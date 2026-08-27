<?php

declare(strict_types=1);

namespace Scout\Config;

/**
 * The `RENT_WATCH_*` → `SCOUT_*` rename, made impossible to half-apply.
 *
 * The repo was renamed on 2026-08-27. Four environment variables travelled with it, and they are
 * the ONE piece of that rename that can hurt, for a reason that has nothing to do with the code:
 * **the deployed `.env` on the host is not in git.** Rename here, forget there, and `SCOUT_DB` is
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
 * The message names the VARIABLE and never its VALUE: a startup refusal is persisted to
 * `state/last-refusal.txt` and read back onto the Q27 heartbeat, and a channel that quotes values
 * is how a pasted `imap://user:password@host` reaches a file — see {@see \Scout\Core\Redact}.
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
        'RENT_WATCH_DB' => 'SCOUT_DB',
        'RENT_WATCH_OFFLINE' => 'SCOUT_OFFLINE',
        'RENT_WATCH_MAX_PASSES' => 'SCOUT_MAX_PASSES',
        'RENT_WATCH_BACKUP_KEEP' => 'SCOUT_BACKUP_KEEP',
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
                    'ce nom a été remplacé par %s lors du renommage du 2026-08-27. '
                    . 'Renommez la ligne dans le `.env` DÉPLOYÉ (celui de l\'hôte, absent de git) '
                    . 'et supprimez l\'ancienne — la lire silencieusement laisserait les deux '
                    . 'orthographes valides indéfiniment.',
                    $new
                )
            );
        }
    }
}
