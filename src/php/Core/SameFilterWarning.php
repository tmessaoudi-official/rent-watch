<?php

declare(strict_types=1);

namespace Scout\Core;

/**
 * EVERY CARD OF ONE SOURCE FAILING THE SAME HARD FILTER IS A SIGNAL, and nothing read it before
 * row 41 (2026-09-05).
 *
 * The C2 round-5 P2: with no band on the mapped path, a selector drifting onto a 5-digit field
 * extracts `95240` cleanly — so `PatternMissLog` counts no miss (the extraction SUCCEEDED),
 * `max_rent_cc` then rejects every card, `item_count` does not move, no run fails, and health
 * stays `ok`. The ParuVendu `autres` class one layer over: a capture that succeeds without meaning
 * anything. The honest generic instrument is the SHAPE of the rejections — one source, one filter,
 * every card — with no band and no magic number: a real market never fails one filter on 100 % of
 * a source's cards pass after pass, and when it does the operator should know within one pass.
 *
 * ONE implementation for both domains (the rule `PatternMissLog::escalate()` already carries):
 * the rent pipeline hands it `Verdict::$disqualifier`, the car pipeline the first reject reason,
 * and both read the same `warnings()`.
 *
 * Deliberately NOT counted: a §1 rejection (`tenure: PLS`) or a vehicle-set exclusion
 * (`exclu : …`). Those are the classifier WORKING; a source whose every card is social housing
 * is a source the rules exist to refuse, not a drifted selector.
 */
final class SameFilterWarning
{
    /** Fewer judged cards than this says nothing — one bad card is one bad card. */
    public const int FLOOR = 3;

    /**
     * The filter a disqualifier names, with its numbers normalised so `rent: 1450 € CC > 1200` and
     * `rent: 1500 € CC > 1200` are one key — or `null` when the rejection is not a filter's.
     */
    public static function keyOf(?string $disqualifier): ?string
    {
        if ($disqualifier === null || trim($disqualifier) === '') {
            return null;
        }
        $lower = mb_strtolower($disqualifier);
        // The classifier working, on either domain — never a drifted selector.
        if (str_starts_with($lower, 'tenure:') || str_starts_with($lower, 'exclu :') || str_starts_with($lower, 'exclu:')) {
            return null;
        }

        return trim((string) preg_replace('~\d+(?:[.,]\d+)?~u', 'N', $lower));
    }

    /**
     * @param array<string, array{judged: int, by: array<string, int>}> $tally per source: how many
     *                                                                        cards were judged, and how
     *                                                                        many each filter key rejected
     *
     * @return list<string>
     */
    public static function warnings(array $tally, int $floor = self::FLOOR): array
    {
        $out = [];
        foreach ($tally as $source => $t) {
            if ($t['judged'] < $floor) {
                continue;
            }
            foreach ($t['by'] as $key => $count) {
                if ($count === $t['judged']) {
                    $out[] = sprintf(
                        '%s : %d annonce(s) sur %d écartée(s) par le MÊME filtre (%s) — un sélecteur a-t-il dérivé sur ce champ ?',
                        $source,
                        $count,
                        $t['judged'],
                        $key,
                    );
                }
            }
        }

        return $out;
    }

    /**
     * Count one judged card into the tally — a convenience so both pipelines write the same shape.
     *
     * @param array<string, array{judged: int, by: array<string, int>}> $tally
     */
    public static function count(array &$tally, string $source, ?string $disqualifier): void
    {
        $tally[$source] ??= ['judged' => 0, 'by' => []];
        ++$tally[$source]['judged'];
        $key = self::keyOf($disqualifier);
        if ($key !== null) {
            $tally[$source]['by'][$key] = ($tally[$source]['by'][$key] ?? 0) + 1;
        }
    }
}
