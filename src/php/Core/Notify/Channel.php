<?php

declare(strict_types=1);

namespace RentWatch\Core\Notify;

/**
 * Somewhere a notification can be delivered.
 *
 * TWO RULES, both from the Q9/Q28 rulings, and both about the same thing — an unsent notification
 * is the one failure this project cannot tolerate quietly:
 *
 * 1. **{@see send()} throws on failure. It never returns silently.** A channel that swallows a
 *    delivery error is the notification-layer form of `except Exception: return []` (hard rule 3):
 *    the run looks successful, the listing is marked notified, and the flat is gone.
 * 2. **{@see check()} runs before any send**, so a channel enabled without its credential is caught
 *    at startup rather than at the moment a match finally arrives — which could be days later, on
 *    the one listing that mattered.
 */
interface Channel
{
    /** Name as it appears in `config/criteria.json` under `notify.channels`. */
    public function name(): string;

    /**
     * Is this channel usable right now?
     *
     * Returns `null` when it is, or a human-readable reason when it is not — *"NTFY_TOPIC is not
     * set"*, not `false`. The caller prints it, and a boolean would make the message someone else's
     * problem to invent.
     */
    public function check(): ?string;

    /** @throws ChannelError on any delivery failure */
    public function send(Notification $notification): void;
}
