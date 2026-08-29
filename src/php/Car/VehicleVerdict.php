<?php

declare(strict_types=1);

namespace Scout\Car;

/** Rejected, or matched with a 0–100 score and the reasons a phone can show. */
final readonly class VehicleVerdict
{
    /** @param list<string> $reasons */
    private function __construct(
        public VehicleOutcome $outcome,
        public ?int $score,
        public array $reasons,
        public bool $highPriority,
    ) {}

    /** @param list<string> $reasons */
    public static function rejected(array $reasons): self
    {
        return new self(VehicleOutcome::REJECT, null, $reasons, false);
    }

    /** @param list<string> $reasons */
    public static function matched(int $score, array $reasons, bool $highPriority): self
    {
        return new self(VehicleOutcome::MATCH, $score, $reasons, $highPriority);
    }
}
