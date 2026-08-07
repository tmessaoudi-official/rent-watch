<?php

declare(strict_types=1);

namespace RentWatch\Config;

/**
 * A config file said something the loader will not accept.
 *
 * Every message names the **JSON pointer** of the offending value (`sources.inli.mixed_tenure`) and
 * what was expected. That is not politeness: config here decides whether the fail-closed rule of
 * `CLAUDE.md` §1 is armed, and a validation message that says only "invalid config" turns a
 * five-second fix into a bisect.
 *
 * This is a `RuntimeException` rather than a `LogicException` on purpose. A malformed config is not
 * a programming error to be asserted away — it is an ordinary, expected, user-caused runtime
 * condition, and the CLI catches it and prints it rather than letting a stack trace out.
 */
final class ConfigError extends \RuntimeException
{
    public static function at(string $pointer, string $expected): self
    {
        return new self($pointer . ': ' . $expected);
    }
}
