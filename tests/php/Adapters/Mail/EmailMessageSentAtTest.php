<?php

declare(strict_types=1);

namespace Scout\Tests\Adapters\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\Mail\EmailMessage;

/**
 * `EmailMessage::sentAt()` — the message's own `Date`, as a UTC instant, or null.
 *
 * It exists because an email listing carried NO observation time until 2026-08-29, so a portal
 * re-sending yesterday's card was recorded at the PASS time and the store's stale-sighting guard
 * never saw it coming: Bien'ici re-sent one flat a day later at a HIGHER rent, both messages sat in
 * the IMAP window, and every pass recorded "1146 then 1122, a drop" — 429 history rows, 128 emails.
 *
 * STRICT, by round-trip, for the same reason `ImapMailbox` parses the feed date strictly: PHP's
 * relative-expression parser moves a mismatched weekday FORWARD, and a forward-moved date is exactly
 * the wrong direction here (a stale card reading as the newest one).
 */
#[CoversClass(EmailMessage::class)]
final class EmailMessageSentAtTest extends TestCase
{
    public function testAnOrdinaryDateIsAUtcInstant(): void
    {
        self::assertSame('2026-08-26T18:31:09Z', self::message('Wed, 26 Aug 2026 18:31:09 +0000')->sentAt());
        self::assertSame('2026-08-27T11:34:25Z', self::message('Thu, 27 Aug 2026 13:34:25 +0200')->sentAt());
    }

    public function testAMismatchedWeekdayIsNullNotAdvanced(): void
    {
        // 9 August 2026 is a Sunday. Lenient parsing applies `Fri` as a modifier and lands on the 14th.
        self::assertNull(self::message('Fri, 09 Aug 2026 10:00:00 +0000')->sentAt());
    }

    /**
     * A SINGLE-DIGIT DAY IS RFC-LEGAL AND WAS REJECTED, which cost PAP its observation time for
     * every alert sent between the 1st and the 9th of a month.
     *
     * RFC 5322 writes the day as `1*2DIGIT`, so `Wed, 3 Sep 2026` is as valid as `Wed, 03 Sep`.
     * The round-trip that makes this parser strict is what refused it: `createFromFormat('d', …)`
     * accepts `3` and re-formats it as `03`, which is not the string it was given.
     *
     * **Measured in production, 2026-09-05, not imagined.** Every PAP row first seen on 1–5
     * September carries `observedAt = NULL` while every row up to 31 August carries one, and
     * `source_runs.feed_newest_at` for `pap` is frozen at `2026-08-31T15:24:29Z` while the portal
     * delivered alerts on all five days. Two silent failures at once, both hard rule 2's shape: the
     * store's stale-sighting guard has no instant to compare (the 429-history-row defect, disarmed
     * for five days a month), and `FEED_SILENT` reads a feed that is delivering daily as one that
     * stopped on the 31st — an alert nobody can believe.
     */
    public function testASingleDigitDayIsRfcLegalAndParsed(): void
    {
        self::assertSame('2026-09-03T08:23:09Z', self::message('Thu, 3 Sep 2026 10:23:09 +0200')->sentAt());
        self::assertSame('2026-09-03T08:23:09Z', self::message('3 Sep 2026 10:23:09 +0200')->sentAt());
    }

    /** RFC 5322 makes the seconds optional (`hh:mm[:ss]`), and the strictness must not read that as malformed. */
    public function testATimeWithoutSecondsIsParsed(): void
    {
        self::assertSame('2026-09-03T08:23:00Z', self::message('Thu, 3 Sep 2026 10:23 +0200')->sentAt());
        self::assertSame('2026-08-26T08:23:00Z', self::message('Wed, 26 Aug 2026 10:23 +0200')->sentAt());
    }

    /**
     * The counterweight, and it is the whole reason the parser is a round-trip rather than a list
     * of masks: widening the accepted shapes must not weaken the STRICTNESS. A day that disagrees
     * with its weekday is still refused whichever width it is written at, and a day that is not a
     * day at all is still refused.
     */
    public function testWideningTheShapesDidNotWeakenTheStrictness(): void
    {
        // 3 September 2026 is a Thursday, not a Friday.
        self::assertNull(self::message('Fri, 3 Sep 2026 10:23:09 +0200')->sentAt());
        self::assertNull(self::message('Fri, 3 Sep 2026 10:23 +0200')->sentAt());
        // Lenient parsing turns each of these into a real instant. None of them is a Date.
        self::assertNull(self::message('tomorrow')->sentAt());
        self::assertNull(self::message('+2 days')->sentAt());
        self::assertNull(self::message('Thu, 31 Sep 2026 10:23:09 +0200')->sentAt(), 'September has 30 days');
    }

    public function testNoDateIsNull(): void
    {
        $raw = "From: a@portal.test\r\nSubject: x\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nbody\r\n";

        self::assertNull(EmailMessage::parse($raw)->sentAt());
    }

    private static function message(string $date): EmailMessage
    {
        return EmailMessage::parse(
            "From: a@portal.test\r\nSubject: x\r\nDate: {$date}\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nbody\r\n",
        );
    }
}
