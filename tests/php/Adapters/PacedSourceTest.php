<?php

declare(strict_types=1);

namespace RentWatch\Tests\Adapters;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RentWatch\Adapters\PacedSource;
use RentWatch\Adapters\Source;
use RentWatch\Adapters\SourceError;
use RentWatch\Core\Pacer;
use RentWatch\Core\SourceHealth;
use RentWatch\Core\SourceProfile;
use RentWatch\Core\SourceStatus;
use RentWatch\Core\Tenure;

/**
 * `PacedSource` is the seam that puts Q37 between the run loop and the network without teaching the
 * pipeline about time.
 *
 * The decorator shape was chosen over injecting the pacer into `Pipeline` because `Pipeline` also
 * serves `--once`, where there is no cadence at all — a timing-aware pipeline would need a "do not
 * actually pace" mode, and a safety control with an off switch is the kind that stays off.
 *
 * These tests assert what is OBSERVABLE — where the sleep lands relative to the request — rather
 * than spying on calls into `Pacer`. That is deliberate: a call-count test passes just as happily
 * when the pacer is invoked with the wrong host, or after the request instead of before it. The
 * sleeps and their ordering are the behaviour the ruling is actually about.
 *
 * Two properties matter more than the delegation, and both are about failure:
 *
 * 1. The wait happens BEFORE the request, never after. A decorator that slept afterwards would fire
 *    its first two requests back to back and only then start behaving.
 * 2. A fetch that THROWS still consumes the host's window. The packet left the machine; the host
 *    saw it. Not charging for it lets a failing source be retried inside its own window — hammering
 *    a site at the exact moment it is most likely to be rate-limiting on purpose.
 */
#[CoversClass(PacedSource::class)]
final class PacedSourceTest extends TestCase
{
    public function testTheWaitLandsBeforeTheSecondRequestNotAfterTheFirst(): void
    {
        $log = new CallLog();
        $paced = new PacedSource(new SpySource('a', 'example.test', $log), self::pacer($log));

        $paced->fetch();
        $paced->fetch();

        self::assertSame(['fetch:a', 'sleep:60', 'fetch:a'], $log->entries);
    }

    /**
     * The host reaches the pacer intact. Proven by the CONSEQUENCE rather than by a spy: two
     * distinct hosts are separated by 5 s and the same host by 60 s, so the interval itself reports
     * which host the pacer believed it was contacting.
     */
    public function testTheInnerSourcesHostIsWhatThePacerSees(): void
    {
        $log = new CallLog();
        $pacer = self::pacer($log);
        $a = new PacedSource(new SpySource('a', 'alpha.test', $log), $pacer);
        $b = new PacedSource(new SpySource('b', 'beta.test', $log), $pacer);

        $a->fetch();
        $b->fetch();

        self::assertSame(['fetch:a', 'sleep:5', 'fetch:b'], $log->entries);
    }

    public function testTheSameHostBehindTwoDifferentSourcesSharesOneWindow(): void
    {
        // The whole reason Q37 is worded in hosts and not in sources. Two adapters on one landlord's
        // domain must not each get a private 60 s window — that is the polling burst that gets an IP
        // banned, and `CLAUDE.md` hard rule 5 leaves polite pacing as the entire defence.
        $log = new CallLog();
        $pacer = self::pacer($log);
        $one = new PacedSource(new SpySource('one', 'shared.test', $log), $pacer);
        $two = new PacedSource(new SpySource('two', 'shared.test', $log), $pacer);

        $one->fetch();
        $two->fetch();

        self::assertSame(['fetch:one', 'sleep:60', 'fetch:two'], $log->entries);
    }

    public function testAHostlessSourceIsNeverDelayed(): void
    {
        // The decorator does not decide what "no host" means — `Pacer` owns that rule, in one place.
        // A decorator that skipped the pacer itself would duplicate the policy and let the two drift.
        $log = new CallLog();
        $pacer = self::pacer($log);
        $m = new PacedSource(new SpySource('mail', null, $log), $pacer);

        $m->fetch();
        $m->fetch();

        self::assertSame(['fetch:mail', 'fetch:mail'], $log->entries);
    }

    public function testAHostlessSourceDoesNotConsumeTheDistinctHostSlot(): void
    {
        $log = new CallLog();
        $pacer = self::pacer($log);
        $web = new PacedSource(new SpySource('web', 'alpha.test', $log), $pacer);
        $mail = new PacedSource(new SpySource('mail', null, $log), $pacer);
        $web2 = new PacedSource(new SpySource('web2', 'beta.test', $log), $pacer);

        $web->fetch();
        $mail->fetch();
        $web2->fetch();

        // Still exactly one 5 s gap: the mailbox neither waited nor pushed beta.test further out.
        self::assertSame(['fetch:web', 'fetch:mail', 'sleep:5', 'fetch:web2'], $log->entries);
    }

    /**
     * Hard rule 3 in decorator form: the wrapper must not convert a `SourceError` into an empty
     * list. A decorator is exactly where that regression hides, because it reads as defensive
     * programming rather than as the silent-failure bug it is.
     */
    public function testAFetchFailurePropagatesAndIsNotSwallowed(): void
    {
        $log = new CallLog();
        $paced = new PacedSource(
            new SpySource('boom', 'example.test', $log, new SourceError('boom', 'HTTP 503')),
            self::pacer($log),
        );

        $this->expectException(SourceError::class);
        $paced->fetch();
    }

    public function testAFailedRequestStillConsumesTheHostWindow(): void
    {
        $log = new CallLog();
        $paced = new PacedSource(
            new SpySource('boom', 'example.test', $log, new SourceError('boom', 'HTTP 503')),
            self::pacer($log),
        );

        foreach ([1, 2] as $ignored) {
            try {
                $paced->fetch();
            } catch (SourceError) {
                // deliberately retried — that is the behaviour under test
            }
        }

        self::assertSame(['fetch:boom', 'sleep:60', 'fetch:boom'], $log->entries);
    }

    public function testEveryOtherContractMethodDelegatesUntouched(): void
    {
        $log = new CallLog();
        $inner = new SpySource('deleg', 'example.test', $log);
        $paced = new PacedSource($inner, self::pacer($log));

        self::assertSame('deleg', $paced->name());
        self::assertSame('private', $paced->family());
        self::assertSame(Tenure::LLI, $paced->defaultTenure());
        self::assertSame('example.test', $paced->host());
        self::assertSame('deleg', $paced->profile()->name);
        self::assertSame(SourceStatus::OK, $paced->health('2026-08-19T00:00:00Z')->status);
    }

    /**
     * `health()`'s clock is the one argument that must not be dropped. Without `$nowIso` the store
     * cannot derive `STALE`, and `STALE` is the verdict that catches the schedule itself having
     * stopped — the failure `--watch` is most likely to suffer. A decorator that quietly failed to
     * forward it would disable that verdict for every paced source with the suite still green.
     */
    public function testHealthForwardsTheClockRatherThanDroppingIt(): void
    {
        $log = new CallLog();
        $inner = new SpySource('clock', 'example.test', $log);

        (new PacedSource($inner, self::pacer($log)))->health('2026-08-19T12:00:00Z');

        self::assertSame('2026-08-19T12:00:00Z', $inner->healthCalledWith);
    }

    /** A pacer whose sleeps are recorded into the same log the fetches write to, so order is visible. */
    private static function pacer(CallLog $log): Pacer
    {
        $now = 0.0;

        return new Pacer(
            clock: static function () use (&$now): float {
                return $now;
            },
            sleeper: static function (float $seconds) use ($log, &$now): void {
                $log->entries[] = 'sleep:' . (int) round($seconds);
                $now += $seconds;
            },
            rand: static fn (int $min, int $max): int => $min,
        );
    }
}

/** Shared by reference through object identity — PHP has no reference-typed promoted properties. */
final class CallLog
{
    /** @var list<string> */
    public array $entries = [];
}

/** Records WHEN it was fetched, because "before or after the wait" is the property under test. */
final class SpySource implements Source
{
    public ?string $healthCalledWith = null;

    public function __construct(
        private readonly string $sourceName,
        private readonly ?string $sourceHost,
        private readonly CallLog $log,
        private readonly ?\Throwable $throw = null,
    ) {}

    public function name(): string
    {
        return $this->sourceName;
    }

    public function host(): ?string
    {
        return $this->sourceHost;
    }

    public function family(): string
    {
        return 'private';
    }

    public function defaultTenure(): ?Tenure
    {
        return Tenure::LLI;
    }

    public function profile(): SourceProfile
    {
        return new SourceProfile($this->sourceName, 'private', Tenure::LLI, false);
    }

    public function fetch(): array
    {
        $this->log->entries[] = 'fetch:' . $this->sourceName;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return [];
    }

    public function health(?string $nowIso = null): SourceHealth
    {
        $this->healthCalledWith = $nowIso;

        return new SourceHealth($this->sourceName, SourceStatus::OK);
    }
}
