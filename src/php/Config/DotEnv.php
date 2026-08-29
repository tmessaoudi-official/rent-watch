<?php

declare(strict_types=1);

namespace Scout\Config;

/**
 * Reads `.env` into the process environment — by PARSING it, never by executing it.
 *
 * ## Why this exists
 *
 * Nothing loaded `.env` on the host. Under Docker it works, because Compose's `env_file:` parses
 * the file itself; run `bin/scout` directly and the same file was inert, so the documented
 * workaround was `set -a; . ./.env; set +a`. That is not a parser. It is the SHELL, and it has two
 * failure modes that were both observed on 2026-08-22 within a minute of each other, on a Gmail app
 * password pasted with the spaces Google displays it with:
 *
 * - `SMTP_PASSWORD=abcd efgh ijkl mnop` is not an assignment to bash. It is a one-command
 *   environment prefix, so the variable is set for the duration of the command `efgh ijkl mnop`
 *   and **never exported**. The CLI then reported `SMTP_PASSWORD is empty` — correctly, and
 *   incomprehensibly.
 * - bash **ran** `efgh ijkl mnop` and printed `command not found: efgh`, putting four characters of
 *   a live credential on the terminal. A value containing backticks or `$(…)` would have been
 *   executed rather than merely echoed.
 *
 * The host and the container therefore disagreed about what a config file MEANT, which is the
 * defect — the printed fragment was a symptom. This class is the fix: one parser, the same values
 * either way, and nothing in the file is ever executed.
 *
 * ## Semantics, chosen to match Compose's `env_file` rather than a shell
 *
 * - A missing file is **not an error** — `compose.yaml` declares `.env` `required: false` and a
 *   fresh clone has none. A file that exists and cannot be READ is an error, because that is a
 *   broken deployment rather than an absent one.
 * - Blank lines, and lines whose first non-space character is `#`, are skipped. An `export` prefix
 *   is accepted, because people paste it.
 * - `KEY=VALUE`. The value is taken **literally**, including spaces: no expansion, no command
 *   substitution, no escape processing. Matching single or double quotes wrapping the whole value
 *   are stripped, which is the only way to keep meaningful trailing whitespace.
 * - **The real environment WINS.** A variable already set is never overwritten, so
 *   `RENT_SCOUT_DB=/tmp/throwaway bin/scout run` still works, Compose's `environment:` still
 *   outranks its own `env_file:`, and CI is never quietly overridden by a file on disk.
 *
 * ## What it will not do
 *
 * A malformed line is refused, and the refusal names the **line number and nothing else**. It must
 * never quote the line: this file is where the IMAP password, the SMTP password and the ntfy topic
 * live, and `ConfigError` messages are printed to the terminal and — for `run` — recorded via
 * {@see \Scout\Core\Redact} into `state/last-refusal.txt`. A parser that echoes what it could
 * not parse is a parser that leaks a credential on the day someone fat-fingers one.
 */
final class DotEnv
{
    /**
     * @param string $path the file to read; absent is fine, unreadable is not
     *
     * @return list<string> the names of the variables actually applied, in file order. Names only —
     *                      a caller that wants to log what happened must not be handed the values.
     *
     * @throws ConfigError on an unreadable file or a line that is not `KEY=VALUE`
     */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            // Distinguished from "absent" deliberately: a `.env` that exists and cannot be read is
            // a permissions mistake on a real deployment, and silently continuing would start the
            // watcher with no channels and no explanation.
            throw ConfigError::at('.env', 'exists but could not be read — check its permissions');
        }

        $applied = [];

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $raw)) as $index => $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (str_starts_with($trimmed, 'export ')) {
                $trimmed = ltrim(substr($trimmed, strlen('export ')));
            }

            $eq = strpos($trimmed, '=');
            if ($eq === false || $eq === 0) {
                throw self::malformed($index + 1);
            }

            $name = rtrim(substr($trimmed, 0, $eq));
            if (preg_match('~^[A-Za-z_][A-Za-z0-9_]*$~', $name) !== 1) {
                throw self::malformed($index + 1);
            }

            if (self::alreadySet($name)) {
                continue;
            }

            self::apply($name, self::unquote(substr($trimmed, $eq + 1)));
            $applied[] = $name;
        }

        return $applied;
    }

    /**
     * The line number, and NOT the line. See the class docblock — every value in this file is a
     * secret or a path, and this message reaches a terminal and a state file.
     */
    private static function malformed(int $lineNumber): ConfigError
    {
        return ConfigError::at(
            '.env:' . $lineNumber,
            'is not `KEY=VALUE`, a blank line or a `#` comment. The line is deliberately not quoted '
                . 'here — this file holds credentials',
        );
    }

    /**
     * Already set in the REAL environment, at any of the three places PHP exposes it.
     *
     * All three are checked because they can disagree: `putenv()` writes only where `getenv()` looks,
     * while a var inherited from the parent process lands in `$_ENV`/`$_SERVER` too depending on
     * `variables_order`. Missing one of them is how a "the environment wins" rule quietly stops
     * winning under a php.ini nobody looked at.
     */
    private static function alreadySet(string $name): bool
    {
        return getenv($name) !== false
            || array_key_exists($name, $_ENV)
            || array_key_exists($name, $_SERVER);
    }

    private static function apply(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    /**
     * Strips ONE layer of matching wrapping quotes, and processes nothing else.
     *
     * No escape handling on purpose. `SMTP_PASSWORD=p@ss\word` means those exact characters, and a
     * parser that turned `\w` into anything would corrupt a credential silently — the failure this
     * whole class exists to prevent, arriving from the other direction.
     */
    private static function unquote(string $value): string
    {
        // Trimmed BOTH ends before the quote test, and that order is the whole point: `KEY= "x "`
        // and `KEY="x "` must mean the same thing. Surrounding whitespace is never significant;
        // whitespace INSIDE quotes always is, which is the only way to express a value that really
        // ends in a space.
        $value = trim($value);

        if (strlen($value) >= 2) {
            $first = $value[0];
            if (($first === '"' || $first === "'") && str_ends_with($value, $first)) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }
}
