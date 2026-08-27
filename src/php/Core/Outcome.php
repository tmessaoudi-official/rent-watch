<?php

declare(strict_types=1);

namespace Scout\Core;

/**
 * What the pipeline does with a classified listing.
 *
 * The rest of the codebase branches on THIS, not on the tenure and the confidence separately —
 * so the fail-closed rule is applied in exactly one place ({@see TenureClassifier}) and cannot be
 * re-derived slightly differently by a caller.
 */
enum Outcome: string
{
    /** Eligible and confident enough. Goes to the track's notification channel. */
    case MATCH = 'MATCH';

    /** Undetermined. Goes to the low-priority "à vérifier" digest, and only there. */
    case DIGEST = 'DIGEST';

    /** Tenure is in the excluded set. Dropped, logged only — a hard disqualifier, never scored. */
    case REJECT = 'REJECT';
}
