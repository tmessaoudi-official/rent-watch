<?php

declare(strict_types=1);

namespace Scout\Core\Notify;

/**
 * How loudly to deliver something.
 *
 * Three levels, not five. The point of a priority is that the top one means "look now", and a scale
 * fine enough to argue about is a scale nobody reads. `HIGH` is reserved: a match scoring at or above
 * the configured threshold AND classified confidently (Q31). Everything else is `NORMAL`, and the
 * things the developer should be able to ignore for an hour are `LOW`.
 */
enum Priority: string
{
    case HIGH = 'HIGH';
    case NORMAL = 'NORMAL';
    case LOW = 'LOW';

    /** ntfy's numeric scale, and a reasonable default mapping for anything else. */
    public function ntfyLevel(): int
    {
        return match ($this) {
            self::HIGH => 5,
            self::NORMAL => 3,
            self::LOW => 2,
        };
    }
}
