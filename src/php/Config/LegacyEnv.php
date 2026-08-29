<?php

declare(strict_types=1);

namespace Scout\Config;

/**
 * The `RENT_WATCH_*` → `SCOUT_*` rename, made impossible to half-apply.
 *
 * The repo was renamed on 2026-08-27. Four environment variables travelled with it, and they are
 * the ONE piece of that rename that can hurt, for a reason that has nothing to do with the code:
 * **the deployed `.env` on the host is not in git.** Rename here, forget there, and `RENT_SCOUT_DB` is
 * unset while `RENT_WATCH_DB` still holds the real path — at which point `dbPath()` takes its
 * default, `Store::open()` creates a brand-new empty database, and the watcher is looking at
 * nothing. Q36's flood guard catches the second half of that (it refuses to notify while the
 * seen-set is empty, so the market is not re-announced), but it stops the watcher, and a stopped
 * watcher is indistinguishable from a quiet market — this project's defining failure.
 *
 * So a legacy name is a **loud refusal naming both spellings**, never a silent fallback. Reading
 * the old name as a courtesy would be worse than either alternative: the rename would never be
 * finished on any machine, and both spellings would work for ever.
 *
 * **It refuses on PRESENCE, not on absence of the successor.** A `.env` carrying both lines is the
 * shadowing bug that cost the IDFM key its first live hour — `DotEnv` applies the FIRST occurrence
 * of a name and skips every later one, and an empty string counts as set, so "the new one wins,
 * quietly" is precisely the shape that hid a dead key behind a plausible config. A stale line is
 * something to delete, not something to out-rank.
 *
 * The message names the VARIABLE and never its VALUE: `Scout::run()` calls this INSIDE its own
 * try, so a refusal here goes through `failRun()` and is persisted to `state/rent-last-refusal.txt`,
 * then read back onto the Q27 heartbeat. A channel that quotes values is how a pasted
 * `imap://user:password@host` reaches a file — see {@see \Scout\Core\Redact}.
 *
 * That call site is deliberate and was corrected once. Called from `bin/scout` — the obvious place,
 * next to the `.env` load — the refusal is stderr ONLY, because `recordRefusal()` is a method on
 * `Scout` and no `Scout` exists yet. The audience for this guard is a container crash-looping under
 * a restart policy on a host whose `.env` still carries the old name, which is exactly the reader
 * Q27 observes is not watching stderr.
 */
final class LegacyEnv
{
    /**
     * Legacy name → its successor. Adding a row here without a case in `LegacyEnvTest` fails that
     * suite by design: the map and its coverage are asserted equal.
     *
     * @var array<string, string>
     */
    public const MAP = [
        'RENT_WATCH_DB' => 'RENT_SCOUT_DB',
        'RENT_WATCH_OFFLINE' => 'SCOUT_OFFLINE',
        'RENT_WATCH_MAX_PASSES' => 'SCOUT_MAX_PASSES',
        'RENT_WATCH_BACKUP_KEEP' => 'SCOUT_BACKUP_KEEP',
        // THE DOMAIN SPLIT (2026-08-29). With a second domain in the tool, an unprefixed key can
        // only mean "the rent one", which is the assumption the developer refused: "like only rent
        // watch exists in this app". Every domain-bound key is RENT_* or CAR_*; the account-level
        // ones (IMAP_HOST/USER/PASSWORD, SMTP_*, NTFY_SERVER, IMAP_SINCE_DAYS, IMAP_MAX_MESSAGES)
        // and the tool-level ones (SCOUT_OFFLINE, SCOUT_MAX_PASSES, SCOUT_BACKUP_KEEP) stay shared.
        'SCOUT_DB' => 'RENT_SCOUT_DB',
        'IMAP_MAILBOX' => 'RENT_IMAP_MAILBOX',
        'NTFY_TOPIC' => 'RENT_NTFY_TOPIC',
        'HEARTBEAT_HOURS' => 'RENT_HEARTBEAT_HOURS',
        'FEED_SILENT_DAYS' => 'RENT_FEED_SILENT_DAYS',
    ];

    /**
     * The process's own environment, checked the way {@see DotEnv} decides a name is already set —
     * `getenv()` AND `$_ENV` AND `$_SERVER`. Missing one of the three is how a rule that reads the
     * environment quietly stops reading it under a `variables_order` nobody looked at.
     *
     * This exists so the entry point holds no logic of its own. `bin/scout` is the one file no test
     * is ever a subject of, and a guard written there is a guard nothing executes — the shape that
     * left the heartbeat's in-loop call site unreachable for a day.
     *
     * @throws ConfigError
     */
    public static function checkProcess(): void
    {
        $found = [];

        foreach (array_keys(self::MAP) as $old) {
            $live = getenv($old);

            if ($live !== false) {
                $found[$old] = $live;
            } elseif (array_key_exists($old, $_ENV)) {
                $found[$old] = (string) $_ENV[$old];
            } elseif (array_key_exists($old, $_SERVER)) {
                $found[$old] = (string) $_SERVER[$old];
            }
        }

        self::check($found);
    }

    /**
     * @param array<string, string> $env normally `$_ENV`, injected so this is testable without
     *                                   mutating the process environment
     *
     * @throws ConfigError on the first legacy name found, in MAP order — deterministic, so the
     *                     message does not depend on the environment's iteration order
     */
    public static function check(array $env): void
    {
        foreach (self::MAP as $old => $new) {
            if (!array_key_exists($old, $env)) {
                continue;
            }

            throw ConfigError::at(
                $old,
                sprintf(
                    'ce nom a été remplacé par %s (renommage du 2026-08-27, puis séparation rent/car du 2026-08-29). '
                    . 'Renommez la ligne dans le `.env` DÉPLOYÉ (celui de l\'hôte, absent de git) '
                    . 'et supprimez l\'ancienne — la lire silencieusement laisserait les deux '
                    . 'orthographes valides indéfiniment.',
                    $new
                )
            );
        }
    }
}
