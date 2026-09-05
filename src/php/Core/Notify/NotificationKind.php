<?php

declare(strict_types=1);

namespace Scout\Core\Notify;

/**
 * What kind of event a notification carries.
 *
 * An enum rather than a string so that a channel adding a per-kind rule — a different sound, a
 * different mailbox — has to handle every kind or fail to compile. A string would let a new kind be
 * silently dropped by every `match` that predates it.
 */
enum NotificationKind: string
{
    /** A new listing that passed every hard filter. */
    case MATCH = 'MATCH';

    /** A listing already seen whose rent fell — or which crossed a disqualifier boundary (Q33). */
    case PRICE_DROP = 'PRICE_DROP';

    /** A source is not healthy. Every alerting `SourceStatus`, not just BROKEN (Q29). */
    case SOURCE_HEALTH = 'SOURCE_HEALTH';

    /** A source that was alerting is `OK` again. Without this, a fix has no confirmation. */
    case SOURCE_RECOVERED = 'SOURCE_RECOVERED';

    /** The "à vérifier" rollup — the fail-closed rule's only landing zone (Q34). */
    case DIGEST = 'DIGEST';

    /**
     * A5 (2026-09-05): settled matches held back by `push_min_score`, rolled up once a day. NOT a
     * digest — the rent digest means tenure doubt and the car domain has no digest at all — so a
     * car rollup carries its own kind, and a channel that files DIGEST as "à vérifier" cannot
     * mistake it for one.
     */
    case ROLLUP = 'ROLLUP';

    /**
     * "I am still running."
     *
     * The one notification that is sent when nothing happened, and the reason it exists (Q27): a
     * dead watcher and a quiet rental market both emit nothing, so silence is only a signal if
     * something breaks it on a schedule.
     */
    case HEARTBEAT = 'HEARTBEAT';
}
