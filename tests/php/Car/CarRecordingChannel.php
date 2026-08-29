<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use Scout\Core\Notify\Channel;
use Scout\Core\Notify\Notification;

/** A channel that keeps what it was sent and counts as delivered — the car tests' notification spy. */
final class CarRecordingChannel implements Channel
{
    /** @var list<Notification> */
    public array $sent = [];

    public function name(): string
    {
        return 'recording';
    }

    public function check(): ?string
    {
        return null;
    }

    public function send(Notification $notification): void
    {
        $this->sent[] = $notification;
    }

    public function reachesRecipient(): bool
    {
        return true;
    }

    public function describe(): string
    {
        return 'recording channel';
    }
}
