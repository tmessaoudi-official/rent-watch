<?php

declare(strict_types=1);

namespace Scout\Config;

use Scout\Core\MutableByDesign;

/**
 * Typed, consuming reader over one decoded JSON object.
 *
 * THE DESIGN POINT, and the reason this is a class rather than a pile of `isset()` checks: every
 * accessor **consumes** the key it reads, and {@see done()} then refuses whatever is left over. So
 * "unknown keys are an error" is enforced by the shape of the code rather than by an allow-list
 * maintained next to it. An allow-list is the classic drift: a key is added to the reads and
 * forgotten in the list, and from then on the list rejects a legitimate setting — or, worse, the
 * list is loosened once and never tightened, and a typo becomes a silently-ignored setting.
 *
 * JSON has no comments (`docs/OPEN-QUESTIONS.md` Q22), so two shapes are ignored, and only two:
 *
 * 1. A key in {@see COMMENT_KEYS} — `_comment`, `_why`, `_source`, `_verified_at`. Free-standing
 *    notes about the object as a whole.
 * 2. `_x`, **but only when `x` is also present in the same object.** That is the useful shape — a
 *    note sitting immediately above the key it explains, where it will actually be read.
 *
 * The rule started as "any key beginning with `_`", and a review was right to call that unbounded:
 * under it, renaming `mixed_tenure` to `_mixed_tenure` disables §1's fail-closed switch and the
 * loader says nothing. Under the rule above the same edit produces TWO loud errors — `_mixed_tenure`
 * is an unknown key because its sibling is gone, and `mixed_tenure` is a missing required key. A flat
 * allow-list would also have fixed that, but at the cost of the per-key notes, which are the ones
 * worth having: a comment three screens from the value it governs is a comment nobody reads.
 *
 * Every failure names the full pointer to the offending value, because `CLAUDE.md` §1's fail-closed
 * rule is armed by one boolean in this file and a message reading "invalid config" would be useless.
 */
final class Reader implements MutableByDesign
{
    /**
     * The only keys treated as comments. Deliberately short and deliberately closed.
     *
     * These are the FREE-STANDING notes — about the object as a whole rather than about one key.
     * A note about a particular key uses the `_<key>` shape instead, which the constructor accepts
     * only while that key is present. `_source` and `_verified_at` record where an endpoint came
     * from and when it was last confirmed against the live site: hard rule 1's paper trail.
     */
    public const array COMMENT_KEYS = ['_comment', '_why', '_source', '_verified_at'];

    /** @var array<string, mixed> */
    private array $remaining;

    /**
     * @param string               $pointer dotted path of this object within its file, for messages
     * @param array<string, mixed> $data
     */
    public function __construct(
        private readonly string $pointer,
        array $data,
    ) {
        $this->remaining = [];
        foreach ($data as $key => $value) {
            $name = (string) $key;

            if (in_array($name, self::COMMENT_KEYS, true)) {
                continue;
            }

            // A `_x` note is a comment only while `x` is really there. Checked against the ORIGINAL
            // `$data` rather than against what has been kept so far, so it does not depend on key
            // order — a note written above its key must behave the same as one written below it.
            if (str_starts_with($name, '_') && array_key_exists(substr($name, 1), $data)) {
                continue;
            }

            $this->remaining[$name] = $value;
        }
    }

    /**
     * Decode a whole file into a reader.
     *
     * @throws ConfigError if the file is missing, unreadable, not valid JSON, or not a JSON object
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw ConfigError::at($path, 'no such config file');
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw ConfigError::at($path, 'config file exists but could not be read');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ConfigError::at($path, 'not valid JSON — ' . $e->getMessage());
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw ConfigError::at($path, 'top level must be a JSON object, not ' . self::describe($decoded));
        }

        /** @var array<string, mixed> $decoded */
        return new self(basename($path), $decoded);
    }

    public function pointer(): string
    {
        return $this->pointer;
    }

    /** Is the key present (and not yet consumed)? */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->remaining);
    }

    /**
     * Every key this reader still holds — used by callers that legitimately iterate an open-ended
     * map (the `sources` object, a `headers` object), which is the one shape `done()` cannot check.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->remaining);
    }

    /** @throws ConfigError if any non-underscore key was never read */
    public function done(): void
    {
        if ($this->remaining === []) {
            return;
        }

        $unknown = array_keys($this->remaining);
        sort($unknown);

        throw ConfigError::at(
            $this->pointer,
            'unknown key' . (count($unknown) === 1 ? '' : 's') . ' ' . implode(', ', $unknown)
                . ' — comments must use one of: ' . implode(', ', self::COMMENT_KEYS),
        );
    }

    /** @throws ConfigError */
    public function requireInt(string $key, ?int $min = null, ?int $max = null): int
    {
        $value = $this->take($key);

        return $this->asInt($key, $value, $min, $max);
    }

    /** @throws ConfigError */
    public function optInt(string $key, ?int $default, ?int $min = null, ?int $max = null): ?int
    {
        if (!$this->has($key)) {
            return $default;
        }

        $value = $this->take($key);
        if ($value === null) {
            return null;
        }

        return $this->asInt($key, $value, $min, $max);
    }

    /** @throws ConfigError */
    public function requireFloat(string $key, ?float $min = null, ?float $max = null): float
    {
        $value = $this->take($key);
        if (!is_int($value) && !is_float($value)) {
            throw $this->bad($key, 'a number', $value);
        }
        if (is_float($value) && !is_finite($value)) {
            throw $this->bad($key, 'a finite number', $value);
        }

        $out = (float) $value;
        if ($min !== null && $out < $min) {
            throw $this->bad($key, 'a number >= ' . $min, $value);
        }
        if ($max !== null && $out > $max) {
            throw $this->bad($key, 'a number <= ' . $max, $value);
        }

        return $out;
    }

    /** @throws ConfigError */
    public function optFloat(string $key, ?float $default, ?float $min = null, ?float $max = null): ?float
    {
        if (!$this->has($key)) {
            return $default;
        }
        if ($this->remaining[$key] === null) {
            $this->take($key);

            return null;
        }

        return $this->requireFloat($key, $min, $max);
    }

    /** @throws ConfigError */
    public function requireBool(string $key): bool
    {
        $value = $this->take($key);
        // Deliberately NOT accepting "true"/1/"yes". A string that looks like a boolean is exactly
        // how `mixed_tenure` would end up truthy-but-not-true, and this is the flag that arms §1.
        if (!is_bool($value)) {
            throw $this->bad($key, 'true or false', $value);
        }

        return $value;
    }

    /** @throws ConfigError */
    public function optBool(string $key, bool $default): bool
    {
        if (!$this->has($key)) {
            return $default;
        }

        return $this->requireBool($key);
    }

    /**
     * @param list<string> $allowed if non-empty, the value must be one of these
     *
     * @throws ConfigError
     */
    public function requireString(string $key, array $allowed = [], bool $allowEmpty = false): string
    {
        $value = $this->take($key);

        return $this->asString($key, $value, $allowed, $allowEmpty);
    }

    /**
     * @param list<string> $allowed
     *
     * @throws ConfigError
     */
    public function optString(string $key, ?string $default, array $allowed = []): ?string
    {
        if (!$this->has($key)) {
            return $default;
        }

        $value = $this->take($key);
        if ($value === null) {
            return null;
        }

        return $this->asString($key, $value, $allowed);
    }

    /**
     * @return list<string>
     *
     * @throws ConfigError
     */
    public function requireStringList(string $key, bool $allowEmptyList = false): array
    {
        $value = $this->take($key);
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->bad($key, 'an array of strings', $value);
        }
        if ($value === [] && !$allowEmptyList) {
            throw $this->bad($key, 'a non-empty array of strings', $value);
        }

        $out = [];
        foreach ($value as $i => $item) {
            if (!is_string($item) || trim($item) === '') {
                throw ConfigError::at(
                    $this->pointer . '.' . $key . '[' . $i . ']',
                    'expected a non-empty string, got ' . self::describe($item),
                );
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * A nested object, as its own reader — so `done()` applies at every level rather than only the top.
     *
     * @throws ConfigError
     */
    public function requireObject(string $key): self
    {
        $value = $this->take($key);
        if (!is_array($value) || array_is_list($value)) {
            throw $this->bad($key, 'a JSON object', $value);
        }

        /** @var array<string, mixed> $value */
        return new self($this->pointer . '.' . $key, $value);
    }

    /** @throws ConfigError */
    public function optObject(string $key): ?self
    {
        if (!$this->has($key)) {
            return null;
        }

        $peek = $this->remaining[$key];
        if ($peek === null) {
            $this->take($key);

            return null;
        }

        return $this->requireObject($key);
    }

    /**
     * A value of any shape, consumed. Used only where the schema is genuinely open — a `headers`
     * value, a field-map path that may be a string or a list of strings.
     */
    public function takeRaw(string $key): mixed
    {
        return $this->take($key);
    }

    private function take(string $key): mixed
    {
        if (!array_key_exists($key, $this->remaining)) {
            throw ConfigError::at($this->pointer . '.' . $key, 'required key is missing');
        }

        $value = $this->remaining[$key];
        unset($this->remaining[$key]);

        return $value;
    }

    private function asInt(string $key, mixed $value, ?int $min, ?int $max): int
    {
        // A float is refused even when integral: `1800.0` in a config file means the author was
        // thinking in a different type than the schema, and silently narrowing hides that.
        if (!is_int($value) || is_bool($value)) {
            throw $this->bad($key, 'an integer', $value);
        }
        if ($min !== null && $value < $min) {
            throw $this->bad($key, 'an integer >= ' . $min, $value);
        }
        if ($max !== null && $value > $max) {
            throw $this->bad($key, 'an integer <= ' . $max, $value);
        }

        return $value;
    }

    /** @param list<string> $allowed */
    private function asString(string $key, mixed $value, array $allowed, bool $allowEmpty = false): string
    {
        if (!is_string($value)) {
            throw $this->bad($key, 'a string', $value);
        }
        if (!$allowEmpty && trim($value) === '') {
            throw $this->bad($key, 'a non-empty string', $value);
        }
        if ($allowed !== [] && !in_array($value, $allowed, true)) {
            throw $this->bad($key, 'one of ' . implode(', ', $allowed), $value);
        }

        return $value;
    }

    private function bad(string $key, string $expected, mixed $got): ConfigError
    {
        return ConfigError::at(
            $this->pointer . '.' . $key,
            'expected ' . $expected . ', got ' . self::describe($got),
        );
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => 'the number ' . var_export($value, true),
            is_string($value) => 'the string ' . var_export($value, true),
            is_array($value) => array_is_list($value) ? 'an array' : 'an object',
            default => get_debug_type($value),
        };
    }
}
