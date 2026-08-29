<?php

declare(strict_types=1);

namespace Scout\Car;

use Scout\Rent\Notify\Formatter;
use Scout\Core\Notify\Notification;
use Scout\Core\Notify\NotificationKind;
use Scout\Core\Notify\Priority;
use Scout\Core\SourceHealth;

/**
 * What a car push says. The SOURCE leads every title (developer ruling, 2026-08-29), then score,
 * make/model/year, mileage and price — the facts a phone shows first. Health and recovery notices
 * are the rent formatter's, unchanged: a broken source is a broken source in either domain.
 */
final readonly class VehicleFormatter
{
    public function __construct(private Formatter $shared = new Formatter()) {}

    public function match(VehicleListing $car, VehicleVerdict $verdict): Notification
    {
        return new Notification(
            kind: NotificationKind::MATCH,
            priority: $verdict->highPriority ? Priority::HIGH : Priority::NORMAL,
            title: $this->headline($car, $verdict->score),
            reasons: $verdict->reasons,
            url: $car->url,
            score: $verdict->score,
            sourceName: $car->sourceName,
        );
    }

    public function priceDrop(VehicleListing $car, int $previousEur, int $currentEur): Notification
    {
        $delta = $previousEur - $currentEur;
        $pct = $previousEur > 0 ? round($delta * 100 / $previousEur, 1) : 0.0;

        return new Notification(
            kind: NotificationKind::RENT_DROP,
            priority: Priority::NORMAL,
            title: $car->sourceName . ' · Baisse de prix — ' . self::name($car) . ' · ' . self::eur($currentEur),
            reasons: [
                self::eur($previousEur) . ' → ' . self::eur($currentEur) . ' (−' . self::eur($delta) . ', −'
                    . rtrim(rtrim(number_format($pct, 1, ',', ' '), '0'), ',') . ' %)',
            ],
            url: $car->url,
            sourceName: $car->sourceName,
        );
    }

    public function sourceHealth(SourceHealth $health): Notification
    {
        return $this->shared->sourceHealth($health);
    }

    public function sourceRecovered(SourceHealth $health): Notification
    {
        return $this->shared->sourceRecovered($health);
    }

    /** @param list<SourceHealth> $health */
    public function heartbeat(int $runs, int $matches, array $health, string $sinceIso): Notification
    {
        $n = $this->shared->heartbeat($runs, $matches, $health, $sinceIso);

        return new Notification(
            kind: $n->kind,
            priority: $n->priority,
            title: 'car-watch tourne — ' . $matches . ' correspondance(s) depuis ' . $sinceIso,
            reasons: $n->reasons,
        );
    }

    /** `paruvendu · 78/100 — Renault Austral 2023 · 26 000 km · 21 000 €` */
    private function headline(VehicleListing $car, ?int $score): string
    {
        $parts = [self::name($car)];
        if ($car->mileageKm !== null) {
            $parts[] = number_format($car->mileageKm, 0, ',', ' ') . ' km';
        }
        if ($car->priceEur !== null) {
            $parts[] = self::eur($car->priceEur);
        }
        $headline = implode(' · ', $parts);

        return $car->sourceName . ' · ' . ($score === null ? $headline : $score . '/100 — ' . $headline);
    }

    private static function name(VehicleListing $car): string
    {
        $name = trim(($car->make === null ? '' : ucfirst($car->make) . ' ') . ($car->model === null ? '' : ucfirst($car->model)));
        if ($name === '') {
            $name = trim($car->title) !== '' ? trim($car->title) : 'véhicule';
        }

        return $car->year === null ? $name : $name . ' ' . $car->year;
    }

    private static function eur(int $v): string
    {
        return number_format($v, 0, ',', ' ') . ' €';
    }
}
