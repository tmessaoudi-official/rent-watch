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

    /** When set, every send() throws — the channel-down half of a delivery-gated guarantee. */
    public bool $down = false;

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
        if ($this->down) {
            throw new \RuntimeException('canal indisponible');
        }
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
