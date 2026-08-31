<?php

declare(strict_types=1);

namespace Scout\Rent\Adapters;

use Scout\Core\MutableByDesign;

/**
 * How often each CONFIGURED positional pattern found nothing, for the pass just run.
 *
 * **The half of Track 1h that makes the next template change visible instead of silent.** PAP moved
 * its rooms out of the title line on 2026-08-28 and both its positional patterns stopped matching.
 * The ownership rule behaved correctly throughout — a configured pattern that misses yields `null`,
 * never the generic scan, so the subscriber's own search floor was never substituted — but NOTHING
 * COUNTED THE MISSES. So for four days every PAP row stored a null surface and a null rooms, 19 of
 * them were notified as MATCH (a null passes `min_surface_m2` by hard rule 9), and `doctor` said
 * `ok`. It was found by querying the production database, not by any signal the tool emitted.
 *
 * That is hard rule 2's shape one layer in: a source whose CARDS still parse but whose FIELDS have
 * stopped extracting is indistinguishable from a source publishing thin listings — and the count of
 * items, which source health watches, does not move at all.
 *
 * Deliberately NOT persisted. A miss rate is a property of the pass that just ran, and the pattern
 * either matches this template or it does not; there is no rolling mean to keep and no history that
 * would say anything the current pass does not. `SourceStatus::FEED_SILENT` needed the store because
 * it reasons about DATES; this reasons about the payload in hand.
 *
 * Mutable on purpose, held by a `readonly` source as a readonly property: the property cannot be
 * reassigned, the counter it points at can be written. That is the only way to accumulate anything
 * inside a `final readonly` adapter without making the adapter itself mutable.
 */
final class PatternMissLog implements MutableByDesign
{
    /** @var array<string, array{calls: int, misses: int}> */
    private array $counts = [];

    /** One attempt at a configured pattern: `$found` is false when it yielded null or an empty string. */
    public function record(string $key, bool $found): void
    {
        $this->counts[$key] ??= ['calls' => 0, 'misses' => 0];
        ++$this->counts[$key]['calls'];

        if (!$found) {
            ++$this->counts[$key]['misses'];
        }
    }

    /** Starts a pass clean, so a count never spans two fetches. */
    public function reset(): void
    {
        $this->counts = [];
    }

    /** @return array<string, array{calls: int, misses: int}> */
    public function counts(): array
    {
        return $this->counts;
    }

    /**
     * The patterns that found NOTHING AT ALL this pass, over enough cards to mean it.
     *
     * **100% of at least three**, and both halves of that are the point. A pattern that misses on
     * SOME cards is ordinary — a portal's own copy varies, and `commune_pattern` legitimately finds
     * nothing on a card that states no commune. A pattern that misses on EVERY card is a template
     * change, which is the F1 signature and the thing worth a channel. The floor of three stops a
     * quiet pass (one alert, one card) from crying wolf on a source that is working fine.
     *
     * @return list<string> the configured keys that missed everywhere, sorted for a stable message
     */
    public function total(int $floor = 3): array
    {
        $out = [];

        foreach ($this->counts as $key => $c) {
            if ($c['calls'] >= $floor && $c['misses'] === $c['calls']) {
                $out[] = $key;
            }
        }

        sort($out);

        return $out;
    }
}
