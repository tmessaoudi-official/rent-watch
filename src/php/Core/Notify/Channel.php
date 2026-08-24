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

    /**
     * Can this channel reach a human who is NOT at this machine?
     *
     * The question `Notifier::delivered()` asks, and therefore the question behind
     * `markNotified()`, the 24 h alert cooldown, the heartbeat marker and `test-notify`'s exit
     * code. It is a CAPABILITY rather than a name, and that distinction has now cost two review
     * rounds in a row.
     *
     * Round 7 found `console` satisfying every one of those gates: a print to a container log
     * counted as a delivery, so a transient ntfy outage announced a flat to a log, wrote
     * `notified_as = 'MATCH'` and suppressed it for ever. The fix filtered `console` out BY NAME —
     * and round 8 found the same hole one door along: `email` over `SMTP_TRANSPORT=file` writes
     * `.eml` to a directory the container destroys on rebuild, and it is not called `console`, so
     * it voted. `test-notify` — the documented proof that a deployed image can reach the user —
     * returned 0 for a message that went to a file.
     *
     * So the property lives here, on the interface, once. A name filter answers "is it this one";
     * this answers the thing actually being asked.
     */
    public function reachesRecipient(): bool;

    /**
     * What this channel is, for `doctor`. NEVER a credential.
     *
     * On the interface because `doctor` needs it polymorphically. `EmailChannel` had this method
     * with a docblock saying "For `doctor`" for as long as it existed, and `doctor` could not call
     * it — the method was not on the interface and nothing referenced it. It is the one diagnostic
     * that would have shown a file transport standing in for a real one.
     */
    public function describe(): string;
}
