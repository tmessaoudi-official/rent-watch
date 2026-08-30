<?php

declare(strict_types=1);

namespace Scout\Core\Notify;

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
 * - **No channel usable → refuse to start.** There is genuinely nowhere for a match to go. Note
 *   `console` counts as *usable* for this question and not for {@see delivered()} — the two sets
 *   are `$usable` and `$counting`, and they differ by exactly that channel.
 * - **Some channel usable → start, disable the broken one, and say so THROUGH a working one.**
 * - **A send fails at runtime → the caller is told which channels failed**, so it can leave
 *   `notified_at` NULL and retry next run. Q9 covered only startup, which left the hole where a
 *   failed delivery silently marks a listing as notified.
 *
 * `console` deliberately does not count toward "a channel is usable" — under Docker it is the
 * container log, which is not a notification channel for anyone.
 *
 * More precisely: `console` cannot REACH ANYONE, and neither can `email` over a file transport.
 * The question is a capability, not a name — see {@see Channel::reachesRecipient()}.
 *
 * **That last sentence was prose for a long time and the constructor did not implement it.**
 * `ConsoleChannel::check()` returns `null`, so console landed in `$usable`, and `delivered()`
 * asked whether fewer channels failed than were usable — so ONE console print satisfied every
 * "did it reach the user" gate in the tree: `markNotified()`, the 24 h alert cooldown, the
 * heartbeat marker and `test-notify`'s exit code. A transient ntfy outage therefore announced a
 * flat to a log, wrote `notified_as = 'MATCH'`, and suppressed it permanently once the network
 * returned. Hence {@see $counting}: console is still SENT to, it just does not vote.
 */
final readonly class Notifier
{
    /** @var list<Channel> */
    private array $usable;

    /**
     * The usable channels that can actually reach a human who is not at a terminal.
     *
     * `$usable` is the send list; this is the QUORUM. Keeping them as two sets rather than
     * filtering at each call site is deliberate — the bug this fixes was one call site out of five
     * asking the wrong question.
     *
     * Membership is {@see Channel::reachesRecipient()}, a CAPABILITY, and it replaced a filter on
     * the literal name `console` after that filter lasted exactly one review round: `email` over
     * `SMTP_TRANSPORT=file` writes `.eml` to a directory the container destroys on rebuild, is not
     * called `console`, and so voted. Two mechanisms answering one question is the shape this file
     * already carries a scar from; there is now one.
     *
     * @var list<Channel>
     */
    private array $counting;

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
        $this->counting = array_values(array_filter(
            $usable,
            static fn (Channel $c): bool => $c->reachesRecipient(),
        ));
        $this->disabled = $disabled;
    }

    /**
     * Why the process must not start, or `null` if it may.
     *
     * Returns a reason only when NOTHING can deliver. A partially-broken notifier still runs, and
     * {@see disabledReport()} is what makes the broken part visible rather than forgotten.
     *
     * **Console-only is deliberately NOT fatal here, and that is a narrower rule than it looks.**
     * `console` cannot DELIVER — {@see delivered()} says so, and that is what the round-7 P0 was
     * about — but it is a legitimate running state: `scout run --once` at a terminal is exactly
     * that, and `doctor` has reported `console seulement` since Q28. Refusing to start would take
     * a working local run away to punish a deployment mistake. What a console-only run does
     * instead is mark nothing notified and report every announcement as undelivered, every pass,
     * which is loud and correct — and `run` warns at startup on `!hasRemoteChannel()`, pinned by
     * ScoutTest::testAConsoleOnlyRunWarnsThatNothingWillBeMarkedNotified.
     */
    public function fatalProblem(): ?string
    {
        if ($this->usable !== []) {
            return null;
        }

        if ($this->channels === []) {
            return 'no notification channel is enabled — nothing would ever be delivered. '
                . 'Set `notify.channels` in config/<domain>/criteria.json';
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

    /**
     * Every usable channel, for `doctor`: name, what it is, and whether it COUNTS.
     *
     * This is the diagnostic that would have shown a file transport standing in for a real
     * channel. `EmailChannel::describe()` existed with a docblock saying "For `doctor`" for as
     * long as the class did, and `doctor` could not call it — the method was not on the interface
     * and nothing in the tree referenced it. Round 8 found the dead method and the P0 it would
     * have exposed in the same pass.
     *
     * @return list<array{name: string, describe: string, counts: bool}>
     */
    public function inventory(): array
    {
        $rows = [];
        foreach ($this->usable as $channel) {
            $rows[] = [
                'name' => $channel->name(),
                'describe' => $channel->describe(),
                'counts' => $channel->reachesRecipient(),
            ];
        }

        return $rows;
    }

    /** Does anything here actually reach the developer when they are not at a terminal? */
    public function hasRemoteChannel(): bool
    {
        return $this->counting !== [];
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
     * It asks {@see $counting}, not `$usable`, and it asks BY NAME rather than by arithmetic. The
     * arithmetic form (`count($failures) < count($usable)`) was what let a console print stand in
     * for a delivery; comparing names says what is meant, which is that some channel capable of
     * reaching a human accepted this notification.
     *
     * @param list<ChannelError> $failures
     */
    public function delivered(array $failures): bool
    {
        if ($this->counting === []) {
            return false;
        }

        $failedNames = [];
        foreach ($failures as $failure) {
            $failedNames[$failure->channelName] = true;
        }

        foreach ($this->counting as $channel) {
            if (!isset($failedNames[$channel->name()])) {
                return true;
            }
        }

        return false;
    }
}
