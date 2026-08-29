<?php

declare(strict_types=1);

namespace Scout\Vehicle;

use Scout\Config\ConfigError;
use Scout\Config\Reader;
use Scout\Core\MalformedText;
use Scout\Core\Text;

/**
 * `config/car/criteria.json`, read with the rent side's strict `Reader`: every unknown key is a
 * hard error, `_`-prefixed keys are comments, and a gitignored `criteria.local.json` beside it
 * overrides field by field. The weights must sum to 100, so a score is a percentage and a weight
 * typo cannot silently rescale every push.
 */
final class VehicleCriteriaLoader
{
    public static function load(string $path, ?string $localPath = null): VehicleCriteria
    {
        $data = self::decodeObject($path);
        if ($localPath !== null && is_file($localPath)) {
            $data = self::deepMerge($data, self::decodeObject($localPath));
        }

        return self::fromArray($data, basename($path));
    }

    /**
     * @param array<string,mixed> $data
     *
     * @throws ConfigError
     */
    public static function fromArray(array $data, string $pointer = 'car/criteria.json'): VehicleCriteria
    {
        $r = new Reader($pointer, $data);

        $maxPrice = $r->requireInt('max_price_eur', 1);
        $prefixes = $r->requireStringList('postcode_prefixes', allowEmptyList: true);
        foreach ($prefixes as $p) {
            if (preg_match('~^\d{2,5}$~', $p) !== 1) {
                throw ConfigError::at($pointer . '.postcode_prefixes', 'préfixe invalide ' . var_export($p, true) . ' — 2 à 5 chiffres');
            }
        }

        $bodyRank = [];
        foreach ($r->requireStringList('body_rank', allowEmptyList: true) as $body) {
            try {
                $key = Text::fold($body);
            } catch (MalformedText $e) {
                throw ConfigError::at($pointer . '.body_rank', 'carrosserie illisible : ' . $e->getMessage());
            }
            if ($key === '' || in_array($key, $bodyRank, true)) {
                throw ConfigError::at($pointer . '.body_rank', 'carrosserie vide ou en double : ' . var_export($body, true));
            }
            $bodyRank[] = $key;
        }

        $peakAge = $r->requireInt('peak_age_years', 1, 30);
        $peakKm = $r->requireInt('peak_mileage_km', 1);

        $w = $r->requireObject('weights');
        $weights = [];
        foreach (VehicleScorer::COMPONENTS as $component) {
            $weights[$component] = $w->requireInt($component, 0, 100);
        }
        $w->done();
        if (array_sum($weights) !== 100) {
            throw ConfigError::at($pointer . '.weights', 'la somme des poids doit faire 100, reçu ' . array_sum($weights));
        }

        $patterns = $r->requireStringList('exclude_patterns', allowEmptyList: true);
        foreach ($patterns as $pattern) {
            if (@preg_match('~' . $pattern . '~u', '') === false) {
                throw ConfigError::at($pointer . '.exclude_patterns', 'expression invalide : ' . $pattern);
            }
        }

        $n = $r->requireObject('notify');
        $notify = new VehicleNotifyPolicy(
            channels: $n->requireStringList('channels'),
            highPriorityScore: $n->requireInt('high_priority_score', 0, 100),
            priceDropMinEur: $n->requireInt('price_drop_min_eur', 0),
            priceDropMinPct: $n->requireFloat('price_drop_min_pct', 0.0, 100.0),
            sourceAlertCooldownHours: $n->optInt('source_alert_cooldown_hours', 12, 1, 720) ?? 12,
        );
        $n->done();
        $r->done();

        return new VehicleCriteria($maxPrice, $prefixes, $bodyRank, $peakAge, $peakKm, $weights, $patterns, $notify);
    }

    /** @return array<string,mixed> */
    private static function decodeObject(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw ConfigError::at(basename($path), 'fichier illisible : ' . $path);
        }
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ConfigError::at(basename($path), 'JSON invalide : ' . $e->getMessage());
        }
        if (!is_array($data)) {
            throw ConfigError::at(basename($path), 'un objet JSON est attendu à la racine');
        }

        return $data;
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $over
     *
     * @return array<string,mixed>
     */
    private static function deepMerge(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            $base[$k] = is_array($v) && is_array($base[$k] ?? null) && !array_is_list($v) ? self::deepMerge($base[$k], $v) : $v;
        }

        return $base;
    }
}
