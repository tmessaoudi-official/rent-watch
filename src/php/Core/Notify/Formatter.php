<?php

declare(strict_types=1);

namespace RentWatch\Core\Notify;

use RentWatch\Core\Department;
use RentWatch\Core\RawListing;
use RentWatch\Core\SourceHealth;
use RentWatch\Core\SourceStatus;
use RentWatch\Core\Verdict;

/**
 * Turns pipeline results into {@see Notification}s.
 *
 * ONE PLACE, on purpose. The rendered notification is this product's only user-facing output — there
 * is no web UI by design — so if two call sites each built their own, the two would drift and the
 * developer would learn to distrust whichever looked wrong.
 *
 * The rendering rule that matters: **a notification must be readable on a phone lock screen.** That
 * is not a style preference, it is where these are actually read, and it is why the title carries
 * the decision-relevant facts (commune, rent, size) rather than the source's own headline, which is
 * usually marketing.
 */
final readonly class Formatter
{
    public function match(RawListing $listing, Verdict $verdict, array $duplicates = []): Notification
    {
        $reasons = $verdict->reasons;

        // Context first, because it answers "where is this?" before "why did it match?". Everything
        // on it was ALREADY extracted and simply never displayed, which is why it costs no request
        // and no new parsing. Omitted entirely when nothing is known — a line reading `· ·` would
        // be worse than its absence.
        $facts = $this->factsLine($listing);
        if ($facts !== null) {
            array_unshift($reasons, $facts);
        }

        if ($duplicates !== []) {
            // Shown rather than dropped. Knowing the same flat is on two portals is actionable — it
            // is a second application route — and a silently discarded duplicate is
            // indistinguishable from a listing that was never fetched.
            $reasons[] = 'également publié sur : ' . implode(', ', $duplicates);
        }

        return new Notification(
            kind: NotificationKind::MATCH,
            priority: $verdict->highPriority ? Priority::HIGH : Priority::NORMAL,
            title: $this->headline($listing, $verdict->score),
            reasons: $reasons,
            url: $listing->url,
            score: $verdict->score,
            sourceName: $listing->sourceName,
        );
    }

    /**
     * A rent that fell, or a listing that crossed back under a hard limit.
     *
     * `$nowQualifies` is what makes the Q33 ruling visible in the output rather than only in the
     * routing: a listing that was over the ceiling and is now under it is a NEW MATCH, not a price
     * tweak, and saying so is the difference between a glance and an application.
     */
    public function rentDrop(RawListing $listing, int $previousCc, int $currentCc, bool $nowQualifies): Notification
    {
        $delta = $previousCc - $currentCc;
        $pct = $previousCc > 0 ? round($delta * 100 / $previousCc, 1) : 0.0;

        return new Notification(
            kind: NotificationKind::RENT_DROP,
            priority: $nowQualifies ? Priority::HIGH : Priority::NORMAL,
            title: ($nowQualifies ? 'PASSE SOUS LE PLAFOND' : 'Baisse de loyer')
                . ' — ' . ($listing->commune ?? 'commune inconnue')
                . ' · ' . $currentCc . ' € CC',
            reasons: array_values(array_filter([
                $previousCc . ' € → ' . $currentCc . ' € CC (−' . $delta . ' €, −'
                    . rtrim(rtrim(number_format($pct, 1, ',', ' '), '0'), ',') . ' %)',
                $nowQualifies
                    ? 'ce bien était écarté sur le loyer ; il est désormais dans le budget'
                    : null,
                $this->sizeLine($listing),
            ])),
            url: $listing->url,
            sourceName: $listing->sourceName,
        );
    }

    /**
     * A source is not healthy.
     *
     * Covers EVERY alerting status, not `BROKEN` alone (ruled 2026-08-07, Q29). `NEVER_PRODUCED` was
     * added precisely because it hid behind `OK`, and `STALE` is what catches the schedule itself
     * having stopped — deriving those and never sending them would waste both.
     */
    public function sourceHealth(SourceHealth $health): Notification
    {
        return new Notification(
            kind: NotificationKind::SOURCE_HEALTH,
            // Not LOW. A broken source is indistinguishable from a quiet market, so the alert is the
            // only thing standing between the developer and weeks of believing nothing is available.
            priority: $health->status === SourceStatus::BROKEN ? Priority::NORMAL : Priority::LOW,
            title: 'Source ' . $health->sourceName . ' : ' . $health->status->value,
            reasons: array_values(array_filter([
                $health->detail,
                $health->lastSuccessAt === null
                    ? 'aucune exécution réussie enregistrée'
                    : 'dernier succès : ' . $health->lastSuccessAt,
            ])),
            sourceName: $health->sourceName,
        );
    }

    public function sourceRecovered(SourceHealth $health): Notification
    {
        return new Notification(
            kind: NotificationKind::SOURCE_RECOVERED,
            priority: Priority::LOW,
            title: 'Source ' . $health->sourceName . ' : rétablie',
            reasons: ['dernier relevé : ' . $health->lastCount . ' annonce(s)'],
            sourceName: $health->sourceName,
        );
    }

    /**
     * The "à vérifier" rollup.
     *
     * One notification for the whole batch rather than one per listing — that is what makes it a
     * digest rather than a second notification stream, and it is the reason the fail-closed rule can
     * afford to send doubtful listings here at all.
     *
     * @param list<array{listing: RawListing, verdict: Verdict}> $entries
     */
    public function digest(array $entries): Notification
    {
        $lines = [];
        foreach ($entries as $entry) {
            $listing = $entry['listing'];
            $lines[] = '• ' . $this->headline($listing, null)
                . ($entry['verdict']->reasons === [] ? '' : ' — ' . $entry['verdict']->reasons[0]);
        }

        return new Notification(
            kind: NotificationKind::DIGEST,
            priority: Priority::LOW,
            title: 'À vérifier : ' . count($entries) . ' annonce(s) au régime indéterminé',
            reasons: $lines,
        );
    }

    /**
     * Proof of life.
     *
     * @param list<SourceHealth> $health
     */
    public function heartbeat(int $runs, int $matches, array $health, string $sinceIso): Notification
    {
        $notOk = array_values(array_filter($health, static fn (SourceHealth $h): bool => $h->status->isAlerting()));

        return new Notification(
            kind: NotificationKind::HEARTBEAT,
            priority: Priority::LOW,
            title: 'rent-watch tourne — ' . $matches . ' correspondance(s) depuis ' . $sinceIso,
            reasons: array_values(array_filter([
                $runs . ' exécution(s), ' . count($health) . ' source(s) actives',
                $notOk === []
                    ? 'toutes les sources sont OK'
                    : count($notOk) . ' source(s) en alerte : ' . implode(', ', array_map(
                        static fn (SourceHealth $h): string => $h->sourceName . ' (' . $h->status->value . ')',
                        $notOk,
                    )),
            ])),
        );
    }

    /**
     * The line a phone shows before anything is expanded.
     *
     * Commune, size and rent, in that order, because those are what decide whether the developer
     * opens it. The source's own title is NOT used: it is written to sell, so it leads with
     * adjectives and buries the facts.
     */
    private function headline(RawListing $listing, ?int $score): string
    {
        // Commune AND postcode, since 2026-08-22. The commune alone was ambiguous — three of the
        // eight Île-de-France departements have a Neuilly — and on a source that ships no title
        // (In'li does not) the headline was the only text in the whole notification, so a bare
        // commune left the reader with a price and a link.
        $where = $listing->commune ?? '';
        $postcode = trim((string) $listing->postcode);
        if ($postcode !== '') {
            $where = $where === '' ? $postcode : $where . ' ' . $postcode;
        }

        $parts = [$where === '' ? 'commune inconnue' : $where];

        $size = $this->sizeLine($listing);
        if ($size !== null) {
            $parts[] = $size;
        }

        $rent = $listing->effectiveRentCc();
        if ($rent !== null) {
            $parts[] = $rent . ' € CC';
        } elseif ($listing->rentHc !== null) {
            // Flagged, never silently shown as if it were comparable to the budget.
            $parts[] = $listing->rentHc . ' € HC';
        }

        $headline = implode(' · ', $parts);

        return $score === null ? $headline : $score . '/100 — ' . $headline;
    }

    /**
     * Departement, floor and lift — whichever of them the source actually said.
     *
     * Hard rule 9 governs every entry. `floor === 0` is RDC and REAL, not absent: a ground-floor
     * flat that silently loses its floor is the display-layer twin of one rejected for not stating
     * it. And an UNMENTIONED lift is not an absent one — `null` says nothing, `false` says there is
     * none — because "sans ascenseur" invented about a building nobody described is exactly the
     * kind of fabrication that makes someone skip a flat that has one.
     */
    private function factsLine(RawListing $listing): ?string
    {
        $bits = [];

        $department = Department::label($listing->postcode);
        if ($department !== null) {
            $bits[] = $department;
        }

        if ($listing->floor !== null) {
            $bits[] = match (true) {
                $listing->floor <= 0 => 'RDC',
                $listing->floor === 1 => '1er étage',
                default => $listing->floor . 'e étage',
            };
        }

        if ($listing->hasElevator !== null) {
            $bits[] = $listing->hasElevator ? 'avec ascenseur' : 'sans ascenseur';
        }

        return $bits === [] ? null : implode(' · ', $bits);
    }

    private function sizeLine(RawListing $listing): ?string
    {
        $bits = [];
        if ($listing->rooms !== null) {
            $bits[] = 'T' . $listing->rooms;
        }
        if ($listing->surfaceM2 !== null) {
            $bits[] = rtrim(rtrim(number_format($listing->surfaceM2, 1, ',', ' '), '0'), ',') . ' m²';
        }

        return $bits === [] ? null : implode(' ', $bits);
    }
}
