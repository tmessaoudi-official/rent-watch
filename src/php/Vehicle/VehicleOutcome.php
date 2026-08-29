<?php

declare(strict_types=1);

namespace Scout\Vehicle;

/** What becomes of a car: pushed, or rejected — there is no mixed-stock digest in this domain. */
enum VehicleOutcome: string
{
    case MATCH = 'MATCH';
    case REJECT = 'REJECT';
}
