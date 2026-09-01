<?php

declare(strict_types=1);

namespace Scout\Core;

/**
 * A source that counts how often its own CONFIGURED extractions found nothing.
 *
 * **The read side of the one-funnel discipline.** `PatternMissLog` made the counting single; this
 * makes the READING single. Without it every CLI gates its miss report on `instanceof
 * EmailAlertSource`, and the day a second adapter learns to count, the report has to remember to
 * name it too — which is the same "one more place to forget" that put the counting itself on one
 * adapter of five (F27) while the finding it was built for was about exactly that failure.
 *
 * Deliberately in `Scout\Core` and deliberately not in either domain's namespace: a portal changing
 * its template is not a housing fact or a vehicle fact, and the rent and car CLIs must be able to
 * print the same thing without either one owning it.
 *
 * Implementing this is a promise that {@see PatternMissLog::reset()} is called at the START of
 * every fetch. A log that accumulates across passes reports a miss rate for a template that has
 * since been fixed, which is worse than reporting nothing — it sends someone to look at a capture
 * that is fine.
 */
interface CountsPatternMisses
{
    /** The per-pattern miss counts of the LAST fetch. Never a running total. */
    public function patternMisses(): PatternMissLog;
}
