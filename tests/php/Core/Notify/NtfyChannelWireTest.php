<?php

declare(strict_types=1);

namespace RentWatch\Tests\Core\Notify;

use PHPUnit\Framework\TestCase;
use RentWatch\Core\Notify\Notification;
use RentWatch\Core\Notify\NotificationKind;
use RentWatch\Core\Notify\NtfyChannel;
use RentWatch\Core\Notify\Priority;

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
        self::assertStringContainsString('Priority:', $wire);
        self::assertStringContainsString('User-Agent: rent-watch', $wire, 'hard rule 5 holds on this path too');
        self::assertStringNotContainsStringIgnoringCase('mozilla', $wire);
        self::assertStringContainsString('https://example.test/annonce/1', $wire, 'the body carries the link');
    }
}
