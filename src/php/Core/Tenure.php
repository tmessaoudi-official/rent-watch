<?php

declare(strict_types=1);

namespace RentWatch\Core;

/**
 * French housing tenure — the core domain concept of this project.
 *
 * Tenure is a property of the LISTING, not of the source. In'li publishes only LLI, but CDC Habitat,
 * Vilogia, Immobilière 3F and Seqens publish social and intermediate stock on the same pages,
 * sometimes in the same result set.
 *
 * `CLAUDE.md` §1 governs this file. The eligible set and the excluded set are methods here, with
 * hard-coded arms and no constructor argument, no setter and no injected table. That is deliberate:
 * the developer is not eligible for social housing, so a social-housing false positive is not a
 * slightly-wrong result — it is a wasted application. There is nothing a YAML file can say that
 * changes the answer.
 */
enum Tenure: string
{
    /** Logement Locatif Intermédiaire (ordonnance 2014-159). The primary target. */
    case LLI = 'LLI';

    /** Private market rate. No cap, no income condition. In scope since the Q4 answer of 2026-08-06. */
    case LIBRE = 'LIBRE';

    /** Prêt Locatif Social. Out of scope — the Q4 answer of 2026-08-06 ruled it social housing. */
    case PLS = 'PLS';

    /** Prêt Locatif à Usage Social. Mainstream social housing. */
    case PLUS = 'PLUS';

    /** Prêt Locatif Aidé d'Intégration. Very-low-income social housing. */
    case PLAI = 'PLAI';

    case ANRU = 'ANRU';
    case ANAH = 'ANAH';

    /** Subsidised regime, absent an explicit intermediate label. */
    case CONVENTIONNE = 'CONVENTIONNE';

    /**
     * Social housing whose financing tier was not determined.
     *
     * This exists because the most reliable discriminator the domain offers is PROCEDURAL, not
     * nominal: a listing demanding a `numéro unique d'enregistrement` or an appearance before a
     * `commission d'attribution` has told us it is allocated through the social channel without
     * ever naming a financing tier. Forcing that into PLAI or PLUS would invent precision the
     * evidence does not support.
     */
    case SOCIAL = 'SOCIAL';

    /**
     * Not determined. The fail-closed landing zone.
     *
     * UNKNOWN is neither eligible nor excluded: it asserts nothing about the listing, it withholds.
     * Its routing is fixed at {@see Outcome::DIGEST} — the low-priority "à vérifier" list.
     */
    case UNKNOWN = 'UNKNOWN';

    /**
     * Is this tenure one the developer must never be shown as a match?
     *
     * Hard-coded on purpose — see the class docblock. `CLAUDE.md` §1: "not user-overridable".
     */
    public function isExcluded(): bool
    {
        return match ($this) {
            self::PLS,
            self::PLUS,
            self::PLAI,
            self::ANRU,
            self::ANAH,
            self::CONVENTIONNE,
            self::SOCIAL => true,
            default => false,
        };
    }

    /** Is this tenure one the developer can actually rent? */
    public function isEligible(): bool
    {
        return match ($this) {
            self::LLI, self::LIBRE => true,
            default => false,
        };
    }

    /**
     * Two verdicts point the same way.
     *
     * {@see self::SOCIAL} is compatible with any excluded tenure: a procedural tell corroborating an
     * explicit PLAI label is agreement, not contradiction, because SOCIAL is the family and PLAI is
     * a member of it.
     */
    public function agreesWith(self $other): bool
    {
        if ($this === $other) {
            return true;
        }

        if ($this === self::SOCIAL) {
            return $other->isExcluded();
        }

        if ($other === self::SOCIAL) {
            return $this->isExcluded();
        }

        return false;
    }
}
