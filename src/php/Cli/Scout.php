<?php

declare(strict_types=1);

namespace RentWatch\Cli;

use RentWatch\Adapters\FixtureSource;
use RentWatch\Adapters\Source;
use RentWatch\Adapters\SourceError;
use RentWatch\Config\ConfigError;
use RentWatch\Config\ConfigLoader;
use RentWatch\Config\Criteria;
use RentWatch\Config\SourceDefinition;
use RentWatch\Core\Notify\Channel;
use RentWatch\Core\Notify\ConsoleChannel;
use RentWatch\Core\Notify\EmailChannel;
use RentWatch\Core\Notify\Formatter;
use RentWatch\Core\Notify\Notifier;
use RentWatch\Core\Notify\NtfyChannel;
use RentWatch\Core\Notify\Priority;
use RentWatch\Core\TenureClassifier;
use RentWatch\Store\Store;

/**
 * The `scout` command — `spec/PROJECT_BRIEF.md` §10.
 *
 * Exit codes are part of the contract, because this runs unattended under a supervisor:
 * **0** nothing to report · **1** something the developer must act on (a failed source, an
 * undelivered notification) · **2** the tool refused to start (bad config, no usable channel, an
 * unseeded database).
 *
 * The refusals are scoped per Q28 and Q36. A bad `criteria.json` stops everything, because the
 * criteria decide what is filtered and a wrong filter is invisible. One bad source block does not.
 */
final readonly class Scout
{
    /**
     * Typed `mixed` because PHP has no `resource` type declaration and a readonly property must be
     * typed. Streams are ARGUMENTS rather than globals so a test can assert on real stdout — this
     * project's completion gate requires the actual output, not a description of it.
     *
     * @var resource
     */
    private mixed $out;

    /** @var resource */
    private mixed $err;

    /**
     * @param resource|null $out
     * @param resource|null $err
     * @param string|null   $nowIso a FIXED clock for tests. Without one, `STALE` and the counting
     *                              window depend on wall time, and a test that passes at 23:59 and
     *                              fails at 00:01 teaches people to re-run rather than to read.
     */
    public function __construct(
        private string $rootDir,
        mixed $out = null,
        mixed $err = null,
        private ?string $nowIso = null,
    ) {
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
    }

    /** @param list<string> $argv command-line arguments, WITHOUT the program name */
    public function run(array $argv): int
    {
        $command = $argv[0] ?? 'help';
        $flags = array_slice($argv, 1);

        try {
            return match ($command) {
                'doctor' => $this->doctor(),
                'dump' => $this->dump($flags),
                'run' => $this->runCommand($flags),
                'digest' => $this->digest(),
                'reclassify' => $this->reclassify($flags),
                'test-notify' => $this->testNotify(),
                'replay' => $this->dump($flags),
                'help', '--help', '-h' => $this->help(0),
                // `$this->fail(...) ?? $this->help(2)` was the first shape and it was WRONG: `fail()`
                // returns an int, never null, so `??` short-circuited and the usage was never
                // printed. A refusal that does not say what IS valid is a refusal the reader has to
                // guess their way out of.
                default => $this->unknownCommand($command),
            };
        } catch (ConfigError $e) {
            // Caught and printed, never a stack trace. A malformed config is an ordinary,
            // expected, user-caused condition — see ConfigError's own docblock.
            return $this->fail('configuration : ' . $e->getMessage());
        } catch (SourceError $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ── doctor ────────────────────────────────────────────────────────────────────────────────────

    /**
     * Run every enabled source once and report status, timing and item counts — spec §8.
     *
     * Prints the store's **journal mode**, which `CLAUDE.md` § Testing requires: WAL can be silently
     * refused on a network mount, and a store in rollback-journal mode makes two processes contend
     * instead of share. And it passes `$nowIso` to `Store::health()`, without which `STALE` — the
     * verdict that catches the schedule itself having stopped — can never fire at all.
     */
    private function doctor(): int
    {
        $criteria = $this->criteria();
        $store = $this->store();
        $sources = $this->sources($store);
        $now = $this->now();

        $this->line('rent-watch doctor · ' . $now);
        $this->line('  base    : ' . $this->dbPath() . ' (schéma v' . $store->schemaVersion()
            . ', journal ' . $store->journalMode() . ')');

        if ($store->journalMode() !== 'wal' && $this->dbPath() !== ':memory:') {
            $this->line('  ⚠ le mode journal n\'est pas WAL : deux processus se bloqueront au lieu de partager');
        }

        $notifier = $this->notifier($criteria);
        $this->line('  canaux  : ' . ($notifier->hasRemoteChannel() ? 'au moins un canal distant' : 'console seulement'));
        foreach ($notifier->disabledReport() as $name => $problem) {
            $this->line('  ⚠ canal ' . $name . ' désactivé : ' . $problem);
        }
        $this->line('  fuseau  : ' . date_default_timezone_get() . ' · digest à ' . $criteria->notify->digestHour . 'h');
        $this->line('');

        if ($sources === []) {
            $this->line('  aucune source activée. Toutes les sources de config/sources.json sont');
            $this->line('  `enabled: false` tant que leur endpoint n\'a pas été vérifié (règle 1).');

            return 0;
        }

        $problems = 0;
        $this->line(sprintf('  %-16s %-16s %8s %8s  %s', 'SOURCE', 'ÉTAT', 'ITEMS', 'DURÉE', 'DÉTAIL'));

        foreach ($sources as $source) {
            $startedAt = hrtime(true);
            $count = 0;
            $error = null;

            try {
                $count = count($source->fetch());
            } catch (\Throwable $e) {
                $error = $e instanceof SourceError ? $e->getMessage() : (new SourceError($source->name(), $e->getMessage(), $e))->getMessage();
            }

            $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
            $store->recordRun($source->name(), $count, $error === null, $error, $now, $durationMs);

            $health = $source->health($now);
            if ($health->status->isAlerting()) {
                ++$problems;
            }

            $this->line(sprintf(
                '  %-16s %-16s %8d %6d ms  %s',
                $source->name(),
                $health->status->value,
                $count,
                $durationMs,
                $error ?? ($health->detail ?? ''),
            ));
        }

        return $problems > 0 ? 1 : 0;
    }

    // ── dump ──────────────────────────────────────────────────────────────────────────────────────

    /**
     * Print the first raw item of a source, for building a field map.
     *
     * `spec` §10: *"what makes onboarding a new source take five minutes instead of an hour."* It
     * prints the MAPPED listing too, so a field map that silently reads nothing is visible as a row
     * of nulls rather than discovered weeks later as a quiet source.
     *
     * @param list<string> $flags
     */
    private function dump(array $flags): int
    {
        $name = null;
        foreach ($flags as $flag) {
            if (!str_starts_with($flag, '-')) {
                $name = $flag;
                break;
            }
        }

        if ($name === null) {
            return $this->fail('usage : scout dump <source>');
        }

        $store = $this->store();
        $definitions = ConfigLoader::loadSources($this->rootDir . '/config/sources.json');

        if (!isset($definitions[$name])) {
            return $this->fail('source inconnue : ' . $name . ' (connues : ' . implode(', ', array_keys($definitions)) . ')');
        }

        $source = $this->buildSource($definitions[$name], $store);
        if ($source === null) {
            return $this->fail('la source ' . $name . ' est de type `' . $definitions[$name]->type
                . '`, pour lequel aucun adaptateur n\'existe encore');
        }

        $listings = $source->fetch();
        if ($listings === []) {
            $this->line('la source a répondu sans erreur mais n\'a produit aucune annonce.');

            return 1;
        }

        $first = $listings[0];
        $this->line('— annonce brute (' . count($listings) . ' au total) —');
        $this->line((string) json_encode($first->fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->line('');
        $this->line('— après application du field map —');

        foreach ([
            'externalId' => $first->externalId,
            'title' => $first->title,
            'url' => $first->url,
            'commune' => $first->commune,
            'postcode' => $first->postcode,
            'rentCc' => $first->rentCc,
            'rentHc' => $first->rentHc,
            'charges' => $first->charges,
            'effectiveRentCc' => $first->effectiveRentCc(),
            'surfaceM2' => $first->surfaceM2,
            'rooms' => $first->rooms,
            'floor' => $first->floor,
            'hasElevator' => $first->hasElevator,
        ] as $field => $value) {
            $this->line(sprintf('  %-16s %s', $field, $this->show($value)));
        }

        $classification = (new TenureClassifier())->classify($first, $source->profile());
        $this->line('');
        $this->line('  tenure           ' . $classification->tenure->value
            . ' (' . $classification->confidenceBp . '/100, ' . $classification->outcome->value . ')');
        foreach ($classification->reasons() as $reason) {
            $this->line('                   · ' . $reason);
        }

        return 0;
    }

    // ── run ───────────────────────────────────────────────────────────────────────────────────────

    /** @param list<string> $flags */
    private function runCommand(array $flags): int
    {
        $verbose = in_array('-v', $flags, true) || in_array('--verbose', $flags, true);
        $seed = in_array('--seed', $flags, true);

        if (in_array('--watch', $flags, true)) {
            return $this->fail(
                '`--watch` n\'est pas encore implémenté. Il doit respecter la cadence réglée le '
                . '2026-08-07 (Q37) : 15 min ± 5, 5 s entre deux hôtes, 60 s entre deux requêtes '
                . 'au même hôte, ordre des sources mélangé à chaque passe. Utilisez `--once` sous cron '
                . 'en attendant.',
            );
        }

        $criteria = $this->criteria();
        $store = $this->store();

        // Q36. A missing volume mount produces a valid, empty, migrated database indistinguishable
        // from a healthy one — and with nothing batched, every historic listing would push at once.
        if ($store->wasCreated() && !$seed) {
            return $this->fail(
                'base vide : première exécution, ou volume non monté. Rien n\'a été notifié. '
                . 'Relancez avec `--seed` pour amorcer le seen-set sans notifier, puis sans le flag.',
            );
        }

        $notifier = $this->notifier($criteria);
        $fatal = $notifier->fatalProblem();
        if ($fatal !== null && !$seed) {
            return $this->fail($fatal);
        }

        foreach ($notifier->disabledReport() as $name => $problem) {
            // Reported, and the process still starts (Q28). One expired credential must not take
            // down the sources, the seen-set and the price history to punish a single channel.
            $this->warn('canal ' . $name . ' désactivé : ' . $problem);
        }

        $sources = $this->sources($store);
        if ($sources === []) {
            $this->line('aucune source activée — rien à faire.');

            return 0;
        }

        $result = (new Pipeline($criteria, $store, $notifier))->runOnce($sources, $this->now(), $seed);

        $this->line(sprintf(
            '%d source(s), %d annonce(s) analysées · %d correspondance(s), %d à vérifier, %d écartée(s), %d doublon(s)',
            $result->sourcesRun,
            $result->itemsParsed,
            $result->matches,
            $result->digested,
            $result->rejectedCount,
            $result->duplicates,
        ));

        if ($seed) {
            $this->line('mode --seed : seen-set amorcé, aucune notification envoyée.');
        }

        if ($verbose) {
            foreach ($result->rejected as $line) {
                $this->line('  écartée ' . $line);
            }
        }

        foreach ($result->errors as $error) {
            $this->warn($error);
        }

        if ($result->undelivered > 0) {
            $this->warn($result->undelivered . ' notification(s) non délivrée(s) — elles seront réessayées');
        }

        return $result->hasProblems() ? 1 : 0;
    }

    // ── digest / reclassify / test-notify ─────────────────────────────────────────────────────────

    private function digest(): int
    {
        $this->line('`scout digest` émet à la demande le récapitulatif « à vérifier ».');
        $this->line('Il est déjà émis automatiquement à la fin de toute passe qui produit de');
        $this->line('nouvelles entrées (Q34) ; la version à la demande relit la base et attend');
        $this->line('la persistance des verdicts, qui existe depuis le schéma v3 mais dont la');
        $this->line('sélection « depuis la dernière émission » n\'est pas encore écrite.');

        return 0;
    }

    /** @param list<string> $flags */
    private function reclassify(array $flags): int
    {
        $store = $this->store();
        $stale = $store->staleVerdicts();

        $this->line(count($stale) . ' annonce(s) au verdict indéterminé ou antérieur au schéma v3.');
        foreach (array_slice($stale, 0, 20) as $row) {
            $this->line(sprintf('  %-24s %-10s %s', $row['dedup_key'], $row['tenure'] ?? '(aucun)', $row['title']));
        }

        if (count($stale) > 20) {
            // Named rather than silently truncated: a cap the reader cannot see reads as
            // "that is all there is".
            $this->line('  … et ' . (count($stale) - 20) . ' autres (affichage tronqué à 20).');
        }

        $this->line('');
        $this->line('La re-classification effective attend le stockage des champs bruts : le');
        $this->line('classifieur a besoin du texte de l\'annonce, que `listings` ne conserve pas.');
        $this->line('C\'est un schéma v4, pas un oubli — voir docs/OPEN-QUESTIONS.md Q35.');

        unset($flags);

        return 0;
    }

    private function testNotify(): int
    {
        $criteria = $this->criteria();
        $notifier = $this->notifier($criteria);

        $fatal = $notifier->fatalProblem();
        if ($fatal !== null) {
            return $this->fail($fatal);
        }

        foreach ($notifier->disabledReport() as $name => $problem) {
            $this->warn('canal ' . $name . ' désactivé : ' . $problem);
        }

        $failures = $notifier->send(new \RentWatch\Core\Notify\Notification(
            kind: \RentWatch\Core\Notify\NotificationKind::HEARTBEAT,
            priority: Priority::NORMAL,
            title: 'rent-watch : test de notification',
            reasons: ['Si vous lisez ceci, le canal fonctionne.'],
        ));

        foreach ($failures as $failure) {
            $this->warn($failure->getMessage());
        }

        return $notifier->delivered($failures) ? 0 : 1;
    }

    // ── plumbing ──────────────────────────────────────────────────────────────────────────────────

    private function criteria(): Criteria
    {
        return ConfigLoader::loadCriteria(
            $this->rootDir . '/config/criteria.json',
            $this->rootDir . '/config/criteria.local.json',
        );
    }

    private function dbPath(): string
    {
        $configured = getenv('RENT_WATCH_DB');

        return is_string($configured) && trim($configured) !== ''
            ? $configured
            : $this->rootDir . '/state/rent-watch.sqlite3';
    }

    private function store(): Store
    {
        return Store::open($this->dbPath());
    }

    /**
     * Every source that is enabled AND has an adapter.
     *
     * A source of a type with no adapter yet is skipped with a warning rather than crashing the
     * run — but it is NOT silent, because a source that quietly does not run is the exact shape of
     * failure the health subsystem exists to make impossible.
     *
     * @return list<Source>
     */
    private function sources(Store $store): array
    {
        $out = [];

        foreach (ConfigLoader::loadSources($this->rootDir . '/config/sources.json') as $definition) {
            if (!$definition->enabled) {
                continue;
            }

            if ($definition->requiresScrapingOptIn() && !$this->scrapingAllowed()) {
                // Hard rule 4 / Q26. Direct scraping of a private portal refuses to run without an
                // explicit flag, and the flag is never persisted in config — so starting a scrape is
                // a deliberate act each time rather than a boolean somebody flipped once.
                $this->warn('source ' . $definition->name . ' ignorée : le scraping direct d\'un portail privé '
                    . 'exige --i-accept-legal-risk sur cette invocation');

                continue;
            }

            $source = $this->buildSource($definition, $store);
            if ($source === null) {
                $this->warn('source ' . $definition->name . ' ignorée : aucun adaptateur pour le type `'
                    . $definition->type . '`');

                continue;
            }

            $out[] = $source;
        }

        return $out;
    }

    private function buildSource(SourceDefinition $definition, Store $store): ?Source
    {
        return match ($definition->type) {
            'fixture' => new FixtureSource($definition, $store, $this->rootDir),
            // `json`, `html` and `email_alert` have no adapter yet. The first NETWORK adapter is
            // blocked on an INPUT rather than a decision: no endpoint in this repo has been verified
            // and hard rule 1 forbids writing one from memory.
            default => null,
        };
    }

    private function scrapingAllowed(): bool
    {
        return in_array('--i-accept-legal-risk', $_SERVER['argv'] ?? [], true);
    }

    private function notifier(Criteria $criteria): Notifier
    {
        $channels = [];

        foreach ($criteria->notify->channels as $name) {
            $channel = $this->buildChannel($name);
            if ($channel !== null) {
                $channels[] = $channel;
            }
        }

        return new Notifier($channels);
    }

    private function buildChannel(string $name): ?Channel
    {
        $channel = match ($name) {
            'console' => new ConsoleChannel($this->out),
            'ntfy' => new NtfyChannel(
                (string) (getenv('NTFY_TOPIC') ?: ''),
                (string) (getenv('NTFY_SERVER') ?: 'https://ntfy.sh'),
            ),
            'email' => new EmailChannel(
                (string) (getenv('SMTP_TO') ?: ''),
                (string) (getenv('SMTP_FROM') ?: 'rent-watch@localhost'),
            ),
            default => null,
        };

        // An unknown channel name is NOT silently dropped. A typo in `notify.channels` that yielded
        // nothing would be a channel the developer believes is enabled and is not — the "computed
        // and never sent" failure hard rule 2 calls worse than no alert at all.
        //
        // Written as a separate guard rather than a `default => throw` inside the match, so that a
        // sabotage can remove it in one line and see the suite go red. As a multi-line throw-arm it
        // could only be broken into a PHP parse error, which proves nothing about the guarantee.
        if ($channel === null) {
            throw ConfigError::at(
                'criteria.json.notify.channels',
                'canal inconnu : ' . var_export($name, true) . ' (connus : console, ntfy, email)',
            );
        }

        return $channel;
    }

    private function now(): string
    {
        return $this->nowIso ?? (new \DateTimeImmutable())->format('Y-m-d\TH:i:sP');
    }

    private function unknownCommand(string $command): int
    {
        $this->warn('commande inconnue : ' . $command);
        $this->help(2);

        return 2;
    }

    private function help(int $code): int
    {
        foreach ([
            'scout — veille sur les annonces de location en Île-de-France',
            '',
            '  scout doctor                  état, durée et volume de chaque source',
            '  scout dump <source>           première annonce brute + field map appliqué',
            '  scout run --once [-v]         une passe complète',
            '  scout run --seed              amorce le seen-set sans notifier',
            '  scout digest                  récapitulatif « à vérifier »',
            '  scout reclassify              annonces au verdict indéterminé',
            '  scout test-notify             vérifie les canaux de notification',
            '',
            '  --i-accept-legal-risk         requis pour toute source `legal_risk` (règle 4)',
        ] as $line) {
            $this->line($line);
        }

        return $code;
    }

    private function show(mixed $value): string
    {
        return match (true) {
            $value === null => '(null)',
            $value === true => 'true',
            $value === false => 'false',
            default => (string) $value,
        };
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
        $this->warn($text);

        return 2;
    }
}
