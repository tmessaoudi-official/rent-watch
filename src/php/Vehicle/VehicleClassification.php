<?php

declare(strict_types=1);

namespace Scout\Vehicle;

/** The classifier's answer: pushed or rejected, and in the reader's words why. */
final readonly class VehicleClassification
{
    /** @param list<string> $reasons */
    public function __construct(
        public VehicleOutcome $outcome,
        public array $reasons,
    ) {}

    /** @return array{outcome: string, reasons: list<string>} */
    public function toArray(): array
    {
        return ['outcome' => $this->outcome->value, 'reasons' => $this->reasons];
    }
}
