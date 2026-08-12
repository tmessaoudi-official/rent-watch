<?php

declare(strict_types=1);

namespace RentWatch\Config;

use RentWatch\Core\Tenure;

/**
 * Reads `config/criteria.json` and `config/sources.json`, and refuses everything it does not
 * recognise.
 *
 * Config is JSON rather than YAML — ruled 2026-08-07 (`docs/OPEN-QUESTIONS.md` Q22): this container
 * has no `ext-yaml` and the egress policy blocks installing a parser, so `.yaml` files would sit
 * unread. `ext-json` is always present, and phorj's `Core.Json` is a default feature, so both
 * implementations can read the same file.
 *
 * Strictness is the whole design. Every unknown key is a hard error ({@see Reader::done()}), because
 * the alternative — ignoring what it does not understand — means a misspelled `mixd_tenure` silently
 * leaves a mixed-stock landlord classified as pure, and §1's fail-closed rule disarmed by a typo.
 */
final class ConfigLoader
{
    /** Placeholder for a URL that has not been verified against the live site (hard rule 1). */
    public const string UNVERIFIED_URL = 'REMPLACER';

    /**
     * Load criteria, applying a gitignored local override if one is present.
     *
     * The override exists so genuinely personal tuning never has to enter git (Q11): the committed
     * file carries only values that were already public in `prototype/sources.yaml`. Merging is
     * **deep for objects and wholesale for arrays** — an array merge would have no sane semantics
     * (does an override's commune list add to the base or replace it?), and "replace" is the only
     * answer a reader can predict.
     *
     * @throws ConfigError
     */
    public static function loadCriteria(string $path, ?string $localPath = null): Criteria
    {
        $data = self::decodeObject($path);

        if ($localPath !== null && is_file($localPath)) {
            $data = self::deepMerge($data, self::decodeObject($localPath));
        }

        return self::criteriaFromArray($data, basename($path));
    }

    /**
     * @param array<string,mixed> $data
     *
     * @throws ConfigError
     */
    public static function criteriaFromArray(array $data, string $pointer = 'criteria.json'): Criteria
    {
        $r = new Reader($pointer, $data);

        $communeLabels = [];
        $communes = [];
        foreach ($r->requireStringList('communes') as $label) {
            $key = Criteria::communeKey($label);
            if ($key === '') {
                throw ConfigError::at($pointer . '.communes', 'commune ' . var_export($label, true) . ' has no letters or digits');
            }
            if (isset($communeLabels[$key])) {
                throw ConfigError::at(
                    $pointer . '.communes',
                    var_export($label, true) . ' and ' . var_export($communeLabels[$key], true)
                        . ' normalise to the same commune',
                );
            }
            $communeLabels[$key] = $label;
            $communes[] = $key;
        }

        $prefixes = [];
        foreach ($r->requireStringList('postcode_prefixes', allowEmptyList: true) as $prefix) {
            if (preg_match('~^\d{1,5}$~', $prefix) !== 1) {
                throw ConfigError::at(
                    $pointer . '.postcode_prefixes',
                    var_export($prefix, true) . ' is not a run of 1–5 digits',
                );
            }
            $prefixes[] = $prefix;
        }

        $minRooms = $r->optInt('min_rooms', null, 1, 20);
        $minSurface = $r->optFloat('min_surface_m2', null, 1.0, 1000.0);
        $maxRent = $r->optInt('max_rent_cc', null, 1, 100000);

        // These are property-type and listing-kind patterns (colocation, meublé, résidence senior),
        // NOT tenure. Tenure exclusion lives in `Tenure::isExcluded()` and is deliberately absent
        // from config — see the Criteria class docblock and CLAUDE.md §1.
        $patterns = self::patternList($r, 'exclude_patterns', $pointer);
        $titlePatterns = self::patternList($r, 'exclude_title_patterns', $pointer);

        // Commune preference (score component S1). Ranking never filters; `communes` above does.
        $rankReader = $r->optObject('commune_rank');
        $rank = [];
        if ($rankReader !== null) {
            foreach ($rankReader->keys() as $label) {
                $key = Criteria::communeKey($label);
                if (!in_array($key, $communes, true)) {
                    throw ConfigError::at(
                        $pointer . '.commune_rank.' . $label,
                        'ranked but not in `communes` — a rank for a commune that is filtered out is dead config',
                    );
                }
                $rank[$key] = $rankReader->requireInt($label, 1, 1000);
            }
            $rankReader->done();
        }

        $weightsReader = $r->optObject('weights');
        $weights = $weightsReader === null ? new Weights() : Weights::fromReader($weightsReader);

        $notifyReader = $r->optObject('notify');
        $notify = $notifyReader === null ? new NotifyPolicy() : NotifyPolicy::fromReader($notifyReader);

        $freshness = $r->optInt('freshness_minutes', 60, 1, 10080) ?? 60;

        $commuteEnabled = false;
        $commuteStation = null;
        $commuteMinutes = null;
        $commuteReader = $r->optObject('commute');
        if ($commuteReader !== null) {
            $commuteEnabled = $commuteReader->optBool('enabled', false);
            $commuteStation = $commuteReader->optString('station', null);
            $commuteMinutes = $commuteReader->optInt('max_minutes', null, 1, 600);
            $commuteReader->done();

            if ($commuteEnabled && ($commuteStation === null || $commuteMinutes === null)) {
                throw ConfigError::at(
                    $pointer . '.commute',
                    'enabled but `station` or `max_minutes` is missing — an enabled commute filter '
                        . 'with nothing to measure against would silently score every listing the same',
                );
            }
        }

        if ($commuteEnabled && $weights->commute === 0) {
            throw ConfigError::at(
                $pointer . '.weights.commute',
                'commute is enabled but weighted 0, so it can never affect a score. '
                    . 'Either give it a weight or set `commute.enabled` to false',
            );
        }
        if (!$commuteEnabled && $weights->commute !== 0) {
            throw ConfigError::at(
                $pointer . '.weights.commute',
                'weighted ' . $weights->commute . ' but `commute.enabled` is false, so every listing '
                    . 'would lose that share of the score for a component that never runs',
            );
        }

        $r->done();

        return new Criteria(
            communes: $communes,
            communeLabels: $communeLabels,
            postcodePrefixes: $prefixes,
            minRooms: $minRooms,
            minSurfaceM2: $minSurface === null ? null : (float) $minSurface,
            maxRentCc: $maxRent,
            excludePatterns: $patterns,
            excludeTitlePatterns: $titlePatterns,
            communeRank: $rank,
            weights: $weights,
            notify: $notify,
            freshnessMinutes: $freshness,
            commuteEnabled: $commuteEnabled,
            commuteStation: $commuteStation,
            commuteMaxMinutes: $commuteMinutes,
        );
    }

    /**
     * @return list<string>
     *
     * @throws ConfigError
     */
    private static function patternList(Reader $r, string $key, string $pointer): array
    {
        if (!$r->has($key)) {
            return [];
        }

        $out = [];
        foreach ($r->requireStringList($key, allowEmptyList: true) as $pattern) {
            self::assertUsablePattern($pattern, $pointer . '.' . $key);
            $out[] = $pattern;
        }

        return $out;
    }

    /**
     * @return array<string, SourceDefinition> keyed by source name
     *
     * @throws ConfigError
     */
    public static function loadSources(string $path): array
    {
        return self::sourcesFromArray(self::decodeObject($path), basename($path));
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string, SourceDefinition>
     *
     * @throws ConfigError
     */
    public static function sourcesFromArray(array $data, string $pointer = 'sources.json'): array
    {
        $top = new Reader($pointer, $data);
        $sources = $top->requireObject('sources');
        $top->done();

        $out = [];
        foreach ($sources->keys() as $name) {
            $out[$name] = self::sourceFromReader($name, $sources->requireObject($name));
        }
        $sources->done();

        if ($out === []) {
            throw ConfigError::at($pointer . '.sources', 'no sources defined');
        }

        return $out;
    }

    /** @throws ConfigError */
    private static function sourceFromReader(string $name, Reader $r): SourceDefinition
    {
        $where = $r->pointer();

        if (preg_match('~^[a-z][a-z0-9_]*$~', $name) !== 1) {
            throw ConfigError::at($where, 'source names are lowercase, digits and underscores, starting with a letter');
        }

        $enabled = $r->requireBool('enabled');
        $family = $r->requireString('family', ['institutional', 'private']);

        $type = $r->requireString('type');
        if ($type === 'browser') {
            // Recognised and refused, rather than accepted and unimplemented. Ruled 2026-08-07 (Q10):
            // a stub adapter that fails at fetch time is a source that looks configured and is not.
            throw ConfigError::at(
                $where . '.type',
                'browser automation is not permitted in this project (docs/OPEN-QUESTIONS.md Q10). '
                    . 'Use `email_alert` for a portal that cannot be polled',
            );
        }
        if (!in_array($type, SourceDefinition::TYPES, true)) {
            throw ConfigError::at($where . '.type', 'expected one of ' . implode(', ', SourceDefinition::TYPES) . ', got ' . var_export($type, true));
        }

        // Required, never defaulted in the file. See the class docblock of SourceDefinition.
        if (!$r->has('mixed_tenure')) {
            throw ConfigError::at(
                $where . '.mixed_tenure',
                'required — it is the flag that arms the fail-closed rule of CLAUDE.md §1. '
                    . 'Say true unless this landlord provably publishes no social stock at all',
            );
        }
        $mixedTenure = $r->requireBool('mixed_tenure');

        $defaultTenure = null;
        if ($r->has('default_tenure')) {
            $raw = $r->optString('default_tenure', null);
            if ($raw !== null) {
                $defaultTenure = Tenure::tryFrom($raw);
                if ($defaultTenure === null) {
                    throw ConfigError::at($where . '.default_tenure', 'not a known tenure: ' . var_export($raw, true));
                }
                if ($defaultTenure->isExcluded()) {
                    throw ConfigError::at(
                        $where . '.default_tenure',
                        $raw . ' is in the excluded set (CLAUDE.md §1). A source whose default tenure is '
                            . 'social housing is wholly out of scope — remove the source rather than '
                            . 'declaring it here',
                    );
                }
                if ($defaultTenure === Tenure::UNKNOWN) {
                    throw ConfigError::at(
                        $where . '.default_tenure',
                        'UNKNOWN is what the classifier concludes, not what a source declares. Use null',
                    );
                }
            }
        }

        $url = $r->optString('url', null);
        $baseUrl = $r->optString('base_url', null);
        $method = $r->optString('method', 'GET', ['GET', 'POST']) ?? 'GET';
        $body = $r->optString('body', null);
        $itemsPath = $r->optString('items_path', null);
        $itemSelector = $r->optString('item_selector', null);
        $legalRisk = $r->optBool('legal_risk', false);
        $fixture = $r->optString('fixture', null);
        $rateLimitMs = $r->optInt('rate_limit_ms', 2000, 0, 600000) ?? 2000;

        $headers = self::stringMap($r->optObject('headers'));
        $params = self::stringMap($r->optObject('params'));

        foreach (array_keys($headers) as $headerName) {
            if (strtolower(trim($headerName)) === 'user-agent') {
                // In cURL, a User-Agent entry in CURLOPT_HTTPHEADER silently overrides
                // CURLOPT_USERAGENT — so this one config key would disguise every request from the
                // source, which is the browser impersonation hard rule 5 forbids outright. Refused
                // here so the operator learns at load time; `CurlHttpClient` refuses it again at
                // the funnel, because config is not the only way a header reaches a request.
                throw ConfigError::at(
                    $where . '.headers',
                    'a User-Agent header is not configurable. CLAUDE.md hard rule 5: this tool '
                        . 'identifies honestly, with one fixed User-Agent. If the source blocks '
                        . 'plain clients, use the email-alert route instead',
                );
            }
        }

        $mapReader = $r->optObject('map');
        $map = $mapReader === null ? new FieldMap(ref: ['id']) : FieldMap::fromReader($mapReader);

        $r->done();

        if ($enabled) {
            if ($type === 'fixture') {
                if ($fixture === null) {
                    throw ConfigError::at($where . '.fixture', 'a fixture source must name its payload file');
                }
            } elseif ($type !== 'email_alert') {
                if ($url === null) {
                    throw ConfigError::at($where . '.url', 'an enabled ' . $type . ' source needs a url');
                }
                if (str_contains($url, self::UNVERIFIED_URL)) {
                    throw ConfigError::at(
                        $where . '.url',
                        'still the ' . self::UNVERIFIED_URL . ' placeholder. CLAUDE.md hard rule 1: never write '
                            . 'an endpoint from memory — verify it against the live site, or leave the source disabled',
                    );
                }
            }
        }

        return new SourceDefinition(
            name: $name,
            enabled: $enabled,
            family: $family,
            type: $type,
            mixedTenure: $mixedTenure,
            defaultTenure: $defaultTenure,
            url: $url,
            baseUrl: $baseUrl,
            method: $method,
            body: $body,
            headers: $headers,
            params: $params,
            itemsPath: $itemsPath,
            itemSelector: $itemSelector,
            map: $map,
            legalRisk: $legalRisk,
            fixture: $fixture,
            rateLimitMs: $rateLimitMs,
        );
    }

    /**
     * @return array<string,string>
     *
     * @throws ConfigError
     */
    private static function stringMap(?Reader $r): array
    {
        if ($r === null) {
            return [];
        }

        $out = [];
        foreach ($r->keys() as $key) {
            $out[$key] = $r->requireString($key, allowEmpty: true);
        }
        $r->done();

        return $out;
    }

    /**
     * A pattern must compile, and must be written in FOLDED ASCII.
     *
     * Patterns are matched against {@see \RentWatch\Core\Text::fold()}ed text, which has had its
     * accents removed — so a config author who writes `meublé` gets a pattern that can never match,
     * and gets it silently. Refusing the accented form with a message naming the folded one is the
     * only way that mistake becomes visible; the alternative, folding the pattern for them, would
     * lowercase `\W` into `\w` and change what the regex means.
     *
     * @throws ConfigError
     */
    private static function assertUsablePattern(string $pattern, string $where): void
    {
        if (preg_match('~[\x80-\xFF]~', $pattern) === 1) {
            throw ConfigError::at(
                $where,
                var_export($pattern, true) . ' contains a non-ASCII character. Patterns are matched '
                    . 'against accent-folded text, so write `meuble`, not `meublé` — an accented '
                    . 'pattern can never match anything',
            );
        }

        // `set_error_handler` PUSHES; passing the old handler back would push a second one rather
        // than popping this one, and PHPUnit fails a test that leaves a handler on the stack. The
        // handler is needed at all because an invalid pattern raises a warning that `@` does not
        // hide from a registered handler, and this project runs with `failOnWarning`.
        set_error_handler(static fn (): bool => true);
        try {
            $ok = preg_match('~' . $pattern . '~i', '');
        } finally {
            restore_error_handler();
        }

        if ($ok === false) {
            throw ConfigError::at($where, var_export($pattern, true) . ' is not a valid regular expression');
        }
    }

    /**
     * @return array<string,mixed>
     *
     * @throws ConfigError
     */
    private static function decodeObject(string $path): array
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
            throw ConfigError::at($path, 'top level must be a JSON object');
        }

        /** @var array<string,mixed> $decoded */
        return $decoded;
    }

    /**
     * Objects merge key by key; everything else replaces.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $over
     *
     * @return array<string,mixed>
     */
    private static function deepMerge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            if (
                is_array($value) && !array_is_list($value)
                && isset($base[$key]) && is_array($base[$key]) && !array_is_list($base[$key])
            ) {
                /** @var array<string,mixed> $left */
                $left = $base[$key];
                /** @var array<string,mixed> $value */
                $base[$key] = self::deepMerge($left, $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
