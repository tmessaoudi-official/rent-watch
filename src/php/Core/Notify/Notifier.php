<?php

declare(strict_types=1);

namespace RentWatch\Core\Notify;

/**
 * Fans one notification out to every usable channel, and reports honestly when it cannot.
 *
 * This is where the Q28 ruling lives — **refusals are scoped, not global** — and the reasoning is
 * worth keeping next to the code. Q9 originally said a channel enabled without its credential is a
 * startup refusal, which is right about the danger (a silent no-op loses a flat) and wrong about the
 * blast radius: under Q8 the process is headless on a VPS, so an expired SMTP password would take
 * down the sources, the seen-set and the price history to punish one channel, and would announce it
 * to a terminal nobody is attached to.
 *
 * So:
 *
 * - **No channel usable → refuse to start.** There is genuinely nowhere for a match to go.
 * - **Some channel usable → start, disable the broken one, and say so THROUGH a working one.**
 * - **A send fails at runtime → the caller is told which channels failed**, so it can leave
 *   `notified_at` NULL and retry next run. Q9 covered only startup, which left the hole where a
 *   failed delivery silently marks a listing as notified.
 *
 * `console` deliberately does not count toward "a channel is usable" — under Docker it is the
 * container log, which is not a notification channel for anyone.
 */
final readonly class Notifier
{
    /** @var list<Channel> */
    private array $usable;

    /** @var array<string, string> channel name → why it is unusable */
    private array $disabled;

    /**
     * Built into locals and assigned ONCE, rather than appended to in place. A readonly property
     * cannot be appended to after initialisation, and that constraint is the point: the set of
     * usable channels is decided at startup and must not shift underneath a run, or a listing could
     * be judged delivered against a channel list that no longer holds.
     *
     * @param list<Channel> $channels
     */
    public function __construct(private array $channels)
    {
        $usable = [];
        $disabled = [];

        foreach ($channels as $channel) {
            $problem = $channel->check();
            if ($problem === null) {
                $usable[] = $channel;
            } else {
                $disabled[$channel->name()] = $problem;
            }
        }

        $this->usable = $usable;
        $this->disabled = $disabled;
    }

    /**
     * Why the process must not start, or `null` if it may.
     *
     * Returns a reason only when NOTHING can deliver. A partially-broken notifier still runs, and
     * {@see disabledReport()} is what makes the broken part visible rather than forgotten.
     */
    public function fatalProblem(): ?string
    {
        if ($this->usable !== []) {
            return null;
        }

        if ($this->channels === []) {
            return 'no notification channel is enabled — nothing would ever be delivered. '
                . 'Set `notify.channels` in config/criteria.json';
        }

        $lines = [];
        foreach ($this->disabled as $name => $problem) {
            $lines[] = '  - ' . $name . ': ' . $problem;
        }

        return "no notification channel is usable, so a match would be found and never delivered:\n"
            . implode("\n", $lines);
    }

    /**
     * Channels that were configured and cannot be used. Empty when all are healthy.
     *
     * @return array<string, string>
     */
    public function disabledReport(): array
    {
        return $this->disabled;
    }

    /** Does anything here actually reach the developer when they are not at a terminal? */
    public function hasRemoteChannel(): bool
    {
        foreach ($this->usable as $channel) {
            if ($channel->name() !== 'console') {
                return true;
            }
        }

        return false;
    }

    /**
     * Deliver to every usable channel.
     *
     * **Does not stop at the first failure.** A partial outage must not cost the delivery that would
     * have succeeded, so every channel is attempted and the failures are collected.
     *
     * @return list<ChannelError> empty when every channel accepted it
     */
    public function send(Notification $notification): array
    {
        $failures = [];

        foreach ($this->usable as $channel) {
            try {
                $channel->send($notification);
            } catch (ChannelError $e) {
                $failures[] = $e;
            } catch (\Throwable $e) {
                // A channel throwing something unexpected is still a delivery failure, and it must
                // not escape and abort the run — the next channel might have worked, and the caller
                // needs to know this listing was not delivered so it can retry.
                $failures[] = new ChannelError($channel->name(), $e->getMessage(), $e);
            }
        }

        return $failures;
    }

    /**
     * Was the notification delivered to at least one channel?
     *
     * The question the caller actually needs before marking a listing notified. "Every channel
     * succeeded" would be too strict — one working channel means the developer heard about it — and
     * "no exception escaped" would be too loose, which is the hole Q28 closes.
     *
     * @param list<ChannelError> $failures
     */
    public function delivered(array $failures): bool
    {
        return count($failures) < count($this->usable) && $this->usable !== [];
    }
}
