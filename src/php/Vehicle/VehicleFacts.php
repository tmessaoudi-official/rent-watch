<?php

declare(strict_types=1);

namespace Scout\Vehicle;

use Scout\Core\MalformedText;
use Scout\Core\Text;

/**
 * The readers every car adapter shares — fuel and gearbox vocabularies, integers with French
 * thousands separators and unit suffixes, the `YYYY-MM` first registration. One implementation, so
 * a source cannot disagree with another about what "Boite de vitesse automatique" means.
 */
final class VehicleFacts
{
    /** essence | diesel | hybride | electrique | gpl | autre — or null when nothing was stated. */
    public static function fuel(?string $raw): ?string
    {
        $key = self::fold($raw);
        if ($key === null) {
            return null;
        }

        return match (true) {
            str_contains($key, 'hybride') || str_contains($key, 'hybrid') => 'hybride',
            str_contains($key, 'electrique') || str_contains($key, 'electric') => 'electrique',
            str_contains($key, 'diesel') || str_contains($key, 'gazole') => 'diesel',
            str_contains($key, 'essence') || str_contains($key, 'bioethanol') || str_contains($key, 'sans plomb') || str_contains($key, 'petrol') => 'essence',
            str_contains($key, 'gpl') || str_contains($key, 'lpg') => 'gpl',
            default => 'autre',
        };
    }

    /**
     * automatique | manuelle — from a stated transmission (`Boite de vitesse automatique`) or from the
     * tokens a French dealer puts in a TITLE (`Auto`, `BVA`, `EDC`, `DSG`, `CVT`, `EAT8`, `DCT` /
     * `BVM`, `Manuelle`). Null when nothing is said: hard rule 9, an unstated gearbox is not manual.
     */
    public static function gearbox(?string $raw): ?string
    {
        $key = self::fold($raw);
        if ($key === null) {
            return null;
        }
        if (preg_match('~\b(?:bvm|manuelle|manual|manuel)\b~', $key) === 1) {
            return 'manuelle';
        }
        if (preg_match('~\b(?:auto|automatique|automatic|bva|edc|dsg|cvt|dct|eat\d?|s-?tronic|steptronic|multitronic|powershift)\b~', $key) === 1) {
            return 'automatique';
        }

        return null;
    }

    /** `136 278 KMT`, `26 000 km`, `21 000€`, `7990` → the integer; null when there are no digits. */
    public static function int(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw;
        }
        if (is_float($raw)) {
            return (int) round($raw);
        }
        if (!is_string($raw)) {
            return null;
        }
        $clean = preg_replace('~[^\d]~u', '', $raw) ?? '';

        return $clean === '' ? null : (int) $clean;
    }

    /** `2015-09` → [2015, 9]; `2015` → [2015, null]; anything else → [null, null]. */
    public static function firstRegistered(?string $raw): array
    {
        if ($raw === null || preg_match('~^\s*(\d{4})(?:-(\d{1,2}))?~', $raw, $m) !== 1) {
            return [null, null];
        }
        $month = isset($m[2]) ? (int) $m[2] : null;

        return [(int) $m[1], $month !== null && $month >= 1 && $month <= 12 ? $month : null];
    }

    public static function fold(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        try {
            return Text::fold(trim($raw));
        } catch (MalformedText) {
            return null;
        }
    }
}
