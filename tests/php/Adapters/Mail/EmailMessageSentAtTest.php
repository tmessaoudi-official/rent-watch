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
