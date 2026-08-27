<?php

declare(strict_types=1);

namespace Scout\Tests\Support;

use Scout\Core\Notify\Channel;
use Scout\Core\Notify\Notification;

/**
 * A channel that COUNTS as a delivery and always succeeds, for CLI tests that need one.
 *
 * It exists because there is no offline configuration that can play this part. The four CLI test
 * classes used `email` over `SMTP_TRANSPORT=file` as their "remote" channel for one review round,
 * and a file transport cannot reach anyone — so every assertion about a listing being marked
 * notified passed for the reason that was itself the round-8 P0. Injecting a double is honest
 * about being a double; configuring a file transport looked like a real channel and was not.
 *
 * Injected through `Scout`'s constructor seam. Tests about CHANNEL BUILDING — an unknown name, a
 * missing credential, the console-only warning — must NOT use this, or they stop exercising the
 * thing they are about.
 */
final class DeliveringChannel implements Channel
{
    /** @var list<Notification> */
    public array $sent = [];

    public function name(): string
    {
        return 'ntfy';
    }

    public function check(): ?string
    {
        return null;
    }

    public function reachesRecipient(): bool
    {
        return true;
    }

    public function describe(): string
    {
        return 'double de test';
    }

    public function send(Notification $notification): void
    {
        $this->sent[] = $notification;
    }
}
