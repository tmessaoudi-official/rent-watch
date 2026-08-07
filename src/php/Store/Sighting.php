<?php

declare(strict_types=1);

namespace RentWatch\Store;

/**
 * What the store learned by being shown a listing — the answer to "is this worth telling anyone?".
 *
 * Two separate events can make a listing notification-worthy (spec §7): it is new, or its rent has
 * dropped since the last sighting. They are carried as distinct fields rather than one "notify me"
 * boolean because the notification text differs and the priority differs, and because collapsing
 * them here would make the store decide policy that belongs to the criteria layer.
 */
final readonly class Sighting
{
    /**
     * @param string   $dedupKey       the stable key this listing resolved to
     * @param bool     $isNew          the store had never seen this key before
     * @param int|null $rentCc         rent charges comprises as of this sighting; null = unknown
     * @param int|null $previousRentCc last KNOWN rent before this sighting; null = never known
     * @param int|null $rentDeltaCc    signed change in euros, or null when either side is unknown.
     *                                 Null is not zero: an unknown rent is not "no change", and it
     *                                 is certainly not a drop to zero (`CLAUDE.md` hard rule 9).
     * @param bool     $isPriceDrop    the rent is known, was known before, and fell
     */
    public function __construct(
        public string $dedupKey,
        public bool $isNew,
        public ?int $rentCc = null,
        public ?int $previousRentCc = null,
        public ?int $rentDeltaCc = null,
        public bool $isPriceDrop = false,
    ) {}
}
