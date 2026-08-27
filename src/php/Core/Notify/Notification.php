<?php

declare(strict_types=1);

namespace Scout\Core\Notify;

/**
 * One thing to tell the developer, in a shape every channel can render.
 *
 * Deliberately NOT a listing. Four different events reach the same channels — a match, a rent drop,
 * a source-health alert, a heartbeat — and giving each its own path is how one of them ends up
 * unroutable. `spec/PROJECT_BRIEF.md` §5 requires every notification to carry its `score` and a
 * human-readable `reasons[]`; a health alert has neither, which is exactly why they are nullable
 * here rather than assumed.
 */
final readonly class Notification
{
    /**
     * @param list<string> $reasons why this is worth the developer's attention, best first
     * @param int|null     $score   0–100 for a match; `null` for anything that is not one
     */
    public function __construct(
        public NotificationKind $kind,
        public Priority $priority,
        public string $title,
        public array $reasons = [],
        public ?string $url = null,
        public ?int $score = null,
        public ?string $sourceName = null,
        public ?string $dedupKey = null,
    ) {}
}
