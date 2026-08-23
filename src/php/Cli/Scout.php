<?php

declare(strict_types=1);

namespace RentWatch\Cli;

use RentWatch\Adapters\EmailAlertSource;
use RentWatch\Adapters\FixtureSource;
use RentWatch\Adapters\Http\CurlHttpClient;
use RentWatch\Adapters\Http\HttpClient;
use RentWatch\Adapters\Http\Robots;
use RentWatch\Adapters\Http\RobotsResolver;
use RentWatch\Adapters\HtmlSource;
use RentWatch\Adapters\HttpJsonSource;
use RentWatch\Adapters\Mail\FileMailbox;
use RentWatch\Adapters\Mail\ImapMailbox;
use RentWatch\Adapters\Mail\Mailbox;
use RentWatch\Adapters\PacedSource;
use RentWatch\Adapters\Source;
use RentWatch\Adapters\SourceError;
use RentWatch\Config\ConfigError;
use RentWatch\Config\ConfigLoader;
use RentWatch\Config\Criteria;
use RentWatch\Config\SourceDefinition;
use RentWatch\Core\CriteriaEngine;
use RentWatch\Core\Heartbeat;
use RentWatch\Core\ListingSnapshot;
use RentWatch\Core\Notify\Channel;
use RentWatch\Core\Notify\ConsoleChannel;
use RentWatch\Core\Notify\EmailChannel;
use RentWatch\Core\Notify\FileTransport;
use RentWatch\Core\Notify\Formatter;
use RentWatch\Core\Notify\MailTransport;
use RentWatch\Core\Notify\Notifier;
use RentWatch\Core\Notify\NtfyChannel;
use RentWatch\Core\Notify\Priority;
use RentWatch\Core\Notify\SendmailTransport;
use RentWatch\Core\Notify\SmtpTransport;
use RentWatch\Core\Pacer;
use RentWatch\Core\RawListing;
use RentWatch\Core\Redact;
use RentWatch\Core\SourceStatus;
use RentWatch\Core\TenureClassifier;
use RentWatch\Core\Verdict;
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
     * The one HTTP client this process uses, shared by every adapter AND by the robots resolver.
     *
     * Sharing is not an optimisation: {@see CurlHttpClient} pins the honest User-Agent and refuses
     * a caller-supplied override, so a robots request made through the same client is guaranteed to
     * identify as the same tool that polls the page. A second client built somewhere else would be
     * one edit away from asking permission as somebody different.
     */
    private HttpClient $http;

    /**
     * Turns an HTTP answer for `/robots.txt` into a verdict. Stateless — the once-per-host cache is
     * a local in {@see sources()}, so that its lifetime is one build of the source list and this
     * class stays `readonly` without taking a {@see \RentWatch\Core\MutableByDesign} exemption it
     * does not qualify for.
     */
    private RobotsResolver $robotsResolver;

    /**
     * @param resource|null $out
     * @param resource|null $err
     * @param string|null   $nowIso a FIXED clock for tests. Without one, `STALE` and the counting
     *                              window depend on wall time, and a test that passes at 23:59 and
     *                              fails at 00:01 teaches people to re-run rather than to read.
     * @param ?HttpClient   $http   a TEST SEAM, and the reason hard rule 5 is enforceable at all.
     *                              Until it existed the two network adapters were built with
     *                              `new CurlHttpClient()` inline, so nothing could observe what the
     *                              CLI requested — and what it requested, on every real poll, did
     *                              not include `robots.txt`. See
     *                              {@see \RentWatch\Tests\Cli\ScoutRobotsTest}.
     */
    public function __construct(
        private string $rootDir,
        mixed $out = null,
        mixed $err = null,
        private ?string $nowIso = null,
        ?HttpClient $http = null,
    ) {
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
        // Built eagerly because the class is readonly and cannot memoise later. Neither
        // constructor touches the network — the resolver fetches on first `forUrl()` — so a
        // command that never polls (`dump`, `digest`, `reclassify`) still makes no request.
        $this->http = $http ?? new CurlHttpClient();
        $this->robotsResolver = new RobotsResolver($this->http);
    }

    /** @param list<string> $argv command-line arguments, WITHOUT the program name */
    public function run(array $argv): int
    {
        $command = $argv[0] ?? 'help';
        $flags = array_slice($argv, 1);

        try {
            return match ($command) {
                'doctor' => $this->doctor($flags),
                'dump' => $this->dump($flags),
                'run' => $this->runCommand($flags),
                'digest' => $this->digest($flags),
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
            //
            // RECORDED when it refused `run`, because this is the commonest startup refusal there
            // is and Q27's note exists for exactly it: under Docker the process exits before any
            // channel is used and its stderr scrolls past in a log nobody reads, so without the note
            // a container that has been refusing to start since Tuesday is indistinguishable from a
            // quiet market. A ConfigError message quotes the offending VALUE, which is why
            // `recordRefusal()` redacts before writing.
            $text = 'configuration : ' . $e->getMessage();

            return $command === 'run' ? $this->failRun($text) : $this->fail($text);
        } catch (SourceError $e) {
            return $command === 'run' ? $this->failRun($e->getMessage()) : $this->fail($e->getMessage());
        } catch (\PDOException $e) {
            // The store could not be opened, and under Q8's deployment that is the LIKELIEST failure
            // in production rather than an exotic one: `state/` is bind-mounted from the host, the
            // image runs as a non-root uid, and a host directory owned by somebody else is simply
            // not writable. Measured by running the real container, where it produced a fatal
            // PDOException and a stack trace.
            //
            // Named path, named cause, no stack trace — the same treatment `ConfigError` already
            // gets for being "an ordinary, expected, user-caused condition". Redacted because a DSN
            // can carry credentials, even though SQLite's does not today.
            $text = 'base de données inutilisable (' . $this->dbPath() . ') : ' . Redact::text($e->getMessage())
                . '. Le volume est-il monté et accessible en écriture par l\'utilisateur du conteneur ?';

            return $command === 'run' ? $this->failRun($text) : $this->fail($text);
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
    private function doctor(array $flags = []): int
    {
        $criteria = $this->criteria();
        $store = $this->store();
        $sources = $this->sources($store, $this->onlySources($flags), $criteria);
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
        // The digest cadence is stated as what RUNS, not as what is configured. This line used to
        // read `digest à 8h`, which promised a daily emission nothing schedules: `digest_hour` is
        // parsed, printed here, and read by no other code. Telling an operator a rollup fires every
        // morning when none does is the "computed and never sent" shape hard rule 2 names, arriving
        // through the diagnostic that exists to prevent it.
        $this->line('  fuseau  : ' . date_default_timezone_get()
            . ' · digest : à la fin de toute passe produisant du nouveau, et sur demande (`scout digest`)');
        $this->line('  note    : `digest_hour` (' . $criteria->notify->digestHour . 'h) est configuré mais AUCUN planificateur ne le lit — voir Q34');
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

            // Hard rule 2, for the one failure nothing else in this table can show. A detail page
            // that stops parsing does not change the source's COUNT and does not fail its run, so
            // `ok / 168 annonces` stays true and correct while every listing quietly loses its
            // title — the broken-selector-forever shape, one layer down. It is reported as a count
            // rather than as a status because one dead page is noise: what means something is the
            // proportion, and the operator is the one who can see it against the item count.
            $detailFailures = $store->detailFailureCount($source->name(), HtmlSource::DETAIL_ATTEMPT_CAP);
            $note = $error ?? ($health->detail ?? '');

            if ($detailFailures > 0) {
                $note = rtrim($note . ' · ' . $detailFailures . ' page(s) de détail illisible(s), abandonnée(s)', ' ·');
            }

            $this->line(sprintf(
                '  %-16s %-16s %8d %6d ms  %s',
                $source->name(),
                $health->status->value,
                $count,
                $durationMs,
                $note,
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

        // `dump` builds the gate too: a dump that hydrated nothing would show a different listing
        // than a run does, which makes it useless for the one job it has — seeing what the pipeline
        // will see.
        $source = $this->buildSource($definitions[$name], $store, $this->criteria());
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

        $watch = in_array('--watch', $flags, true);

        if ($watch && $seed) {
            // `--seed` marks everything already seen WITHOUT notifying, to bootstrap the seen-set.
            // Repeating that every fifteen minutes would suppress every notification forever while
            // the process looked perfectly healthy — a watcher that watches and never speaks.
            return $this->failRun(
                '`--seed` amorce le seen-set en une passe ; combiné à `--watch` il n\'émettrait '
                . 'jamais aucune notification. Lancez `scout run --once --seed`, puis `--watch`.',
            );
        }

        $criteria = $this->criteria();
        $store = $this->store();

        // Q36. A missing volume mount produces a valid, empty, migrated database indistinguishable
        // from a healthy one — and with nothing batched, every historic listing would push at once.
        //
        // The question asked is whether anything has ever been RECORDED, not whether this process
        // created the file: `scout doctor` opens the database, and while the guard read the latter,
        // running doctor once — the first thing a new machine invites you to do — let the next run
        // notify the entire back catalogue.
        if ($store->isSeenSetEmpty() && !$seed) {
            return $this->failRun(
                'base vide : première exécution, ou volume non monté. Rien n\'a été notifié. '
                . 'Relancez avec `--seed` pour amorcer le seen-set sans notifier, puis sans le flag.',
            );
        }

        $notifier = $this->notifier($criteria);
        $fatal = $notifier->fatalProblem();
        if ($fatal !== null && !$seed) {
            return $this->failRun($fatal);
        }

        foreach ($notifier->disabledReport() as $name => $problem) {
            // Reported, and the process still starts (Q28). One expired credential must not take
            // down the sources, the seen-set and the price history to punish a single channel.
            $this->warn('canal ' . $name . ' désactivé : ' . $problem);
        }

        $sources = $this->sources($store, $this->onlySources($flags), $criteria);
        if ($sources === []) {
            $this->line('aucune source activée — rien à faire.');

            return 0;
        }

        if ($watch) {
            return $this->watch($criteria, $store, $notifier, $sources, $verbose);
        }

        return $this->onePass($criteria, $store, $notifier, $sources, $seed, $verbose);
    }

    /**
     * The Q37 watch loop.
     *
     * Three things are re-done on EVERY pass, and each was a way to get this wrong:
     *
     * - `PacedSource::wrapAll()` is called per pass, not once, because Q37 requires the order to be
     *   shuffled *each* pass. Wrapping once would fix one random order for the lifetime of the
     *   process — which for a service running for weeks is simply a fixed order, and being polled
     *   first every fifteen minutes is itself a fingerprint.
     * - `$this->now()` is re-read per pass. Hoisting it would stamp every run in the log with the
     *   moment the process started, and `Store::health()` derives `STALE` from those timestamps —
     *   so the one verdict that catches "the schedule has stopped" would be computed against a clock
     *   that never moves. That is the failure mode of a monitor monitoring itself with a dead clock.
     * - The pacer is built ONCE and shared, because its whole job is remembering across passes.
     *
     * @param list<Source> $sources
     */
    private function watch(
        Criteria $criteria,
        Store $store,
        Notifier $notifier,
        array $sources,
        bool $verbose,
    ): int {
        $loop = null;
        $pacer = new Pacer(
            clock: static fn (): float => hrtime(true) / 1_000_000_000.0,
            sleeper: WatchLoop::interruptibleSleeper(
                static function () use (&$loop): bool {
                    return $loop !== null && $loop->isStopping();
                },
            ),
            rand: static fn (int $min, int $max): int => random_int($min, $max),
        );

        // Q27. Built BEFORE the loop so an unusable HEARTBEAT_HOURS refuses at startup rather than
        // on the first beat — which, on the default, would be a day into an unattended run.
        //
        // Converted to a REFUSAL rather than allowed to propagate: a bad env value is an ordinary,
        // expected, user-caused condition, and `ConfigError`'s docblock already rules that those are
        // caught and printed, never shown as a stack trace. It goes through `failRun()` so the next
        // successful start reports it, which is the case Q27's refusal note exists for — an operator
        // who typo'd this in a compose file would otherwise see nothing at all.
        try {
            $heartbeat = Heartbeat::fromEnv(($raw = getenv('HEARTBEAT_HOURS')) === false ? null : $raw);
        } catch (\InvalidArgumentException $e) {
            return $this->failRun($e->getMessage());
        }
        $passes = 0;

        // What this run actually polls, which is NOT the same as what the config enables whenever
        // `--source` is in play. Taken from the built list rather than re-read from the config,
        // because the built list is the thing the loop will iterate: any future reason a source is
        // dropped between config and loop is then reflected here for free, rather than silently
        // reintroducing the mismatch this replaced.
        $watched = array_map(static fn (Source $source): string => $source->name(), $sources);

        // Read and CLEARED once, here. Q27: "the next successful start can report what happened
        // while it was down." Clearing it at startup rather than after sending means a refusal is
        // reported once, not on every beat for the life of the process.
        $refusal = $this->takeLastRefusal();

        if ($heartbeat->isDue($this->lastHeartbeat(), $this->now())) {
            $this->beat($notifier, $store, $passes, 0, $refusal, $watched);
        }

        $loop = new WatchLoop(
            pass: function () use ($criteria, $store, $notifier, $sources, $verbose, $pacer, $heartbeat, $watched, &$passes): void {
                // `finally`, and it is the whole point rather than a style choice. This claimed to
                // be "outside its try/catch by construction" and was not: the heartbeat sat in the
                // same closure as the pass, and `WatchLoop` wraps that closure in its own `try` — so
                // any throw from `onePass` skipped both `++$passes` AND the beat. A review panel
                // found it on 2026-08-24, next to a pass-aborting defect that made it reachable: a
                // watcher losing every pass to one bad listing would also have gone silent, which is
                // precisely the state the beat exists to distinguish from a quiet market.
                //
                // The common case it was right about still holds — all sources 503ing is caught per
                // source INSIDE `Pipeline` and never reaches here — which is exactly why nobody
                // re-checked the mechanism.
                try {
                    $this->onePass($criteria, $store, $notifier, PacedSource::wrapAll($sources, $pacer), false, $verbose);
                    ++$passes;
                } finally {
                    // A heartbeat is the one message that must still go out when passes are failing,
                    // since a watcher whose sources are all broken is exactly the state silence
                    // would hide. Its own failure must not mask the pass's: a liveness signal that
                    // can replace the diagnosis is worse than one that is late.
                    try {
                        if ($heartbeat->isDue($this->lastHeartbeat(), $this->now())) {
                            $this->beat($notifier, $store, $passes, 0, null, $watched);
                        }
                    } catch (\Throwable $beatFailure) {
                        $this->warn('battement de cœur non émis : ' . Redact::text($beatFailure->getMessage()));
                    }
                }
            },
            pacer: $pacer,
            onError: function (\Throwable $e): void {
                // Reported, never hidden — hard rule 3 at the loop level. The loop survives so the
                // next pass can succeed; saying nothing would make a watcher that has stopped
                // fetching indistinguishable from one watching a quiet market.
                $this->warn('passe en échec : ' . Redact::text($e->getMessage()));
            },
        );

        $handlers = $loop->installSignalHandlers();

        $maxPasses = $this->maxPasses();

        $this->line(sprintf(
            'surveillance active · %d source(s) · toutes les %d min ± %d (Q37) · %s%s',
            count($sources),
            (int) (Pacer::PASS_INTERVAL_SECONDS / 60),
            (int) (Pacer::JITTER_SECONDS / 60),
            // Said out loud rather than assumed: without ext-pcntl a SIGTERM kills the process
            // wherever it happens to be, and the operator should not learn that from a duplicate
            // notification storm after the first `docker stop`.
            $handlers
                ? 'arrêt propre sur SIGINT/SIGTERM (la passe en cours se termine)'
                : 'ext-pcntl absent : pas d\'arrêt propre, la passe en cours sera interrompue',
            // Said out loud, every pass, because a watcher that stops after N passes looks exactly
            // like one that is still watching — right up until the listing it missed.
            $maxPasses === null ? '' : sprintf(' · RENT_WATCH_MAX_PASSES=%d : arrêt après %d passe(s)', $maxPasses, $maxPasses),
        ));

        // Said out loud so the operator knows what silence will mean. An interval nobody was told
        // about cannot be used to judge whether the watcher has gone quiet.
        $this->line(sprintf('battement de cœur toutes les %d h (Q27) — le silence au-delà est un signal', $heartbeat->intervalHours));

        return $loop->run($maxPasses);
    }

    /**
     * One complete pass. Shared by `--once` and by every iteration of `--watch`.
     *
     * @param list<Source> $sources
     */
    private function onePass(
        Criteria $criteria,
        Store $store,
        Notifier $notifier,
        array $sources,
        bool $seed,
        bool $verbose,
    ): int {
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

        if ($result->unencodable > 0) {
            // Named on the pass that causes it. These rows are judged and digested normally, but no
            // snapshot could be taken, so `scout reclassify` will skip them for ever — a fact worth
            // one line now rather than a mystery in a skip counter later.
            // "charge utile", not "texte illisible". `ListingSnapshot::encode()` refuses three
            // distinct things — malformed UTF-8 anywhere in the listing (including a STRUCTURED
            // FIELD whose title and description are perfectly clean), a nesting depth over 512, and
            // Inf/NaN — and only the first is unreadable prose. A review panel produced a NOTIFIED
            // MATCH that silently lost its snapshot while the operator was told the listing was
            // illisible. Substituting U+FFFD instead of refusing is not the answer and was checked:
            // it would delete an excluded label out of the middle of `conventionné`.
            $this->warn($result->unencodable . ' annonce(s) à la charge utile non encodable — verdict enregistré sans instantané, non re-jugeable');
        }

        if ($result->undelivered > 0) {
            $this->warn($result->undelivered . ' notification(s) non délivrée(s) — elles seront réessayées');
        }

        return $result->hasProblems() ? 1 : 0;
    }

    // ── digest / reclassify / test-notify ─────────────────────────────────────────────────────────

    /**
     * Emit the "à vérifier" digest on demand (Q34's other half).
     *
     * **It reads the STORE, not the last pass, and that is the whole reason it exists.** The
     * pipeline already emits every new digest entry at the end of any pass that produces one, marks
     * them only after the channel confirms, and re-offers an undelivered batch next run. What that
     * retry cannot reach is a listing that stops being published in between: it is no longer in any
     * pass's results, so nothing re-offers it, and a digest entry — unlike a match — has no second
     * chance from anywhere else. Selecting on `outcome = 'DIGEST' AND notified_at IS NULL` asks the
     * question the pipeline cannot: what has been judged doubtful and never told to anyone.
     *
     * **A row with no snapshot is announced, never skipped.** It announces such a row from
     * `listings`' own columns — stored facts, not invented ones — and says how many were degraded
     * that way.
     *
     * THE REASON FIRST GIVEN FOR THAT RULE WAS WRONG, and the correction is worth more than the
     * rule. It said: every row in the standing backlog predates schema v7, so skipping
     * evidence-less rows would skip exactly what this command was ruled to rescue. A review panel
     * ran a real v4 and a real v6 database through the migration and showed the premise is false in
     * the other direction — `outcome` is a v7 column too, and is not backfilled either, so a pre-v7
     * row has `outcome = NULL` and this query never returns it at all. The pre-v7 backlog is not
     * skipped for lack of a snapshot; it is never selected. A true rule with an invented cause is
     * worse than a wrong one, because nobody re-checks it.
     *
     * That backlog is deliberately NOT reachable, and widening the query is the wrong fix: a pre-v7
     * row with `tenure = 'UNKNOWN'` might have been digested, or might have been REJECTED by a hard
     * disqualifier before the tenure branch was ever reached, and nothing stored tells them apart.
     * Selecting on tenure would put rejected listings — wrong commune, wrong size — into the one
     * channel §1 uses as its landing zone, wearing the clothes of a doubtful match. A pre-v7 row
     * that is still PUBLISHED gets its outcome from the next ordinary pass; one already delisted
     * stays unreachable, and that is a stated cost rather than a hidden one.
     *
     * The evidence-less path is still live, and since 2026-08-24 it is reachable in production for
     * a reason that has nothing to do with v7: a listing whose own text is not valid UTF-8 cannot
     * be snapshotted, so `Store::recordVerdict()` stores its verdict without one. That listing is
     * judged, digested, and carries a title — exactly the row this branch announces.
     *
     * That is deliberately NOT the rule {@see reclassify()} follows. Reclassify FORMS a verdict, so
     * running it on less evidence than the original saw is the §1 breach schema v7 exists to
     * prevent. This command only ANNOUNCES a verdict already formed and already stored; announcing
     * a stored `DIGEST` from a stored title cannot promote anything into a match.
     *
     * @param list<string> $flags
     */
    private function digest(array $flags): int
    {
        $dryRun = in_array('--dry-run', $flags, true);
        foreach ($flags as $flag) {
            if ($flag !== '--dry-run') {
                return $this->fail('option inconnue : ' . $flag . ' (connue : --dry-run)');
            }
        }

        $store = $this->store();
        $rows = $store->pendingDigest();

        if ($rows === []) {
            $this->line('Aucune annonce en attente dans le récapitulatif « à vérifier ».');

            return 0;
        }

        $entries = [];
        $withoutSnapshot = 0;
        $unreadable = 0;

        foreach ($rows as $row) {
            $listing = null;
            $json = $row['evidence_json'];

            if (is_string($json) && $json !== '') {
                try {
                    $listing = ListingSnapshot::decode($json);
                } catch (\JsonException | \InvalidArgumentException $e) {
                    // Per-row, which is why the store hands these back RAW. Decoding the batch in
                    // one query would let the first damaged row take every readable entry with it —
                    // one bad row costing the whole digest, the opposite of degrading it and saying
                    // so. Redacted: a snapshot quotes the listing payload back, and an adapter
                    // error message is a secrets channel.
                    ++$unreadable;
                    $this->warn(sprintf(
                        'instantané illisible pour %s — annonce dégradée : %s',
                        $row['dedup_key'],
                        Redact::text($e->getMessage()),
                    ));
                }
            } else {
                ++$withoutSnapshot;
            }

            $listing ??= new RawListing(
                sourceName: $row['source'],
                externalId: $row['external_id'],
                title: $row['title'],
                url: $row['url'],
                rentCc: $row['rent_cc'],
            );

            /** @var list<string> $reasons */
            $reasons = $this->decodeSignals($row['signals_json']);

            $entries[] = [
                'listing' => $listing,
                'verdict' => Verdict::digest($reasons),
                'key' => $row['dedup_key'],
                'keys' => [$row['dedup_key']],
            ];
        }

        $notification = (new Formatter())->digest($entries);

        $this->line($notification->title);
        foreach ($notification->reasons as $reason) {
            $this->line($reason);
        }

        if ($withoutSnapshot > 0) {
            // Counted out loud. A backlog announced without its full detail otherwise reads as a
            // set of sources that publish nothing but titles.
            // THE CAUSE IT NAMES MUST BE ONE THAT CAN HAPPEN. This said "antérieures au schéma v7"
            // and a review panel showed a row created seconds ago at v7 being reported that way —
            // `pendingDigest()` filters on `outcome`, itself a v7 column, so a pre-v7 row is never
            // returned here at all. The only reachable cause is a listing whose own payload could
            // not be JSON-encoded. Pointing an operator at a migration hides an encoding fault in a
            // live source, which is the one thing this line could have surfaced.
            $this->line(sprintf(
                '%d annonce(s) sans instantané (charge utile non encodable) — annoncées depuis les colonnes conservées.',
                $withoutSnapshot,
            ));
        }
        if ($unreadable > 0) {
            $this->line($unreadable . ' instantané(s) illisible(s) — voir les avertissements ci-dessus.');
        }

        if ($dryRun) {
            $this->line('--dry-run : rien n\'a été envoyé, rien n\'a été marqué comme émis.');

            return 0;
        }

        $notifier = $this->notifier($this->criteria());

        $fatal = $notifier->fatalProblem();
        if ($fatal !== null) {
            return $this->fail($fatal);
        }

        foreach ($notifier->disabledReport() as $name => $problem) {
            $this->warn('canal ' . $name . ' désactivé : ' . $problem);
        }

        $failures = $notifier->send($notification);
        foreach ($failures as $failure) {
            $this->warn($failure->getMessage());
        }

        if (!$notifier->delivered($failures)) {
            // Nothing marked. Marking first would consume the batch permanently on a failed send,
            // and these entries have no other route to the developer — the same asymmetry the
            // pipeline's digest branch is built on.
            $this->warn('récapitulatif non délivré — rien n\'a été marqué comme émis, il sera réessayé.');

            return 1;
        }

        $now = $this->nowIso ?? date('c');
        foreach ($entries as $entry) {
            $store->markNotified($entry['key'], $now);
        }

        $this->line(count($entries) . ' annonce(s) émise(s).');

        return 0;
    }

    /**
     * The stored `reasons[]`, or an empty list when the column holds something that is not one.
     *
     * Tolerant on purpose, and it is the one place in this command that is: these are explanatory
     * strings shown next to an entry, so a damaged `signals_json` costs a sentence. The LISTING is
     * what must always survive intact, and it is decoded separately and counted.
     *
     * @return list<string>
     */
    private function decodeSignals(?string $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $reasons = [];
        foreach ($decoded as $reason) {
            if (is_string($reason)) {
                $reasons[] = $reason;
            }
        }

        return $reasons;
    }

    /** @param list<string> $flags */
    /**
     * Re-run the classifier and the criteria engine over stored verdicts (Q35).
     *
     * The problem is a PERMANENT silent miss. The seen-set guarantees a listing is new exactly
     * once, so a listing digested as `UNKNOWN` under a classifier that is later improved is never
     * surfaced again by anything at all — and Q18 (PLI) and Q21 (a shouted `PLUS`) both route there
     * deliberately, so the bin is not small.
     *
     * **`reclassify runs on evidence ⊇ original, never ⊂`**, and that is a §1 rule rather than a
     * quality preference. A card whose structured field says `PLS` while its title says *logement
     * intermédiaire* classifies `UNKNOWN` today BY CONFLICT; re-run on the title alone it becomes a
     * MATCH. So re-judging a row whose evidence has shrunk does not make a smaller improvement — it
     * manufactures the one outcome §1 forbids, preferentially on the listings most likely to be
     * social, because those are the ones whose evidence conflicts. A row with no stored snapshot is
     * therefore SKIPPED and counted out loud, never degraded to whatever text is lying around.
     *
     * **It runs on the schema-v7 snapshot ALONE, and does not merge the detail cache on top.** That
     * refines the mechanic sketched when v7 was planned, and the reason is in the pipeline: every
     * pass rewrites the snapshot with the member exactly as the classifier consumed it, AFTER any
     * detail merge. So a detail page fetched in pass N is already inside the snapshot written in
     * pass N, and re-mapping `listing_detail.fields_json` here would buy no evidence while making a
     * stored verdict depend on `ListingMapper` code and a `detail_map` that have since changed —
     * precisely the drift the snapshot column exists to escape.
     *
     * It re-JUDGES rather than merely re-classifying, because Q35's promotion test is on `Outcome`
     * and only the criteria engine produces one. That means TODAY's criteria: a row whose tenure now
     * resolves cleanly can still fail a ceiling lowered since it was stored, and records `REJECT`.
     *
     * @param list<string> $flags
     */
    private function reclassify(array $flags): int
    {
        $dryRun = false;

        foreach ($flags as $flag) {
            if ($flag === '--dry-run') {
                $dryRun = true;
                continue;
            }

            if ($flag === '--since' || str_starts_with($flag, '--since=')) {
                // REFUSED, not answered with something else. Q35's staleness mechanism is a stored
                // classifier version, and there is no such column — answering `--since` against
                // `last_seen_at` would substitute a different mechanism for the ruled one while
                // looking like it. Re-running the whole bin costs seconds, so nothing is lost.
                return $this->fail(
                    '--since n\'est pas implémenté : il suppose une version de classifieur stockée '
                    . 'avec le verdict, colonne qui n\'existe pas. Relancer sans option revoit tout le lot.',
                );
            }

            return $this->fail('option inconnue : ' . $flag . ' (connues : --dry-run)');
        }

        $store = $this->store();
        $criteria = $this->criteria();
        $rows = $store->staleVerdicts();

        if ($rows === []) {
            $this->line('Aucun verdict indéterminé à revoir.');

            return 0;
        }

        $this->line(count($rows) . ' annonce(s) au verdict indéterminé ou antérieur au schéma v3.');

        $profiles = [];
        foreach (ConfigLoader::loadSources($this->rootDir . '/config/sources.json') as $definition) {
            $profiles[$definition->name] = $definition->profile();
        }

        $classifier = new TenureClassifier();
        $engine = new CriteriaEngine($criteria);

        // THE NOTIFIER IS BUILT BEFORE ANY ROW IS TOUCHED, and that ordering is the fix to a defect
        // a review panel proved on 2026-08-24. It used to be constructed after the loop: a deploy
        // whose `NTFY_TOPIC` was not yet filled in ran the whole re-judge, rewrote every verdict,
        // and only then hit `fatalProblem()` — consuming the entire promotable backlog in one run
        // while printing a message about an environment variable. Refuse before the work, as
        // `run`, `digest` and `test-notify` all do.
        $notifier = null;
        if (!$dryRun) {
            $notifier = $this->notifier($criteria);

            $fatal = $notifier->fatalProblem();
            if ($fatal !== null) {
                return $this->fail($fatal);
            }

            foreach ($notifier->disabledReport() as $name => $problem) {
                $this->warn('canal ' . $name . ' désactivé : ' . $problem);
            }
        }

        $skipped = 0;
        $unreadable = 0;
        $rejudged = 0;
        $changed = 0;
        /** @var list<array{listing: RawListing, verdict: Verdict, key: string, classification: \RentWatch\Core\Classification}> $promotions */
        $promotions = [];

        foreach ($rows as $row) {
            $key = $row['dedup_key'];

            try {
                $evidence = $store->evidence($key);
            } catch (\JsonException | \InvalidArgumentException $e) {
                // Per row. `Store::evidence()` throws on a damaged snapshot by design — degrading it
                // to `null` would make data loss indistinguishable from a row that never had one —
                // but letting that throw escape would void the whole run, which is the blast-radius
                // mistake detail hydration already made once. Loud AND scoped. Redacted, because a
                // snapshot quotes the listing payload back.
                ++$unreadable;
                $this->warn('instantané illisible pour ' . $key . ' — verdict inchangé : ' . Redact::text($e->getMessage()));
                continue;
            }

            if ($evidence === null) {
                // THE §1 RULE. Not backfilled, so there is nothing honest to judge on.
                ++$skipped;
                continue;
            }

            $profile = $profiles[$evidence->sourceName] ?? new \RentWatch\Core\SourceProfile(
                // A source removed from `sources.json` leaves its listings behind. Fail CLOSED:
                // `mixedTenure: true` with no default digests a listing with no signal instead of
                // matching it, which is the direction §1 requires when we do not know what we are
                // looking at.
                $evidence->sourceName,
                'institutional',
                null,
                true,
            );

            $classification = $classifier->classify($evidence, $profile);
            $before = $store->outcome($key);

            if ($before === null) {
                // Every dedup MEMBER is classified; only the SURVIVOR is judged. `NULL` is exactly
                // what distinguishes "never judged" from "judged and rejected", and manufacturing an
                // outcome here would destroy that distinction for a row the engine never saw.
                if (!$dryRun) {
                    $this->writeVerdict($store, $key, $classification, $evidence);
                }

                continue;
            }

            $verdict = $engine->judge($evidence, $classification, $this->ageSeconds($store, $key));
            $after = $verdict->outcome->value;
            ++$rejudged;

            if ($after === 'MATCH' && $before !== 'MATCH') {
                // A PROMOTION, AND NOTHING IS WRITTEN YET. This row is left exactly as it was until
                // the channel confirms, and that deferral is the whole point rather than an
                // optimisation: writing the verdict here removes the row from `staleVerdicts()`
                // (its tenure resolves) AND from `pendingDigest()` (its outcome is no longer
                // DIGEST), so a failed send left a MATCH nobody was told about that NO command
                // could reach again — while the warning below promised a retry. There is no third
                // selector: `grep "notified_at IS NULL"` finds those two and nothing else.
                //
                // The population is exactly the one that cannot be rescued by the pipeline either:
                // a still-published listing is re-judged next pass, but these commands exist for
                // the listing that has since been delisted.
                //
                // Widened from `DIGEST -> MATCH` for the same reason. REJECT -> MATCH is not a
                // demotion, it is reachable the moment the criteria widen — Q1-Q3 widened three
                // filters in one day — and `docs/OPEN-QUESTIONS.md` already rules that a listing
                // which was disqualified and now qualifies IS a new match. Recording it silently
                // stranded it in the same unreachable state.
                $promotions[] = [
                    'listing' => $evidence,
                    'verdict' => $verdict,
                    'key' => $key,
                    'classification' => $classification,
                ];

                continue;
            }

            if (!$dryRun) {
                $this->writeVerdict($store, $key, $classification, $evidence);
                $store->recordOutcome($key, $after);
            }

            if ($after !== $before) {
                ++$changed;
            }
        }

        $this->line(sprintf(
            '%d annonce(s) re-jugée(s), %d verdict(s) modifié(s), %d promotion(s) vers MATCH.',
            $rejudged,
            // Promotions are NOT counted here: nothing has been written for them yet, and a
            // "modifié" that a failed send then rolls back would be a number the store contradicts.
            $changed,
            count($promotions),
        ));

        if ($skipped > 0) {
            // Both causes are reachable HERE, unlike in `digest()`: `staleVerdicts()` selects
            // `tenure IS NULL`, which a genuine pre-v7 row has, AND `tenure = 'UNKNOWN'`, which a
            // listing whose payload could not be encoded has. Naming only the migration would be
            // the same invented cause the digest line carried.
            $this->line(sprintf(
                '%d annonce(s) sans instantané (antérieures au schéma v7, ou charge utile non encodable) '
                . '— ignorées : les re-juger sur moins de preuves que l\'originale est exactement la '
                . 'brèche que le §1 interdit.',
                $skipped,
            ));
        }
        if ($unreadable > 0) {
            $this->line($unreadable . ' instantané(s) illisible(s) — voir les avertissements ci-dessus.');
        }

        if ($dryRun) {
            $this->line('--dry-run : aucun verdict réécrit, aucune notification envoyée.');

            return 0;
        }

        if ($promotions === []) {
            return 0;
        }

        // `$notifier` is non-null here: it is built above whenever `$dryRun` is false, and the
        // dry-run path returned already.
        return $this->announcePromotions($store, $notifier ?? $this->notifier($criteria), $promotions);
    }

    /**
     * Write a verdict and the evidence it was formed from, in the one statement the store provides.
     *
     * Extracted so the two call sites cannot drift: a verdict written from a different snapshot
     * than the one just judged is the divergence `evidence_json` exists to make impossible.
     */
    private function writeVerdict(
        Store $store,
        string $key,
        \RentWatch\Core\Classification $classification,
        RawListing $evidence,
    ): void {
        $store->recordVerdict(
            $key,
            $classification->tenure->value,
            $classification->confidenceBp,
            $classification->reasons(),
            $evidence,
        );
    }

    /**
     * Push every DIGEST -> MATCH promotion as a new match.
     *
     * Being told a flat is DOUBTFUL is not being told it is a MATCH, so a row already carried in a
     * delivered digest is notified again here — that is the miss Q35 exists to recover, not a
     * duplicate.
     *
     * @param list<array{listing: RawListing, verdict: Verdict, key: string, classification: \RentWatch\Core\Classification}> $promotions
     */
    private function announcePromotions(Store $store, Notifier $notifier, array $promotions): int
    {
        $formatter = new Formatter();
        $now = $this->nowIso ?? date('c');
        $undelivered = 0;

        foreach ($promotions as $promotion) {
            $failures = $notifier->send($formatter->match($promotion['listing'], $promotion['verdict']));
            foreach ($failures as $failure) {
                $this->warn($failure->getMessage());
            }

            if (!$notifier->delivered($failures)) {
                // NOTHING IS WRITTEN. The row keeps its old tenure and its old outcome, so it is
                // still selected by `staleVerdicts()` next run and the retry the warning promises
                // is real. Writing first — which this did until 2026-08-24 — removed it from every
                // selector at once and left a MATCH nobody was told about and nothing could reach.
                ++$undelivered;
                continue;
            }

            // ONLY NOW, and in this order: the verdict and its evidence, then the outcome, then the
            // delivery mark. Each is a fact that is true because the one before it is.
            $this->writeVerdict($store, $promotion['key'], $promotion['classification'], $promotion['listing']);
            $store->recordOutcome($promotion['key'], $promotion['verdict']->outcome->value);
            $store->markNotified($promotion['key'], $now);
        }

        if ($undelivered > 0) {
            $this->warn($undelivered . ' promotion(s) non délivrée(s) — rien n\'a été écrit, elles seront réessayées.');

            return 1;
        }

        return 0;
    }

    /** How long ago this listing was first seen, for the criteria engine's freshness component. */
    private function ageSeconds(Store $store, string $dedupKey): ?int
    {
        $snapshot = $store->snapshot($dedupKey);
        if ($snapshot === null) {
            return null;
        }

        try {
            $first = new \DateTimeImmutable($snapshot->firstSeenAt);
            $now = new \DateTimeImmutable($this->nowIso ?? 'now');
        } catch (\Exception) {
            return null;
        }

        return max(0, $now->getTimestamp() - $first->getTimestamp());
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

    /**
     * A bound on the number of `--watch` passes, from `RENT_WATCH_MAX_PASSES`. Absent — the normal
     * case, and the documented behaviour — means the loop runs until it is stopped.
     *
     * A TEST SEAM, in the same shape as `RENT_WATCH_OFFLINE`, and it exists because `--watch` is the
     * one verb whose success case never returns. Without a bound, a test that expects the run to be
     * REFUSED and is wrong does not fail: it blocks, and takes the whole suite with it. The sabotage
     * ledger sat on exactly that for eleven minutes before it was noticed, and a gate that stalls
     * silently is worse than one that reports a failure.
     *
     * Anything that is not a positive integer is ignored rather than refused: this must never be the
     * reason a watcher will not start on a machine where somebody exported a stray value.
     */
    private function maxPasses(): ?int
    {
        $configured = getenv('RENT_WATCH_MAX_PASSES');

        if (!is_string($configured) || preg_match('/^[1-9]\d*$/', trim($configured)) !== 1) {
            return null;
        }

        return (int) trim($configured);
    }

    /**
     * Where the Q27 liveness marker and the last startup refusal live.
     *
     * Beside the database, under `state/`, because Q8 rules that `state/` is the MOUNTED VOLUME on
     * the VPS — the one directory that survives a container being replaced. A marker in the image
     * would reset on every deploy, which for the refusal note means losing exactly the message it
     * exists to carry across a restart.
     *
     * Files rather than store rows, deliberately. A lost marker costs one extra low-priority
     * heartbeat, which is the benign direction; and `src/php/Store/**` is on
     * `tests/sabotage-check.sh`'s mandatory trigger list, so putting a liveness marker there would
     * owe a multi-hour ledger run for a timestamp. Q27 already rules `last-refusal.txt` to be a file
     * in `state/`, so its sibling matches the ruling's own shape.
     */
    private function stateFile(string $name): string
    {
        $db = $this->dbPath();
        $dir = $db === ':memory:' ? $this->rootDir . '/state' : \dirname($db);

        return $dir . '/' . $name;
    }

    /** The instant of the last heartbeat, or `null` when none has been sent by this deployment. */
    private function lastHeartbeat(): ?string
    {
        $path = $this->stateFile('heartbeat.txt');

        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        return \is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
    }

    /**
     * Emit the Q27 heartbeat if it is due, and record that it was.
     *
     * Q27: low priority, *"stating runs completed, sources OK and matches sent"*, and emitted
     * **whether or not anything matched** — that is the entire point, since a watcher that only
     * speaks when it has news is silent in exactly the two cases that must be told apart.
     *
     * The marker is written only after a successful send. A heartbeat that failed to deliver must
     * not mark itself done, or a broken channel would be papered over by its own bookkeeping.
     */
    /** @param list<string> $watched the sources this run actually polls — see below */
    private function beat(
        Notifier $notifier,
        Store $store,
        int $passes,
        int $matches,
        ?string $refusal,
        array $watched,
    ): void {
        $now = $this->now();
        $reasons = [
            $passes === 0 ? 'démarrage de la surveillance' : $passes . ' passe(s) terminée(s)',
            $matches . ' annonce(s) notifiée(s)',
        ];

        // The health figure counts WHAT THIS RUN WATCHES, not what the config enables. It used to
        // read every enabled source, so `--watch --source=x` against the shipped config reported
        // "1/5 source(s) en bon état" — four faults that did not exist, in the one channel whose
        // whole value is that it can be believed. It also degrades: an unpolled source's health
        // record goes STALE, so the beat would eventually alarm every day about sources nobody
        // asked it to watch, and a health line that always reads "4 broken" is a line its reader
        // learns to skip.
        $ok = 0;
        foreach ($watched as $name) {
            if ($store->health($name, $now)->status === SourceStatus::OK) {
                ++$ok;
            }
        }
        $reasons[] = $ok . '/' . \count($watched) . ' source(s) en bon état';

        // But silence about the scope would replace one wrong reading with another: a deployment
        // carrying a forgotten `--source` would report a flawless 1/1 forever while four landlords
        // went unwatched. The startup banner already states the count, and it is a log line nobody
        // re-reads; the beat is what reaches the phone, so the scope has to travel WITH the figure.
        // Only when it is true — a permanent parenthetical on every beat is noise, and noise on the
        // liveness channel costs the same as a false alarm.
        $configured = \count($this->sourceNames());

        if ($configured !== \count($watched)) {
            $reasons[] = 'limité par --source : ' . $configured . ' configurée(s), '
                . \count($watched) . ' surveillée(s)';
        }

        if ($refusal !== null) {
            // Q27's other half: "the next successful start can report what happened while it was
            // down". Carried on the startup beat, because a refusal that only sat in a file would
            // be read by somebody who already knew to look.
            $reasons[] = 'refus au démarrage précédent : ' . $refusal;
        }

        $failures = $notifier->send(new \RentWatch\Core\Notify\Notification(
            kind: \RentWatch\Core\Notify\NotificationKind::HEARTBEAT,
            priority: Priority::LOW,
            title: 'rent-watch : toujours actif',
            reasons: $reasons,
        ));

        foreach ($failures as $failure) {
            $this->warn(Redact::text($failure->getMessage()));
        }

        if ($notifier->delivered($failures)) {
            @file_put_contents($this->stateFile('heartbeat.txt'), $now . "\n");
        }
    }

    /** @return list<string> */
    private function sourceNames(): array
    {
        $names = [];
        foreach (ConfigLoader::loadSources($this->rootDir . '/config/sources.json') as $definition) {
            if ($definition->enabled) {
                $names[] = $definition->name;
            }
        }

        return $names;
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
    private function sources(Store $store, ?array $only = null, ?Criteria $criteria = null): array
    {
        $out = [];
        $known = [];
        // One `robots.txt` per host per build, and the cache is a LOCAL rather than a property so
        // its lifetime is exactly this call. Hard rule 5 is about load as well as permission:
        // re-reading robots for every page of a four-page walk is a request the site did not need
        // to serve. See {@see RobotsResolver} on why the resolver itself does not hold this.
        /** @var array<string, Robots> $robotsByOrigin */
        $robotsByOrigin = [];

        foreach (ConfigLoader::loadSources($this->rootDir . '/config/sources.json') as $definition) {
            if ($only !== null && !in_array($definition->name, $only, true)) {
                continue;
            }
            $known[] = $definition->name;

            if (!$definition->enabled) {
                // `--source=<name>` FORCE-RUNS a disabled source, and only an explicit name does.
                //
                // Without this, `/add-source` step 5 — "run `scout doctor` against the new block,
                // flip `enabled: true` once it is green" — could not be followed in that order: the
                // block had to be enabled before anything would run it, which is the edit to
                // committed config the flag exists to avoid. `dump` has always resolved a source by
                // name regardless of `enabled`, so this makes the verbs agree rather than inventing
                // a rule. What it is NOT is a loosening of the enabled check: an ordinary run still
                // skips the source, which `testADisabledSourceStaysOutOfAnOrdinaryRun` pins.
                if ($only === null) {
                    continue;
                }

                // LOUD, because the failure this could become is a `--source` left behind in a
                // deployment: a source nobody enabled, running every fifteen minutes, indis-
                // tinguishable in the output from one somebody turned on deliberately.
                $this->warn('source ' . $definition->name . ' est désactivée dans la config, '
                    . 'exécutée parce qu\'elle est nommée explicitement');
            }

            if ($definition->requiresScrapingOptIn() && !$this->scrapingAllowed()) {
                // Hard rule 4 / Q26. Direct scraping of a private portal refuses to run without an
                // explicit flag, and the flag is never persisted in config — so starting a scrape is
                // a deliberate act each time rather than a boolean somebody flipped once.
                $this->warn('source ' . $definition->name . ' ignorée : le scraping direct d\'un portail privé '
                    . 'exige --i-accept-legal-risk sur cette invocation');

                continue;
            }

            $source = $this->buildSource($definition, $store, $criteria, $robotsByOrigin);
            if ($source === null) {
                $this->warn('source ' . $definition->name . ' ignorée : aucun adaptateur pour le type `'
                    . $definition->type . '`');

                continue;
            }

            $out[] = $source;
        }

        // A `--source` naming nothing is a typo, and a typo that silently runs zero sources is the
        // worst possible outcome of a debugging flag: it reports a clean, fast, empty pass. Name
        // what was asked for and what exists, and let the caller decide.
        if ($only !== null) {
            $absent = array_values(array_diff($only, $known));
            if ($absent !== []) {
                $this->warn('source(s) inconnue(s) : ' . implode(', ', $absent));
            }
        }

        return $out;
    }

    /**
     * `--source=<name>`, repeatable. `null` means every enabled source.
     *
     * The flag exists for the moment a new source is onboarded: `/add-source` asks for a run
     * against exactly one block, and without this the only way to get one was to disable the
     * others — an edit to committed config, made under time pressure, easy to forget to undo.
     *
     * @param list<string> $flags
     *
     * @return list<string>|null
     */
    private function onlySources(array $flags): ?array
    {
        $names = [];
        foreach ($flags as $flag) {
            if (str_starts_with($flag, '--source=')) {
                $name = trim(substr($flag, strlen('--source=')));
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        return $names === [] ? null : $names;
    }

    /**
     * @param ?Criteria $criteria the run's own filters, from which the hydration RANKING is built.
     *        No longer a gate — novelty is the gate and it lives in the store's detail cache; this
     *        only decides who goes first when a pass's budget cannot cover every candidate.
     */
    private function buildSource(
        SourceDefinition $definition,
        Store $store,
        ?Criteria $criteria = null,
        array &$robotsByOrigin = [],
    ): ?Source {
        // THE RANKING, and rank 0 is the one that matters.
        //
        // A listing NOT YET IN THE SEEN-SET is about to be notified on this very pass, and it is
        // the only candidate for which hydration can still change the outcome. Ranked any lower, it
        // loses its slot to backlog — and by the time backlog's slot comes round it has already
        // been notified unhydrated, with no title checked and no floor, so hydrating it then buys
        // nothing at all. That is the pass-2 bypass this whole design exists to close, rebuilt out
        // of a budget instead of a missing cache.
        //
        // Rank 1 is the geographic filter, and WHY that one and not the whole criteria set:
        // `matchesCommune()` is the only filter whose inputs a CARD already carries in full.
        // Ranking on rent or surface would deprioritise on a field the detail page might have been
        // the one to supply — and while ordering can only delay a listing rather than reject it,
        // delaying on unknown-because-unfetched is the same reasoning error hard rule 8 names.
        //
        // Rank 2 is everything else: hydrated eventually, in source order, out of the backlog.
        $priority = $criteria === null
            ? null
            : static function (RawListing $listing) use ($criteria, $store): int {
                if ($store->snapshot($store->dedupKey($listing)) === null) {
                    return 0;
                }

                return $criteria->matchesCommune($listing->commune, $listing->postcode) ? 1 : 2;
            };

        // Hard rule 1, enforced at the single funnel every verb passes through. The LOADER refuses
        // an `enabled: true` source carrying the placeholder, and that was the whole guard for as
        // long as a disabled source could never run; `--source=<name>` force-running one, and
        // `dump` having always done so, both bring it back within reach. Refused rather than
        // attempted: fetching the literal string fails anyway, but with a curl error that says
        // nothing about why.
        if ($definition->url === ConfigLoader::UNVERIFIED_URL) {
            throw ConfigError::at(
                'sources.' . $definition->name . '.url',
                'still the ' . ConfigLoader::UNVERIFIED_URL . ' placeholder — its endpoint has never '
                    . 'been verified against the live site (CLAUDE.md hard rule 1), so there is '
                    . 'nothing to poll',
            );
        }

        return match ($definition->type) {
            'fixture' => new FixtureSource($definition, $store, $this->rootDir),
            // The ADAPTER exists and is tested; what waits on the developer is the URL in
            // `config/sources.json`. Hard rule 1 forbids writing an endpoint from memory, and the
            // loader refuses `enabled: true` next to a REMPLACER placeholder — so this can never
            // poll something nobody verified, while still being ready the moment a capture lands.
            'json' => new HttpJsonSource($definition, $store, $this->http, $this->robotsFor($definition, $robotsByOrigin)),
            // Same shape as `json`, one step different in the middle: the payload is parsed as
            // HTML5 by the language's own `Dom\HTMLDocument` and the field map is read as CSS
            // selectors. In'li is the first real source to use it — its search page is
            // server-rendered, so there is no JSON endpoint to prefer.
            'html' => new HtmlSource(
                $definition,
                $store,
                $this->http,
                $this->robotsFor($definition, $robotsByOrigin),
                $priority,
                // The CLOCK, not a reading of it. `$sources` is built ONCE, before the watch loop,
                // so `now()` here would hand every source the moment the PROCESS started and keep
                // it for weeks: the detail backoff would compute `now - since` as zero for ever and
                // never retry a failed page, and every `fetched_at` in `listing_detail` would
                // record process start rather than the fetch. `null` in production means the source
                // reads real time on each pass; an injected value still propagates for tests.
                $this->nowIso,
            ),
            'email_alert' => $this->buildEmailSource($definition, $store),
            default => null,
        };
    }

    /**
     * The `robots.txt` verdict governing this source, read once per host per process.
     *
     * Never `null`. A `null` here is precisely the defect this method was added to close: both
     * adapters guard every robots check with `$this->robots !== null`, so passing `null` does not
     * mean *"check later"* — it means *"never check"*, silently, on every real poll. A source whose
     * url carries no usable host therefore gets a fail-closed verdict rather than an exemption.
     */
    private function robotsFor(SourceDefinition $definition, array &$robotsByOrigin): Robots
    {
        $url = $definition->url;

        if ($url === null || trim($url) === '') {
            return Robots::unavailable('la source ne déclare aucune url');
        }

        $origin = $this->robotsResolver->originFor($url);

        if ($origin === null) {
            return $this->robotsResolver->forUrl($url);   // yields the fail-closed verdict, with its reason
        }

        return $robotsByOrigin[$origin] ??= $this->robotsResolver->forUrl($url);
    }

    /**
     * The email-alert path, with its mailbox chosen by `.env`.
     *
     * `MAILBOX_DIR` points at a directory of `.eml` files and needs no credentials at all — which is
     * how the whole parse → classify → notify path is exercisable before an IMAP account exists, and
     * how a real alert becomes a regression fixture once one does. `IMAP_*` switches to the live
     * mailbox with no other change.
     */
    private function buildEmailSource(SourceDefinition $definition, Store $store): ?Source
    {
        $mailbox = $this->buildMailbox();

        if ($mailbox === null) {
            $this->warn('source ' . $definition->name . ' ignorée : ni MAILBOX_DIR ni IMAP_HOST ne sont '
                . 'définis, donc aucune boîte aux lettres à lire');

            return null;
        }

        return new EmailAlertSource(
            $definition,
            $store,
            $mailbox,
            $this->criteria()->communeLabels,
        );
    }

    private function buildMailbox(): ?Mailbox
    {
        $directory = (string) (getenv('MAILBOX_DIR') ?: '');
        if ($directory !== '') {
            return new FileMailbox($directory);
        }

        $host = (string) (getenv('IMAP_HOST') ?: '');
        if ($host === '') {
            return null;
        }

        return new ImapMailbox(
            host: $host,
            user: (string) (getenv('IMAP_USER') ?: ''),
            password: (string) (getenv('IMAP_PASSWORD') ?: ''),
            folder: (string) (getenv('IMAP_MAILBOX') ?: 'INBOX'),
            port: (int) (getenv('IMAP_PORT') ?: 993),
        );
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
                '[rent-watch]',
                $this->buildMailTransport(),
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

    /**
     * How email leaves, chosen by `SMTP_TRANSPORT`.
     *
     * `file` writes `.eml` files and needs nothing — it is how `scout test-notify` produces a real,
     * readable message with no server and no credential. `smtp` speaks the protocol with `.env`
     * credentials. `sendmail` hands off to a host MTA, which is right under a Docker compose stack
     * that runs one.
     *
     * The default is `smtp` WHEN `SMTP_HOST` is set and `sendmail` otherwise, rather than a fixed
     * choice: a developer who filled in the SMTP block expects it to be used, and one who did not
     * has an MTA or does not want email at all.
     */
    private function buildMailTransport(): MailTransport
    {
        $kind = strtolower((string) (getenv('SMTP_TRANSPORT') ?: ''));
        $host = (string) (getenv('SMTP_HOST') ?: '');

        if ($kind === '') {
            $kind = $host !== '' ? 'smtp' : 'sendmail';
        }

        return match ($kind) {
            'file' => new FileTransport((string) (getenv('MAIL_OUTBOX') ?: $this->rootDir . '/var/outbox')),
            'smtp' => new SmtpTransport(
                host: $host,
                port: (int) (getenv('SMTP_PORT') ?: 587),
                user: (string) (getenv('SMTP_USER') ?: ''),
                password: (string) (getenv('SMTP_PASSWORD') ?: ''),
                from: (string) (getenv('SMTP_FROM') ?: 'rent-watch@localhost'),
                security: strtolower((string) (getenv('SMTP_SECURITY') ?: 'starttls')),
            ),
            default => new SendmailTransport(),
        };
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
            '  scout run --watch [-v]        boucle : 15 min ± 5 de jitter (Q37)',
            '  … --source=<nom>              limite `doctor` / `run` à une source (répétable)',
            '  scout digest [--dry-run]      émet le récapitulatif « à vérifier » en attente',
            '  scout reclassify [--dry-run]  re-juge les verdicts indéterminés stockés',
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

    /**
     * A startup refusal from `scout run`: reported now, and RECORDED for the next start (Q27).
     *
     * Scoped to `run` on purpose. A `doctor` refusal is read by whoever typed it, in the terminal
     * they typed it in, so recording it would make the next watch start report noise the operator
     * already saw and already acted on.
     */
    private function failRun(string $text): int
    {
        $this->recordRefusal($text);

        return $this->fail($text);
    }

    /**
     * Record why `scout run` refused to start, for the next successful start to report (Q27).
     *
     * A startup refusal is the one failure that reaches nobody: the process exits before any
     * notification channel is used, and under Docker its stderr scrolls past in a log the operator
     * is not reading. Q27's answer is to leave it on the mounted volume so the next start can say
     * what happened while it was down.
     *
     * **Redacted before it touches the disk.** A refusal text can carry a channel credential problem
     * or a URL with a key in it — `Store::recordRun()` redacts at its funnel for the same reason,
     * and this is a second disk-write funnel, so it needs the same guard rather than trusting the
     * caller. Failure to write is swallowed on purpose: a full or read-only volume must not turn a
     * refusal-with-a-reason into a crash with none.
     */
    private function recordRefusal(string $text): void
    {
        @file_put_contents($this->stateFile('last-refusal.txt'), $this->now() . ' — ' . Redact::text($text) . "\n");
    }

    /** The previous startup refusal, removed as it is read so it is reported exactly once. */
    private function takeLastRefusal(): ?string
    {
        $path = $this->stateFile('last-refusal.txt');

        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        @unlink($path);

        return \is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
    }
}
