<?php

declare(strict_types=1);

namespace Scout\Tests\Support;

use Scout\Core\Notify\Channel;
use Scout\Core\Notify\ChannelError;
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

    /**
     * Kinds this channel refuses to deliver, so a test can exercise the DELIVERY-FAILED branch.
     *
     * A recipient-reaching channel that throws is the state Q27's refusal note is most likely to
     * meet — the commonest startup refusal is a channel misconfiguration — and until round 5 nothing
     * could reach that branch at all.
     *
     * @var list<\Scout\Core\Notify\NotificationKind>
     */
    public array $refuses = [];

    public function send(Notification $notification): void
    {
        if (\in_array($notification->kind, $this->refuses, true)) {
            throw new ChannelError('ntfy: HTTP 503 depuis ntfy.sh');
        }

        $this->sent[] = $notification;
    }
}
