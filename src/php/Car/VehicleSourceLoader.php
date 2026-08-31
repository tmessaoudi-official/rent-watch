<?php

declare(strict_types=1);

namespace Scout\Car;

use Scout\Config\ConfigError;
use Scout\Config\Reader;

/**
 * `config/car/sources.json`, strictly. Every configured pattern is compile-checked at load —
 * a pattern that cannot compile would match nothing and read as a quiet portal (hard rule 2 with
 * an alibi) — and every key an adapter could never act on is refused rather than ignored.
 */
final class VehicleSourceLoader
{
    private const array TYPES = ['email_alert', 'sitemap_jsonld', 'fixture'];
    private const array FAMILIES = ['portal', 'dealer', 'auction'];
    private const array PATTERN_PARAMS = ['subject_pattern', 'price_pattern', 'facts_pattern', 'title_pattern', 'make_model_pattern', 'seller_pattern', 'postcode_pattern'];

    /**
     * Of those, the ones NO vehicle adapter reads — measured, not assumed
     * (`grep -rn "'<key>'" src/php/Car/` finds this file and nothing else).
     *
     * Kept as its own list rather than removed from `PATTERN_PARAMS`, so the day an adapter learns
     * to read one the regex check is already there and only this line moves.
     */
    private const array UNREAD_PARAMS = ['seller_pattern', 'postcode_pattern'];

    /** @return array<string, VehicleSourceDefinition> */
    public static function load(string $path): array
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

        return self::fromArray($data, basename($path));
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string, VehicleSourceDefinition>
     */
    public static function fromArray(array $data, string $pointer = 'car/sources.json'): array
    {
        $root = new Reader($pointer, $data);
        $sources = $root->requireObject('sources');
        $out = [];

        foreach ($sources->keys() as $name) {
            if (str_starts_with($name, '_')) {
                continue;
            }
            $where = $pointer . '.sources.' . $name;
            $r = $sources->requireObject($name);

            $enabled = $r->requireBool('enabled');
            $family = $r->requireString('family', self::FAMILIES);
            $type = $r->requireString('type', self::TYPES);
            $feedSilentDays = $r->optInt('feed_silent_days', null);
            if ($feedSilentDays !== null) {
                if ($type !== 'email_alert') {
                    throw ConfigError::at($where . '.feed_silent_days', 'seule une source email_alert rapporte une date de flux');
                }
                if ($feedSilentDays < 1) {
                    throw ConfigError::at($where . '.feed_silent_days', 'doit valoir au moins 1 jour');
                }
            }
            $url = $r->optString('url', null);
            $itemUrlPattern = $r->optString('item_url_pattern', null);
            $lotBudget = $r->optInt('lot_budget_per_pass', 50, 1, 5000) ?? 50;
            $rateLimit = $r->optInt('rate_limit_ms', 2000, 0, 600000) ?? 2000;
            $fixture = $r->optString('fixture', null);

            $params = [];
            $p = $r->optObject('params');
            if ($p !== null) {
                foreach ($p->keys() as $key) {
                    $params[$key] = $p->requireString($key, allowEmpty: true);
                }
                $p->done();
            }
            foreach (self::PATTERN_PARAMS as $key) {
                if (isset($params[$key]) && $params[$key] !== '' && @preg_match($params[$key], '') === false) {
                    throw ConfigError::at($where . '.params.' . $key, 'expression régulière invalide — elle ne correspondrait jamais à rien sans le dire');
                }
            }

            // DECLARED, COMPILE-CHECKED, AND READ BY NOBODY — refused rather than accepted (Track 1c).
            //
            // These are in `PATTERN_PARAMS`, so a config carrying one loads cleanly, passes its
            // regex check, and then does absolutely nothing: `grep -rn "'seller_pattern'"
            // src/php/Car/` finds this file and no adapter. That is the inert-parameter defect the
            // rent side already paid for — `title_pattern` sat unread on every non-segmented email
            // source until 2026-08-26, which made `exclude_title_patterns` unreachable there.
            //
            // `title_pattern` LEFT this list on 2026-08-31, when `VehicleEmailSource` learned to
            // read it against the subject for leboncoin — which is the discharge this comment asks
            // for, performed rather than deferred.
            //
            // Refusing costs nothing today (neither shipped source configures one) and it is the
            // only way the next person to reach for one finds out. When an adapter learns to read
            // one, delete it from this list in the same change.
            foreach (self::UNREAD_PARAMS as $key) {
                if (isset($params[$key]) && $params[$key] !== '') {
                    throw ConfigError::at(
                        $where . '.params.' . $key,
                        $key . ' est déclaré mais AUCUN adaptateur véhicule ne le lit : le configurer '
                            . 'ne ferait rien du tout, en silence. Faites-le lire par un adaptateur '
                            . 'et retirez-le de VehicleSourceLoader::UNREAD_PARAMS dans le même changement',
                    );
                }
            }

            $map = [];
            $m = $r->optObject('map');
            if ($m !== null) {
                foreach ($m->keys() as $key) {
                    $map[$key] = $m->requireString($key);
                }
                $m->done();
            }

            // A VALUE, not a pattern, so `PATTERN_PARAMS` cannot check it — and a typo here is
            // silent in the worst way: `make_model_source: "titre"` would fall to the `link`
            // branch, match nothing, and leave every listing with a null make, which scores 0 on
            // the brand component rather than reading as a fault.
            if (isset($params['make_model_source']) && !in_array($params['make_model_source'], ['link', 'title'], true)) {
                throw ConfigError::at(
                    $where . '.params.make_model_source',
                    'attendu « link » ou « title », reçu « ' . $params['make_model_source'] . ' »',
                );
            }
            if (isset($params['make_model_source']) && !isset($params['make_model_pattern'])) {
                throw ConfigError::at(
                    $where . '.params.make_model_source',
                    'nomme la source d\'un motif qui n\'existe pas — ajoutez make_model_pattern ou retirez cette clé',
                );
            }

            if ($type === 'email_alert') {
                if ($enabled && ($params['from'] ?? '') === '') {
                    throw ConfigError::at($where . '.params.from', 'une source email_alert activée doit nommer son expéditeur — la boîte sert plusieurs portails');
                }
                if ($enabled && ($params['link_host'] ?? '') === '') {
                    throw ConfigError::at($where . '.params.link_host', 'une source email_alert activée doit nommer l\'hôte de ses liens d\'annonce');
                }
            }
            if ($type === 'sitemap_jsonld') {
                if ($url === null) {
                    throw ConfigError::at($where . '.url', 'une source sitemap_jsonld nomme son sitemap');
                }
                if ($itemUrlPattern === null || @preg_match($itemUrlPattern, '') === false) {
                    throw ConfigError::at($where . '.item_url_pattern', 'une source sitemap_jsonld nomme le motif de ses pages de lot, avec un groupe capturant l\'identifiant');
                }
                foreach (['ref', 'price'] as $required) {
                    if (($map[$required] ?? '') === '') {
                        throw ConfigError::at($where . '.map.' . $required, 'obligatoire pour une source sitemap_jsonld');
                    }
                }
            }
            if ($type === 'fixture' && $fixture === null) {
                throw ConfigError::at($where . '.fixture', 'une source fixture nomme son fichier');
            }
            $r->done();

            $out[$name] = new VehicleSourceDefinition(
                name: $name, enabled: $enabled, family: $family, type: $type, params: $params, url: $url,
                itemUrlPattern: $itemUrlPattern, map: $map, lotBudgetPerPass: $lotBudget, rateLimitMs: $rateLimit,
                feedSilentDays: $feedSilentDays, fixture: $fixture,
            );
        }
        $sources->done();
        $root->done();

        return $out;
    }
}
