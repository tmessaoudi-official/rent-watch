<?php

declare(strict_types=1);

namespace RentWatch\Tests\Core\Notify;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RentWatch\Core\Notify\ConsoleChannel;
use RentWatch\Core\Notify\Notification;
use RentWatch\Core\Notify\NotificationKind;
use RentWatch\Core\Notify\Priority;

/**
 * What a HIGH-priority notification actually LOOKS LIKE — the last unpinned link in the `!!` chain.
 *
 * The marker was dead by construction from the day it was written: `high_priority_score` was 70 and
 * no listing that clears the tenure-confidence floor has ever scored above 55, so
 * `Priority::HIGH` **has never been produced by a real pass**. Lowering the threshold to 50 on
 * 2026-08-26 arms it, and a branch no fixture reaches is dead safety code until something reaches
 * it — this repo has paid for that twice already (the SeLoger title fallback, the detail-hydration
 * gate).
 *
 * The rest of the chain was already covered and is deliberately not duplicated here: the engine
 * producing `highPriority: true` is `HighPriorityMarkerTest`, and `Formatter::match()` mapping it to
 * `Priority::HIGH` is `NotifyTest::testAHighScoringConfidentMatchIsHighPriority`. What neither
 * touches is the **rendering**, which is the only part the developer ever sees.
 *
 * Both failures here are silent in the same way. A wrong ntfy level means the listing that most
 * deserves attention arrives as an ordinary push — indistinguishable from the other sixty that day.
 * A `!!` that renders like a normal match means the marker is on and invisible, which is worse than
 * it being off, because it reports a discrimination it is not making.
 */
#[CoversClass(ConsoleChannel::class)]
#[CoversClass(Priority::class)]
final class PriorityRenderingTest extends TestCase
{
    public function testTheThreePrioritiesRenderDISTINGUISHABLYOnTheConsole(): void
    {
        $markers = [];

        foreach ([Priority::HIGH, Priority::NORMAL, Priority::LOW] as $priority) {
            $markers[$priority->name] = $this->render($priority);
        }

        self::assertStringStartsWith('!!', $markers['HIGH'], 'the whole point of the marker is that it is visible');

        // THE COUNTERWEIGHT, and it is the half that matters: asserting `!!` alone is satisfied by a
        // channel that prints `!!` in front of EVERY notification, which is the marker switched on
        // and meaningless rather than off.
        self::assertSame(
            3,
            count(array_unique($markers)),
            'three priorities must produce three different prefixes, or the marker discriminates nothing',
        );
        self::assertStringNotContainsString('!!', $markers['NORMAL']);
        self::assertStringNotContainsString('!!', $markers['LOW']);
    }

    /**
     * The ntfy levels, asserted by VALUE.
     *
     * `NtfyChannelWireTest` sends a HIGH notification and asserts only that a `Priority:` header is
     * present — true of every level, so it would pass unchanged if HIGH started sending 3. ntfy's
     * scale is 1–5 and only 4 and 5 bypass a phone's quiet hours, which is the entire behavioural
     * difference the marker is for.
     */
    public function testTheNtfyLevelsAreDistinctAndHighIsActuallyHigh(): void
    {
        self::assertSame(5, Priority::HIGH->ntfyLevel(), 'below 5 the push does not break through');
        self::assertGreaterThan(Priority::NORMAL->ntfyLevel(), Priority::HIGH->ntfyLevel());
        self::assertGreaterThan(Priority::LOW->ntfyLevel(), Priority::NORMAL->ntfyLevel());

        self::assertSame(
            3,
            count(array_unique(array_map(
                static fn (Priority $p): int => $p->ntfyLevel(),
                [Priority::HIGH, Priority::NORMAL, Priority::LOW],
            ))),
            'three priorities collapsing onto two levels loses a distinction silently',
        );
    }

    /**
     * Through the channel's own injectable stream rather than an output buffer: it writes with
     * `fwrite($this->stream, …)` defaulting to `STDOUT`, which `ob_start()` does not capture — a
     * first draft of this test asserted against an empty string and failed for that reason, not
     * because the marker was missing.
     */
    private function render(Priority $priority): string
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);

        (new ConsoleChannel($stream))->send(new Notification(
            NotificationKind::MATCH,
            $priority,
            'T4 a Chatou - 1450 EUR CC',
            ['score 82'],
            'https://example.test/annonce/1',
        ));

        rewind($stream);
        $out = (string) stream_get_contents($stream);
        fclose($stream);

        return $out;
    }
}
