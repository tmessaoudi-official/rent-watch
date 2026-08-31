<?php

declare(strict_types=1);

namespace Scout\Tests\Core\Notify;

use PHPUnit\Framework\TestCase;
use Scout\Core\Notify\Notification;
use Scout\Core\Notify\NotificationKind;
use Scout\Core\Notify\NtfyChannel;
use Scout\Core\Notify\Priority;

/**
 * `NtfyChannel` against a real socket, answered by the scripted HTTP responder on loopback.
 *
 * This exists because nothing had ever executed the channel's real curl path under the suite —
 * which is exactly how a deprecated `curl_close()` sat latent in it after the identical call was
 * found and removed in `CurlHttpClient` (round-2 panel finding, full-set-coverage rule). With
 * `failOnDeprecation` on, this test also fails the suite if a deprecated call returns to the path.
 */
final class NtfyChannelWireTest extends TestCase
{
    public function testADeliveryPutsTheTopicTitleAndHonestIdentityOnTheWire(): void
    {
        $transcriptPath = sys_get_temp_dir() . '/rentwatch-ntfy-transcript-' . bin2hex(random_bytes(6)) . '.txt';

        $proc = proc_open(
            [PHP_BINARY, __DIR__ . '/../../Adapters/scripted-http-server.php', $transcriptPath],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($proc, 'the scripted HTTP server must start');

        try {
            $name = fgets($pipes[1], 256);
            self::assertIsString($name, 'the server must report the port it chose');
            $port = (int) substr(strrchr(trim($name), ':') ?: ':0', 1);
            self::assertGreaterThan(0, $port);

            $channel = new NtfyChannel('secret-topic-1234', 'http://127.0.0.1:' . $port, 5);
            $channel->send(new Notification(
                NotificationKind::MATCH,
                Priority::HIGH,
                'T4 a Chatou - 1450 EUR CC',
                ['score 82', 'ascenseur'],
                'https://example.test/annonce/1',
            ));
        } finally {
            foreach ($pipes as $pipe) {
                @fclose($pipe);
            }
            proc_close($proc);
        }

        $wire = (string) @file_get_contents($transcriptPath);
        @unlink($transcriptPath);

        self::assertStringContainsString('POST /secret-topic-1234 HTTP/1.1', $wire, 'the topic is the path');
        self::assertStringContainsString('Title: T4 a Chatou - 1450 EUR CC', $wire);
        // BY VALUE, not merely present. `Priority:` alone is true of every level, so hardcoding the
        // wire literal — `'Priority: ' . $n->priority->ntfyLevel()` in NtfyChannel — to a 3 survived
        // this assertion AND `PriorityRenderingTest`, which pins the enum rather than this call
        // site. A correct rule covering one of its two surfaces, inside the test that names the
        // failure class. ntfy's scale is 1–5 and only 4 and 5 break through a phone's quiet hours,
        // which is the entire behavioural difference the `!!` marker buys — and the notification
        // sent above is `Priority::HIGH`.
        self::assertStringContainsString('Priority: 5', $wire, 'a HIGH push must actually go out as HIGH');
        self::assertStringContainsString('User-Agent: scout', $wire, 'hard rule 5 holds on this path too');
        self::assertStringNotContainsStringIgnoringCase('mozilla', $wire);
        self::assertStringContainsString('https://example.test/annonce/1', $wire, 'the body carries the link');
    }

    /**
     * THE DOMAIN BADGE LEADS THE TITLE AND IS ALSO A TAG (developer request, 2026-08-31).
     *
     * Both domains push to ntfy and both titles begin with a source name, so on a phone
     * `seloger · 44/100 — …` and `paruvendu · 78/100 — …` are told apart only by reading them.
     * FIRST is the load-bearing part: a notification title is truncated at the END, so the front is
     * the one position that always survives.
     *
     * Asserted on the WIRE rather than on a getter, because the title a phone shows is the header
     * this channel writes — and the tag is what ntfy can filter on, so it is checked too.
     */
    public function testTheDomainBadgeLeadsTheTitleAndIsAlsoATag(): void
    {
        $transcriptPath = sys_get_temp_dir() . '/rentwatch-ntfy-badge-' . bin2hex(random_bytes(6)) . '.txt';

        $proc = proc_open(
            [PHP_BINARY, __DIR__ . '/../../Adapters/scripted-http-server.php', $transcriptPath],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($proc, 'the scripted HTTP server must start');

        try {
            $name = fgets($pipes[1], 256);
            self::assertIsString($name);
            $port = (int) substr(strrchr(trim($name), ':') ?: ':0', 1);

            (new NtfyChannel('t', 'http://127.0.0.1:' . $port, 5, badge: '\u{1F3E0} RENT \u{B7}', badgeTag: 'house'))
                ->send(new Notification(
                    NotificationKind::MATCH,
                    Priority::HIGH,
                    'seloger \u{B7} 44/100 — Sartrouville',
                    ['score 44'],
                    null,
                ));
        } finally {
            foreach ($pipes as $pipe) {
                @fclose($pipe);
            }
            proc_close($proc);
        }

        $wire = (string) @file_get_contents($transcriptPath);
        @unlink($transcriptPath);

        self::assertStringContainsString(
            'Title: \u{1F3E0} RENT \u{B7} seloger \u{B7} 44/100 — Sartrouville',
            $wire,
            'the badge leads the title, before the source name a phone would otherwise show first',
        );
        self::assertStringContainsString('Tags: house,match', $wire, 'and it is a filterable tag, domain first');
    }

    public function testACrlfBearingUrlCannotSmuggleAHeaderOntoTheNtfyRequest(): void
    {
        // The url is landlord-controlled (listing payload → ListingMapper → Notification) and goes
        // into the Click header. A CRLF in it would start a second, attacker-chosen ntfy control
        // header (Attach, Actions, Email…) on the POST to the user's own server. NtfyChannel calls
        // libcurl directly, so CurlHttpClient's funnel guard never sees this — headerSafe on the
        // Click url is what closes it, and this test is what proves it stays closed.
        $transcriptPath = sys_get_temp_dir() . '/rentwatch-ntfy-inj-' . bin2hex(random_bytes(6)) . '.txt';

        $proc = proc_open(
            [PHP_BINARY, __DIR__ . '/../../Adapters/scripted-http-server.php', $transcriptPath],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($proc, 'the scripted HTTP server must start');

        try {
            $name = fgets($pipes[1], 256);
            self::assertIsString($name);
            $port = (int) substr(strrchr(trim($name), ':') ?: ':0', 1);
            self::assertGreaterThan(0, $port);

            (new NtfyChannel('secret-topic-1234', 'http://127.0.0.1:' . $port, 5))->send(new Notification(
                NotificationKind::MATCH,
                Priority::HIGH,
                // BOTH the title and the url carry an injection payload: the title is the header
                // whose guard made the round-3 Click finding visible, yet had no coverage of its
                // own until round 5. Both are landlord-controlled.
                "T4 a Chatou\r\nActions: view, Ouvrir, https://evil.test/pwn",
                ['score 82'],
                "https://example.test/a\r\nX-Smuggled: injected-by-listing\r\nAttach: https://evil.test/x",
            ));
        } finally {
            foreach ($pipes as $pipe) {
                @fclose($pipe);
            }
            proc_close($proc);
        }

        $wire = (string) @file_get_contents($transcriptPath);
        @unlink($transcriptPath);

        // Split header block from body: the body legitimately echoes the url as message text
        // (POSTFIELDS, not a header — a line break there is harmless display), so the injection
        // risk lives ENTIRELY in the header block, which ends at the Content-Length line.
        $boundary = stripos($wire, 'Content-Length');
        self::assertNotFalse($boundary, 'the request must carry a Content-Length header');
        $headerBlock = substr($wire, 0, $boundary);

        // The test of injection is not whether the string appears — headerSafe COLLAPSES the CRLF
        // to a space, so `X-Smuggled` survives as harmless inline text inside the Click value —
        // but whether it appears as its own header LINE. It must not.
        foreach (explode("\n", $headerBlock) as $line) {
            $lower = strtolower(trim($line));
            self::assertFalse(str_starts_with($lower, 'x-smuggled'), 'no injected header line: ' . $line);
            self::assertFalse(str_starts_with($lower, 'attach:'), 'no injected ntfy control header line: ' . $line);
            self::assertFalse(str_starts_with($lower, 'actions:'), 'no injected Actions header from the title: ' . $line);
        }

        // Both headers still carry their (collapsed, single-line) content.
        self::assertMatchesRegularExpression('~Click: [^\r\n]*example\.test~', $headerBlock);
        self::assertMatchesRegularExpression('~Title: [^\r\n]*Chatou~', $headerBlock);
    }
}
