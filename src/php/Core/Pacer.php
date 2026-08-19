<?php

declare(strict_types=1);

namespace RentWatch\Core;

/**
 * The Q37 pacing ruling (2026-08-07), as the only place in the codebase that knows how fast this
 * tool is allowed to poll.
 *
 * > Poll every 15 minutes ± 5 minutes of jitter. Within a pass: at least 5 s between requests to
 * > distinct hosts, at least 60 s between two requests to the same host, and the source order is
 * > shuffled each pass so no site is always first. A pass finishing in under 60 s does not
 * > immediately start another.
 *
 * WHY THIS IS A CLASS AND NOT THREE `sleep()` CALLS IN THE LOOP. `CLAUDE.md` hard rule 5 forbids
 * CAPTCHA solving, proxy rotation and fingerprint spoofing — which leaves polite rate limiting as
 * the *entire* strategy for not being blocked. It therefore has to be correct, and correctness here
 * is arithmetic over a clock. Every source of time and randomness is injected, so `PacerTest`
 * asserts the whole ruling in microseconds instead of in a quarter of an hour. A pacer that called
 * `time()` and `sleep()` directly would be untestable, and an untestable rule is an unenforced one.
 *
 * The failure this prevents does not look like a failure. A banned IP presents as every source
 * going quiet at the same time — which is exactly what a slow rental market looks like, and exactly
 * the shape hard rule 2 exists to make impossible.
 *
 * NOT thread- or process-safe, and it does not need to be: one `scout --watch` process owns one
 * pacer. Two concurrent watchers would each pace themselves and jointly break the ruling, which is
 * a deployment error, not something this class can defend against.
 *
 * {@see MutableByDesign} — and it clears that interface's high bar rather than borrowing it. A
 * pacer is not a value object: it is a running ledger of when each host was last contacted, and
 * that ledger changing as the pass proceeds IS the mechanism, not an optimisation over an immutable
 * design. Nor is it ever handed to a caller as a result, which is the defect the readonly rule
 * exists to prevent — `--watch` constructs one, uses it, and drops it. A `readonly` variant would
 * have to return a new pacer from every `beforeFetch()`, and a caller that dropped the returned
 * copy would silently un-pace the rest of the pass: an immutability that makes the failure quieter.
 */
final class Pacer implements MutableByDesign
{
    /** 15 minutes, the cadence Q37 chose. */
    public const int PASS_INTERVAL_SECONDS = 900;

    /** ± 5 minutes, so the interval lands in [600, 1200] and no poll is predictable to the second. */
    public const int JITTER_SECONDS = 300;

    /** Minimum gap between two requests, whatever their hosts. */
    public const float DISTINCT_HOST_GAP_SECONDS = 5.0;

    /** Minimum gap between two requests to the SAME host. */
    public const float SAME_HOST_GAP_SECONDS = 60.0;

    /**
     * The ruling's last sentence, kept as an explicit floor. It is redundant against the 600 s lower
     * bound of the jitter band today; it is written down anyway so that a future change to the
     * cadence cannot reintroduce a tight loop by accident. A rule that holds only as a side effect
     * of another number is not enforced by anything.
     */
    public const float MIN_SECONDS_BETWEEN_PASSES = 60.0;

    /** Wall-clock of the most recent request to each host, keyed by lowercased host. */
    /** @var array<string, float> */
    private array $lastByHost = [];

    /** Wall-clock of the most recent request to ANY host. */
    private ?float $lastRequestAt = null;

    /**
     * @param \Closure(): float             $clock   seconds; only differences are ever used
     * @param \Closure(float): void         $sleeper blocks for that many seconds
     * @param \Closure(int, int): int       $rand    inclusive on both bounds, like `random_int`
     */
    public function __construct(
        private readonly \Closure $clock,
        private readonly \Closure $sleeper,
        private readonly \Closure $rand,
    ) {}

    /**
     * The production wiring: a monotonic clock, a real sleep, and a CSPRNG.
     *
     * `hrtime(true)` rather than `microtime(true)` because pacing must survive an NTP step or a DST
     * transition. A wall clock that jumps backwards mid-pass would make an elapsed interval look
     * negative and could collapse the gap between two requests to zero — the one thing this class
     * exists to prevent, triggered by a clock correction nobody would connect to a ban.
     */
    public static function real(): self
    {
        return new self(
            clock: static fn (): float => hrtime(true) / 1_000_000_000.0,
            sleeper: static function (float $seconds): void {
                if ($seconds > 0.0) {
                    usleep((int) round($seconds * 1_000_000.0));
                }
            },
            rand: static fn (int $min, int $max): int => random_int($min, $max),
        );
    }

    /**
     * The pacer's own clock reading.
     *
     * Exposed so that a caller timing a pass uses the SAME time source the pacer will compare it
     * against. `betweenPasses()` subtracts the pass start from this clock; a caller that measured
     * the start with `microtime()` while the pacer ran on `hrtime()` would be subtracting two
     * unrelated epochs, and the resulting interval would be nonsense in whichever direction the
     * epochs happened to differ — most likely an enormous number, i.e. no wait at all.
     */
    public function now(): float
    {
        return ($this->clock)();
    }

    /**
     * Blocks until it is polite to issue this source's request, then records that it happened.
     *
     * `$host` of `null` means the source issues no outbound web request — a fixture read from disk,
     * or an IMAP mailbox, which is one connection to one's own mail provider and not a site that can
     * ban anyone. Such a source neither waits nor consumes the distinct-host slot; pacing it would
     * add dead time to every pass and delay the real requests queued behind it.
     */
    public function beforeFetch(?string $host): void
    {
        if ($host === null || $host === '') {
            return;
        }

        $key = strtolower($host);
        $now = ($this->clock)();

        // The two rules are a maximum, not a sequence. Tracking only "the last request" would let a
        // site be polled twice inside its own 60 s window whenever a different host was polled in
        // between — the naive implementation, and the one an interleaved-hosts test catches.
        $readyAt = $now;
        if ($this->lastRequestAt !== null) {
            $readyAt = max($readyAt, $this->lastRequestAt + self::DISTINCT_HOST_GAP_SECONDS);
        }
        if (isset($this->lastByHost[$key])) {
            $readyAt = max($readyAt, $this->lastByHost[$key] + self::SAME_HOST_GAP_SECONDS);
        }

        $this->sleepUntil($now, $readyAt);

        // Re-read rather than trusting `$readyAt`: the sleeper is injected, and a sleep that returned
        // early (a signal, in production) must not be recorded as time that elapsed.
        $issuedAt = ($this->clock)();
        $this->lastByHost[$key] = $issuedAt;
        $this->lastRequestAt = $issuedAt;
    }

    /**
     * Fisher-Yates over the injected randomness.
     *
     * PHP's `shuffle()` is not seedable, so a test could assert nothing about it beyond "the array
     * still has the same length" — and the property Q37 actually cares about is that no site is
     * reliably first, which is a claim about the distribution, not the length. Being polled first
     * every fifteen minutes is itself a fingerprint.
     *
     * @template T
     * @param  list<T> $items
     * @return list<T>
     */
    public function shuffle(array $items): array
    {
        $n = count($items);
        for ($i = $n - 1; $i > 0; $i--) {
            $j = ($this->rand)(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return array_values($items);
    }

    /**
     * Blocks until the next pass may begin.
     *
     * The interval is measured from the START of the previous pass, not its end, so the cadence is
     * the cadence the developer asked for rather than "the cadence plus however long the sources
     * took". A pass that overran the whole interval starts the next one at once — minus the floor
     * below, which never permits an immediate restart.
     */
    public function betweenPasses(float $passStartedAt): void
    {
        $interval = (float) ($this->rand)(
            self::PASS_INTERVAL_SECONDS - self::JITTER_SECONDS,
            self::PASS_INTERVAL_SECONDS + self::JITTER_SECONDS,
        );

        $now = ($this->clock)();
        $readyAt = max($passStartedAt + $interval, $now + self::MIN_SECONDS_BETWEEN_PASSES);

        $this->sleepUntil($now, $readyAt);
    }

    /** Never asks for a negative sleep; a clock that moved past the target is simply late. */
    private function sleepUntil(float $now, float $readyAt): void
    {
        $wait = $readyAt - $now;
        if ($wait > 0.0) {
            ($this->sleeper)($wait);
        }
    }
}
