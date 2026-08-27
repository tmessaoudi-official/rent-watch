<?php

declare(strict_types=1);

namespace Scout\Core\Notify;

/**
 * Writes notifications to a stream. Always available, never a substitute for a real channel.
 *
 * The Q28 ruling is explicit about the second half: under Docker on a VPS, `console` is the
 * container log, which nobody is watching — so it does NOT count as a DELIVERY. It is here because
 * a channel that always works is what makes `scout run --once` demonstrable, not because it
 * delivers.
 *
 * Precisely which gate that is matters, and this sentence used to name the wrong one. It is
 * {@see Notifier::delivered()} — the question `markNotified()`, the 24 h alert cooldown, the
 * heartbeat marker and `test-notify`'s exit code all ask. It is NOT the startup refusal: a
 * console-only process still starts, because `run --once` at a terminal is that process and
 * refusing would take a working local run away to punish a deployment mistake. What it cannot do
 * is mark anything notified.
 */
final readonly class ConsoleChannel implements Channel
{
    /**
     * Typed `mixed` because PHP has no `resource` type declaration and a readonly property must be
     * typed. The project requires every class under `src/php/` to be `final readonly`, and a stream
     * handle is not a reason to opt out of that — it is assigned once and never reassigned.
     *
     * @var resource
     */
    private mixed $stream;

    /** @param resource|null $stream */
    public function __construct(mixed $stream = null)
    {
        $this->stream = $stream ?? STDOUT;
    }

    public function name(): string
    {
        return 'console';
    }

    /**
     * NO. Under Q8 the process is headless on a VPS and this is the container log.
     *
     * It is here because a channel that always works is what makes `scout run --once`
     * demonstrable — not because it delivers.
     */
    public function reachesRecipient(): bool
    {
        return false;
    }

    public function describe(): string
    {
        return 'sortie standard (sous Docker : le journal du conteneur)';
    }

    public function check(): ?string
    {
        return null;
    }

    public function send(Notification $n): void
    {
        $marker = match ($n->priority) {
            Priority::HIGH => '!!',
            Priority::NORMAL => ' >',
            Priority::LOW => '  ',
        };

        $out = $marker . ' [' . $n->kind->value . '] ' . $n->title . "\n";
        foreach ($n->reasons as $reason) {
            $out .= '      · ' . $reason . "\n";
        }
        if ($n->url !== null) {
            $out .= '      ' . $n->url . "\n";
        }

        if (@fwrite($this->stream, $out) === false) {
            // Even here. A channel that swallows a write failure is the notification-layer form of
            // `except Exception: return []` — the run reports success and the listing is marked
            // notified while nothing was delivered.
            throw new ChannelError($this->name(), 'could not write to the output stream');
        }
    }
}
