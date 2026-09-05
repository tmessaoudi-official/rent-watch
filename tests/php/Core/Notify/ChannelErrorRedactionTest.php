<?php

declare(strict_types=1);

namespace Scout\Tests\Core\Notify;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Core\Notify\ChannelError;

/**
 * WHERE THE CREDENTIAL GUARANTEE ACTUALLY LIVES — hard rule 7, pinned at its single funnel.
 *
 * A channel failure carries the URL it failed on, and an ntfy topic, an SMTP DSN or a Telegram bot
 * token lives in that URL. Every CLI writes those messages to stderr, which under `compose.yaml` is
 * the container log.
 *
 * A C2 round-7 lens reported three `RentScout` sites as unredacted, `CarScout` having redacted at
 * all four of its own — a true reading of the syntax attached to a leak that does not exist, which
 * is this repo's named failure shape. `ChannelError::__construct()` redacts the message it is built
 * with, and `Notifier::send()` returns nothing but `ChannelError` (its `\Throwable` arm WRAPS into
 * one). The call sites redact again as defence in depth; this is the guarantee.
 *
 * It had no test. Deleting the constructor's `Redact::text()` left the whole suite green.
 */
#[CoversClass(ChannelError::class)]
final class ChannelErrorRedactionTest extends TestCase
{
    public function testTheMessageIsRedactedAtConstruction(): void
    {
        $e = new ChannelError('ntfy', 'POST https://ntfy.sh/scout-9f2ac?auth=tk_abc123 a répondu 401');

        self::assertStringContainsString('401', $e->getMessage(), 'the diagnosis survives');
        self::assertStringNotContainsString('tk_abc123', $e->getMessage());
        self::assertStringNotContainsString('scout-9f2ac', $e->getMessage(), 'the topic addresses the channel');
    }

    public function testAnSmtpDsnAndABotTokenAreMaskedToo(): void
    {
        $smtp = new ChannelError('email', 'smtp://scout%40gmail.com:hunter2@smtp.gmail.com:587 refusé');
        self::assertStringNotContainsString('hunter2', $smtp->getMessage());
        self::assertStringContainsString('smtp.gmail.com:587', $smtp->getMessage(), 'the host is the diagnosis');

        $tg = new ChannelError('telegram', 'https://api.telegram.org/bot7712345:AAH-secret-token/sendMessage 403');
        self::assertStringNotContainsString('AAH-secret-token', $tg->getMessage());
        self::assertStringContainsString('403', $tg->getMessage());
    }

    /** The channel NAME is not a secret and must survive: it is how an operator knows which one failed. */
    public function testTheChannelNameLeadsTheMessage(): void
    {
        self::assertStringStartsWith('ntfy: ', (new ChannelError('ntfy', 'HTTP 503'))->getMessage());
    }
}
