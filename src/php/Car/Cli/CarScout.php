<?php

declare(strict_types=1);

namespace Scout\Car\Cli;

use Scout\Adapters\Http\CurlHttpClient;
use Scout\Adapters\Http\HttpClient;
use Scout\Adapters\Http\RobotsResolver;
use Scout\Adapters\Mail\FileMailbox;
use Scout\Adapters\Mail\ImapMailbox;
use Scout\Adapters\SourceError;
use Scout\Config\ConfigError;
use Scout\Core\Heartbeat;
use Scout\Core\Notify\Notification;
use Scout\Core\Notify\NotificationKind;
use Scout\Core\Notify\Notifier;
use Scout\Core\Notify\Priority;
use Scout\Core\Pacer;
use Scout\Core\Redact;
use Scout\Core\SourceStatus;
use Scout\Car\SitemapVehicleSource;
use Scout\Car\VehicleClassifier;
use Scout\Car\VehicleCriteria;
use Scout\Car\VehicleCriteriaLoader;
use Scout\Car\VehicleEmailSource;
use Scout\Car\VehicleFormatter;
use Scout\Car\VehicleOutcome;
use Scout\Car\VehiclePipeline;
use Scout\Car\VehicleScorer;
use Scout\Car\VehicleSource;
use Scout\Car\VehicleSourceDefinition;
use Scout\Car\VehicleSourceLoader;
use Scout\Car\VehicleStore;
use Scout\Cli\WatchLoop;
use Scout\Cli\ChannelFactory;

/**
 * `scout --domain=car …` — the car watcher's verbs, on its own database, config and push topic.
 *
 * Deliberately smaller than the rent CLI: doctor, dump, run (--once / --seed / --watch, --source=),
 * test-notify, help. No digest (no mixed stock), no reclassify (no stored UNKNOWN). What it keeps
 * from the rent CLI it keeps EXACTLY: the Q36 refusal on an empty seen-set (reading the vehicle
 * table), the Q27 heartbeat with its own marker, the Q37 pacer, the injected Notifier and HttpClient
 * seams, and `--source=` force-running a disabled block.
 */
final readonly class CarScout
{
    private const string DEFAULT_DB = 'state/car-watch.sqlite3';
    private const string DEFAULT_FOLDER = 'car-watch/portails';
    /** @var resource */
    private mixed $out;
    /** @var resource */
    private mixed $err;
    private HttpClient $http;
    private RobotsResolver $robotsResolver;

    public function __construct(
        private string $rootDir,
        mixed $out = null,
        mixed $err = null,
        private ?string $nowIso = null,
        ?HttpClient $http = null,
        private ?Notifier $notifier = null,
    ) {
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
        $this->http = $http ?? new CurlHttpClient();
        $this->robotsResolver = new RobotsResolver($this->http);
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $command = $argv[0] ?? 'help';
        $flags = array_slice($argv, 1);

        try {
            return match ($command) {
                'doctor' => $this->doctor($flags),
                'dump', 'replay' => $this->dump($flags),
                'run' => $this->runCommand($flags),
                'test-notify' => $this->testNotify(),
                'help', '--help', '-h' => $this->help(0),
                default => $this->fail('commande inconnue : ' . $command) + $this->help(2) - 2,
            };
        } catch (ConfigError $e) {
            return $this->fail('configuration : ' . $e->getMessage());
        } catch (SourceError $e) {
            return $this->fail('source ' . $e->sourceName . ' : ' . Redact::text($e->getMessage()));
        } catch (\RuntimeException $e) {
            return $this->fail(Redact::text($e->getMessage()) ?? 'erreur');
        }
    }

    // ── verbs ───────────────────────────────────────────────────────────────────────────────────

    /** @param list<string> $flags */
    private function doctor(array $flags): int
    {
        $criteria = $this->criteria();
        $store = $this->store();
        $now = $this->now();
        $this->line('car-watch · base ' . $this->dbPath() . ' · journal ' . $store->journalMode() . ' · schéma véhicules ' . VehicleStore::SCHEMA_VERSION);
        $counts = $store->counts();
        $this->line(sprintf('  seen-set : %d annonce(s), %d notifiée(s), %d correspondance(s)%s', $counts['count'], $counts['notified'], $counts['matches'], $store->isSeenSetEmpty() ? ' — VIDE : `run --once --seed` avant `--watch` (Q36)' : ''));
        $notifier = $this->notifier($criteria);
        $this->line('  canaux   : ' . ($notifier->hasRemoteChannel() ? 'au moins un canal atteint un destinataire' : 'AUCUN canal n\'atteint de destinataire'));
        foreach ($notifier->inventory() as $channel) {
            $this->line(sprintf('             - %-8s %s [%s]', $channel['name'], $channel['describe'], $channel['counts'] ? 'compte comme délivré' : 'NE COMPTE PAS'));
        }
        $this->line(sprintf('  critères : ≤ %s € · zone %s · pic ≤ %d ans / ≤ %s km · carrosseries %s', number_format($criteria->maxPriceEur, 0, ',', ' '), $criteria->postcodePrefixes === [] ? 'nationale' : implode(',', $criteria->postcodePrefixes), $criteria->peakAgeYears, number_format($criteria->peakMileageKm, 0, ',', ' '), implode(' > ', $criteria->bodyRank) ?: '(aucune)'));
        $this->line('');

        $sources = $this->sources($store, $this->onlySources($flags));
        if ($sources === []) {
            $this->line('  aucune source activée.');

            return 0;
        }
        $this->line(sprintf('  %-12s %-13s %6s %8s  %s', 'SOURCE', 'ÉTAT', 'ITEMS', 'DURÉE', 'DÉTAIL'));
        $problems = 0;
        foreach ($sources as $source) {
            $started = microtime(true);
            try {
                $items = count($source->fetch());
                if ($source instanceof SitemapVehicleSource) {
                    $items = $source->lastIndexSize() ?? $items; // the feed's size, as the pipeline records it
                }
                $ms = (int) ((microtime(true) - $started) * 1000);
                $store->runs()->recordRun($source->name(), $items, true, null, $now, $ms, $source instanceof \Scout\Adapters\FeedFreshness ? $source->newestFeedItemAt() : null);
            } catch (\Throwable $e) {
                $ms = (int) ((microtime(true) - $started) * 1000);
                $store->runs()->recordRun($source->name(), 0, false, $e->getMessage(), $now, $ms);
                $this->line(sprintf('  %-12s %-13s %6s %6d ms  %s', $source->name(), 'ERREUR', '-', $ms, Redact::text($e->getMessage())));
                ++$problems;
                continue;
            }
            $health = $source->health($now);
            if ($health->status !== SourceStatus::OK) {
                ++$problems;
            }
            $this->line(sprintf('  %-12s %-13s %6d %6d ms  %s', $source->name(), $health->status->value, $items, $ms, $health->detail));
        }

        return $problems === 0 ? 0 : 1;
    }

    /** @param list<string> $flags */
    private function dump(array $flags): int
    {
        $name = self::firstBare($flags);
        if ($name === null) {
            return $this->fail('usage : scout --domain=car dump <source>');
        }
        $definitions = $this->definitions();
        if (!isset($definitions[$name])) {
            return $this->fail('source inconnue : ' . $name . ' (connues : ' . implode(', ', array_keys($definitions)) . ')');
        }
        $store = $this->store();
        $source = $this->buildSource($definitions[$name], $store);
        $listings = $source->fetch();
        if ($listings === []) {
            $this->line('la source a répondu sans erreur mais n\'a produit aucune annonce.');

            return 1;
        }
        $car = $listings[0];
        $this->line('— annonce brute (' . count($listings) . ' au total) —');
        $this->line((string) json_encode($car->fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->line('');
        $this->line('— après lecture —');
        foreach (['externalId', 'title', 'url', 'make', 'model', 'priceEur', 'year', 'month', 'mileageKm', 'fuel', 'gearbox', 'body', 'sellerType', 'postcode', 'observedAt'] as $field) {
            $v = $car->$field;
            $this->line(sprintf('  %-12s %s', $field, $v === null ? '(null)' : (is_bool($v) ? var_export($v, true) : (string) $v)));
        }
        $now = new \DateTimeImmutable($this->now());
        $verdict = (new VehicleScorer())->judge($car, (new VehicleClassifier())->classify($car), $this->criteria(), (int) $now->format('Y'), (int) $now->format('n'));
        $this->line('');
        $this->line('  verdict      ' . $verdict->outcome->value . ($verdict->score === null ? '' : ' ' . $verdict->score . '/100'));
        foreach ($verdict->reasons as $reason) {
            $this->line('               · ' . $reason);
        }

        return 0;
    }

    /** @param list<string> $flags */
    private function runCommand(array $flags): int
    {
        $verbose = in_array('-v', $flags, true) || in_array('--verbose', $flags, true);
        $seed = in_array('--seed', $flags, true);
        $watch = in_array('--watch', $flags, true);
        if ($watch && $seed) {
            return $this->fail('`--seed` amorce le seen-set en une passe ; combiné à `--watch` il n\'émettrait jamais rien. Lancez `run --once --seed`, puis `--watch`.');
        }
        $criteria = $this->criteria();
        $store = $this->store();

        // Q36, the car analog: an empty VEHICLE seen-set is a fresh file or a missing mount, and
        // the alternative is pushing the whole Autohero catalogue at once.
        if (!$seed && $store->isSeenSetEmpty()) {
            return $this->fail('seen-set véhicules VIDE : `scout --domain=car run --once --seed` d\'abord — sinon tout le catalogue serait notifié d\'un coup (Q36)');
        }

        $notifier = $this->notifier($criteria);
        $sources = $this->sources($store, $this->onlySources($flags));
        if ($sources === []) {
            return $this->fail('aucune source activée');
        }
        $pipeline = new VehiclePipeline($criteria, $store, $notifier);

        if (!$watch) {
            $result = $pipeline->runOnce($sources, $this->now(), $seed);
            $this->report($result, $seed, $verbose);

            return $result->sourcesFailed > 0 && $result->sourcesRun === 0 ? 1 : 0;
        }

        return $this->watch($pipeline, $sources, $store, $notifier, $verbose);
    }

    /** @param list<VehicleSource> $sources */
    private function watch(VehiclePipeline $pipeline, array $sources, VehicleStore $store, Notifier $notifier, bool $verbose): int
    {
        $loop = null;
        $pacer = new Pacer(
            clock: static fn (): float => hrtime(true) / 1_000_000_000.0,
            sleeper: WatchLoop::interruptibleSleeper(static function () use (&$loop): bool {
                return $loop !== null && $loop->isStopping();
            }),
            rand: static fn (int $min, int $max): int => random_int($min, $max),
        );
        $heartbeat = Heartbeat::fromEnv(($raw = getenv('CAR_HEARTBEAT_HOURS')) === false ? null : $raw, 'CAR_HEARTBEAT_HOURS');
        $maxPasses = $this->maxPasses();
        $passes = 0;
        $notified = 0;
        $formatter = new VehicleFormatter();

        $this->line(sprintf('car-watch · surveillance active · %d source(s) · toutes les %d min ± %d (Q37)%s', count($sources), (int) (Pacer::PASS_INTERVAL_SECONDS / 60), (int) (Pacer::JITTER_SECONDS / 60), $maxPasses === null ? '' : ' · SCOUT_MAX_PASSES=' . $maxPasses));

        $beat = function () use (&$passes, &$notified, $sources, $notifier, $formatter): void {
            $health = array_map(fn (VehicleSource $s) => $s->health($this->now()), $sources);
            $n = $formatter->heartbeat($passes, $notified, $health, $this->now());
            if ($notifier->delivered($notifier->send($n))) {
                @file_put_contents($this->stateFile('car-heartbeat.txt'), $this->now());
            }
        };
        if ($heartbeat->isDue($this->lastHeartbeat(), $this->now())) {
            $beat();
        }

        $loop = new WatchLoop(
            pass: function () use ($pipeline, $sources, &$passes, &$notified, $verbose, $heartbeat, $beat): void {
                $result = $pipeline->runOnce($sources, $this->now());
                ++$passes;
                $notified += $result->notified;
                $this->report($result, false, $verbose);
                if ($heartbeat->isDue($this->lastHeartbeat(), $this->now())) {
                    $beat();
                }
            },
            pacer: $pacer,
            onError: function (\Throwable $e): void {
                $this->warn('passe échouée : ' . Redact::text($e->getMessage()));
            },
        );

        return $loop->run($maxPasses);
    }

    private function testNotify(): int
    {
        $notifier = $this->notifier($this->criteria());
        $failures = $notifier->send(new Notification(
            kind: NotificationKind::HEARTBEAT,
            priority: Priority::LOW,
            title: 'car-watch : test de notification',
            reasons: ['si ce message vous parvient, le canal du car-watch est utilisable'],
        ));
        if (!$notifier->hasRemoteChannel()) {
            return $this->fail('aucun canal n\'atteint un destinataire : la console seule ne prouve rien');
        }

        return $notifier->delivered($failures) ? 0 : $this->fail('envoi échoué : ' . implode(' ; ', array_map(static fn ($f) => (string) $f, $failures)));
    }

    private function help(int $code): int
    {
        $this->line('scout --domain=car — veille sur les voitures d\'occasion');
        $this->line('');
        $this->line('  scout --domain=car doctor                 état de chaque source, seen-set, canaux');
        $this->line('  scout --domain=car dump <source>          première annonce brute + lecture + verdict');
        $this->line('  scout --domain=car run --once [-v]        une passe');
        $this->line('  scout --domain=car run --once --seed      amorce le seen-set sans notifier (obligatoire avant --watch)');
        $this->line('  scout --domain=car run --watch [-v]       boucle Q37, battement Q27 (state/car-heartbeat.txt)');
        $this->line('  … --source=<nom>                          limite à une source (répétable ; force une source désactivée)');
        $this->line('  scout --domain=car test-notify            vérifie le canal du car-watch');
        $this->line('');
        $this->line('  config : config/car/criteria.json (+ criteria.local.json), config/car/sources.json');
        $this->line('  env    : CAR_SCOUT_DB, CAR_IMAP_MAILBOX, CAR_NTFY_TOPIC, CAR_HEARTBEAT_HOURS, CAR_FEED_SILENT_DAYS');
        $this->line('           (les identifiants IMAP/SMTP, NTFY_SERVER, IMAP_SINCE_DAYS et IMAP_MAX_MESSAGES sont partagés)');

        return $code;
    }

    // ── wiring ──────────────────────────────────────────────────────────────────────────────────

    private function criteria(): VehicleCriteria
    {
        return VehicleCriteriaLoader::load($this->rootDir . '/config/car/criteria.json', $this->rootDir . '/config/car/criteria.local.json');
    }

    /** @return array<string, VehicleSourceDefinition> */
    private function definitions(): array
    {
        return VehicleSourceLoader::load($this->rootDir . '/config/car/sources.json');
    }

    private function store(): VehicleStore
    {
        return VehicleStore::open($this->dbPath(), $this->feedSilentDays());
    }

    /** `CAR_FEED_SILENT_DAYS` — the car domain's global feed-silence threshold; a source block's own `feed_silent_days` outranks it. */
    private function feedSilentDays(): int
    {
        $raw = getenv('CAR_FEED_SILENT_DAYS');
        if ($raw === false || trim($raw) === '') {
            return 3;
        }
        if (!ctype_digit(trim($raw)) || (int) $raw < 1) {
            throw ConfigError::at('CAR_FEED_SILENT_DAYS', 'doit être un entier de jours ≥ 1 — 0 désactiverait la détection de flux muet, reçu ' . var_export($raw, true));
        }

        return (int) $raw;
    }

    private function dbPath(): string
    {
        $raw = (string) (getenv('CAR_SCOUT_DB') ?: '');
        if ($raw === '') {
            return $this->rootDir . '/' . self::DEFAULT_DB;
        }

        return str_starts_with($raw, '/') || $raw === ':memory:' ? $raw : $this->rootDir . '/' . $raw;
    }

    private function notifier(VehicleCriteria $criteria): Notifier
    {
        if ($this->notifier !== null) {
            return $this->notifier;
        }
        $channels = [];
        foreach ($criteria->notify->channels as $name) {
            $channels[] = ChannelFactory::build($name, $this->out, $this->rootDir, '[car-watch]', 'car-watch@localhost', (string) (getenv('CAR_NTFY_TOPIC') ?: ''), 'CAR_NTFY_TOPIC');
        }

        return new Notifier($channels);
    }

    /**
     * @param ?list<string> $only
     *
     * @return list<VehicleSource>
     */
    private function sources(VehicleStore $store, ?array $only): array
    {
        $out = [];
        foreach ($this->definitions() as $name => $definition) {
            $forced = $only !== null && in_array($name, $only, true);
            if ($only !== null && !$forced) {
                continue;
            }
            if (!$definition->enabled && !$forced) {
                continue;
            }
            if (!$definition->enabled) {
                $this->warn('source ' . $name . ' est `enabled: false` — forcée par --source=');
            }
            $source = $this->buildSource($definition, $store);
            if ($source !== null) {
                $out[] = $source;
            }
        }
        if ($only !== null) {
            foreach ($only as $name) {
                if (!isset($this->definitions()[$name])) {
                    $this->warn('source inconnue ignorée : ' . $name);
                }
            }
        }

        return $out;
    }

    private function buildSource(VehicleSourceDefinition $definition, VehicleStore $store): ?VehicleSource
    {
        $warn = fn (string $m) => $this->warn($m);

        return match ($definition->type) {
            'email_alert' => new VehicleEmailSource($definition, $store, $this->mailbox($definition), $warn, (int) (getenv('IMAP_MAX_MESSAGES') ?: 50)),
            'sitemap_jsonld' => new SitemapVehicleSource($definition, $store, $this->http, $this->robotsResolver->forUrl((string) $definition->url), $warn),
            default => null,
        };
    }

    private function mailbox(VehicleSourceDefinition $definition): \Scout\Adapters\Mail\Mailbox
    {
        $dir = (string) (getenv('MAILBOX_DIR') ?: '');
        if ($dir !== '') {
            return new FileMailbox(str_starts_with($dir, '/') ? $dir : $this->rootDir . '/' . $dir);
        }
        $host = (string) (getenv('IMAP_HOST') ?: '');
        if ($host === '') {
            throw ConfigError::at('.env', 'ni MAILBOX_DIR ni IMAP_HOST ne sont définis — la source ' . $definition->name . ' ne peut lire aucun courrier');
        }
        $since = max(1, (int) (getenv('IMAP_SINCE_DAYS') ?: 7));

        return new ImapMailbox(
            host: $host,
            user: (string) (getenv('IMAP_USER') ?: ''),
            password: (string) (getenv('IMAP_PASSWORD') ?: ''),
            folder: (string) (getenv('CAR_IMAP_MAILBOX') ?: self::DEFAULT_FOLDER),
            port: (int) (getenv('IMAP_PORT') ?: 993),
            fromFilter: $definition->param('from'),
            sinceDays: $since,
            warn: fn (string $m) => $this->warn($m),
        );
    }

    /** @param list<string> $flags */
    private function onlySources(array $flags): ?array
    {
        $only = [];
        foreach ($flags as $flag) {
            if (str_starts_with($flag, '--source=')) {
                $only[] = substr($flag, 9);
            }
        }

        return $only === [] ? null : $only;
    }

    private function report(\Scout\Car\VehicleRunResult $r, bool $seed, bool $verbose): void
    {
        $this->line(sprintf('%d source(s), %d annonce(s) analysées · %d correspondance(s), %d écartée(s), %d baisse(s) de prix, %d notifiée(s)%s', $r->sourcesRun, $r->itemsParsed, $r->matches, $r->rejectedCount, $r->priceDrops, $r->notified, $r->undelivered > 0 ? ', ' . $r->undelivered . ' NON délivrée(s)' : ''));
        if ($seed) {
            $this->line('mode --seed : seen-set amorcé, aucune notification envoyée.');
        }
        foreach ($r->errors as $error) {
            $this->warn($error);
        }
        if ($verbose) {
            foreach ($r->rejected as $line) {
                $this->line('  ' . $line);
            }
        }
    }

    private function maxPasses(): ?int
    {
        $raw = getenv('SCOUT_MAX_PASSES');
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        if (!ctype_digit(trim($raw)) || (int) $raw < 1) {
            throw ConfigError::at('SCOUT_MAX_PASSES', 'doit être un entier ≥ 1, reçu ' . var_export($raw, true));
        }

        return (int) $raw;
    }

    private function lastHeartbeat(): ?string
    {
        $path = $this->stateFile('car-heartbeat.txt');
        if (!is_file($path)) {
            return null;
        }
        $v = @file_get_contents($path);

        return $v === false || trim($v) === '' ? null : trim($v);
    }

    private function stateFile(string $name): string
    {
        return \dirname($this->dbPath()) . '/' . $name;
    }

    private function now(): string
    {
        return $this->nowIso ?? (new \DateTimeImmutable())->format('Y-m-d\TH:i:sP');
    }

    /** @param list<string> $flags */
    private static function firstBare(array $flags): ?string
    {
        foreach ($flags as $f) {
            if (!str_starts_with($f, '-')) {
                return $f;
            }
        }

        return null;
    }

    private function line(string $text): void
    {
        fwrite($this->out, $text . "\n");
    }

    private function warn(string $text): void
    {
        fwrite($this->err, '⚠ ' . $text . "\n");
    }

    private function fail(string $text): int
    {
        fwrite($this->err, $text . "\n");

        return 2;
    }
}
