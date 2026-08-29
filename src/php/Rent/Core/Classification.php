<?php

declare(strict_types=1);

namespace Scout\Rent\Core;

/**
 * The classifier's verdict on one listing.
 *
 * CONFIDENCE IS AN INTEGER, 0..100, and only becomes a float at the boundary. The spec talks about
 * `confidence: 0..1`, and {@see confidence()} honours that — but the arithmetic underneath is exact.
 * Floating-point accumulation is not guaranteed bit-identical between two language runtimes, and
 * this project runs the same classifier twice (PHP and phorj) over one shared corpus specifically so
 * the two can be diffed. A verdict that differs in the last bit would make that diff useless.
 */
final readonly class Classification
{
    /**
     * @param int                $confidenceBp 0..100, in whole points
     * @param list<TenureSignal> $signals      every signal that fired, in the order they were found
     */
    public function __construct(
        public Tenure $tenure,
        public int $confidenceBp,
        public array $signals,
        public Outcome $outcome,
    ) {}

    /** The spec's `0..1` view. */
    public function confidence(): float
    {
        return $this->confidenceBp / 100;
    }

    /**
     * Human-readable justification, highest-priority signal first.
     *
     * These are the `reasons[]` every notification carries (`spec/PROJECT_BRIEF.md` §5).
     *
     * @return list<string>
     */
    public function reasons(): array
    {
        $ordered = $this->signals;
        usort(
            $ordered,
            static fn (TenureSignal $a, TenureSignal $b): int
                => [$a->tier, $a->position] <=> [$b->tier, $b->position],
        );

        return array_map(static fn (TenureSignal $s): string => $s->reason, $ordered);
    }

    /**
     * Stable structure for the cross-language differential test and for `scout dump`.
     *
     * @return array{tenure: string, confidence_bp: int, outcome: string, reasons: list<string>}
     */
    public function toArray(): array
    {
        return [
            'tenure' => $this->tenure->value,
            'confidence_bp' => $this->confidenceBp,
            'outcome' => $this->outcome->value,
            'reasons' => $this->reasons(),
        ];
    }
}
