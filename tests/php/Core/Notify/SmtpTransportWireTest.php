<?php

declare(strict_types=1);

namespace Scout\Tests\Core\Notify;

use PHPUnit\Framework\TestCase;
use Scout\Core\Notify\ChannelError;
use Scout\Core\Notify\SmtpTransport;
use Scout\Core\Redact;

/**
 * `SmtpTransport` against a real socket, answered by a scripted server on loopback.
 *
 * These exist because the previous SMTP tests had the shape a sabotage run exposed twice already:
 * they proved the MECHANISM (`Redact` masks a literal it is handed) and nothing about the WIRING
 * (`SmtpTransport::secrets()` actually handing the literals over, the STARTTLS check actually
 * running before a credential leaves the process). Two sabotages stayed green because of it —
 * "SMTP continues without STARTTLS" and "SMTP stops masking the base64 form of the password".
 * Every assertion here is on what actually crossed the wire or what a real dialogue produced.
 */
final class SmtpTransportWireTest extends TestCase
{
    private const string PASSWORD = 'wire-hunter2-pw';

    /** @var resource|null */
    private mixed $proc = null;

    /** @var list<resource> */
    private array $pipes = [];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        if ($this->proc !== null) {
            @proc_terminate($this->proc);
            @proc_close($this->proc);
            $this->proc = null;
        }
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
    }

    public function testAServerNotOfferingStarttlsGetsNoCredentialAtAll(): void
    {
        // THE CLEARTEXT-CREDENTIAL PATH. A server that does not advertise STARTTLS while the
        // transport is configured for it is misconfigured or being impersonated; continuing would
        // put the password on the wire in the clear. The refusal must happen BEFORE any credential
        // leaves the process — which only the transcript can prove.
        [$port, $transcriptPath] = $this->startServer([
            '220 fake ready',
            "250-fake greets you\n250 SIZE 35882577",
        ]);

        $transport = new SmtpTransport(
            '127.0.0.1',
            $port,
            'alert-bot',
            self::PASSWORD,
            security: 'starttls',
            timeoutSeconds: 5,
        );

        try {
            $transport->send('moi@example.test', 'Sujet', 'Corps', []);
            self::fail('a server without STARTTLS must be refused, not accommodated');
        } catch (ChannelError $e) {
            self::assertStringContainsString('STARTTLS', $e->getMessage());
        }

        $sent = $this->transcript($transcriptPath);
        self::assertNotEmpty($sent, 'the client must at least have said EHLO');

        foreach ($sent as $line) {
            self::assertStringNotContainsStringIgnoringCase('AUTH', $line);
            self::assertStringNotContainsString(self::PASSWORD, $line);
            self::assertStringNotContainsString(base64_encode(self::PASSWORD), $line);
        }
    }

    public function testARejectedLoginDoesNotLeakTheCredentialTheServerEchoes(): void
    {
        // A real server echoes the command it rejected, and for AUTH LOGIN that command IS the
        // base64 password. `{LAST}` makes the fake do the same, so this test only passes if
        // `SmtpTransport::secrets()` hands BOTH forms of the credential to `ChannelError` — a
        // constructed-error test cannot tell that wiring apart from decoration. `security: 'none'`
        // is what puts AUTH on a plain socket, and it is only permitted because the host is
        // loopback; that gate has its own test in NetworkAdaptersTest.
        [$port, $transcriptPath] = $this->startServer([
            '220 fake ready',
            '250 fake greets you',
            '334 VXNlcm5hbWU6',
            '334 UGFzc3dvcmQ6',
            '535 5.7.8 authentication rejected for {LAST}',
        ]);

        $transport = new SmtpTransport(
            '127.0.0.1',
            $port,
            'alert-bot',
            self::PASSWORD,
            security: 'none',
            timeoutSeconds: 5,
        );

        try {
            $transport->send('moi@example.test', 'Sujet', 'Corps', []);
            self::fail('a 535 must surface as a channel failure');
        } catch (ChannelError $e) {
            $message = $e->getMessage();

            self::assertStringContainsString('535', $message, 'the diagnostic must survive masking');
            self::assertStringContainsString(Redact::MASK, $message);
            self::assertStringNotContainsString(self::PASSWORD, $message);
            self::assertStringNotContainsString(base64_encode(self::PASSWORD), $message);
        }

        // The credential DID cross the wire — plaintext auth to loopback is the configuration
        // under test — so the transcript doubles as proof the server really echoed it.
        self::assertContains(base64_encode(self::PASSWORD), $this->transcript($transcriptPath));
    }

    public function testADeliveryDotStuffsTheBodyOnTheWire(): void
    {
        // RFC 5321 transparency: a body line starting with `.` must be stuffed to `..`, or the
        // rest of a landlord's ad is read as SMTP commands and the notification is truncated
        // silently. The transcript shows the stuffed form actually transmitted.
        [$port, $transcriptPath] = $this->startServer([
            '220 fake ready',
            '250 fake greets you',
            '250 sender ok',
            '250 recipient ok',
            '354 go ahead',
            'DATA|250 queued',
            '221 bye',
        ]);

        $transport = new SmtpTransport('127.0.0.1', $port, security: 'none', timeoutSeconds: 5, from: 'rent-watch@localhost');
        $transport->send(
            'moi@example.test',
            'Sujet',
            "Ligne un\n.commence par un point\nLigne trois",
            ['From' => 'rent-watch@localhost'],
        );

        $sent = $this->transcript($transcriptPath);

        self::assertContains('MAIL FROM:<rent-watch@localhost>', $sent);
        self::assertContains('..commence par un point', $sent, 'the leading dot must be doubled on the wire');
        self::assertContains('.', $sent, 'the terminator must still be a lone dot');
        self::assertNotContains('.commence par un point', $sent, 'the unstuffed form must not be transmitted');
    }

    /**
     * Fork the scripted server and wait until it is listening.
     *
     * @param list<string> $replies
     *
     * @return array{int, string} the port it chose, and the transcript path
     */
    private function startServer(array $replies): array
    {
        $id = bin2hex(random_bytes(6));
        $script = sys_get_temp_dir() . '/rentwatch-smtp-replies-' . $id . '.json';
        $transcript = sys_get_temp_dir() . '/rentwatch-smtp-transcript-' . $id . '.txt';
        file_put_contents($script, json_encode($replies));
        $this->tempFiles[] = $script;
        $this->tempFiles[] = $transcript;

        $proc = proc_open(
            [PHP_BINARY, __DIR__ . '/scripted-smtp-server.php', $script, $transcript],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($proc, 'the scripted server must start');
        $this->proc = $proc;
        $this->pipes = array_values($pipes);

        // Blocks until the child prints its address — it does so the moment it listens, and if it
        // dies instead the pipe closes and this returns false.
        $name = fgets($pipes[1], 256);
        self::assertIsString($name, 'the scripted server must report the port it chose');

        $port = (int) substr(strrchr(trim($name), ':') ?: ':0', 1);
        self::assertGreaterThan(0, $port);

        return [$port, $transcript];
    }

    /**
     * Wait for the server to exit, then read what the client actually sent.
     *
     * `proc_close` blocks until the child exits; the child's own 5-second socket timeouts bound
     * that wait, so a wedged dialogue costs seconds, not a hung suite.
     *
     * @return list<string>
     */
    private function transcript(string $path): array
    {
        foreach ($this->pipes as $pipe) {
            @fclose($pipe);
        }
        $this->pipes = [];

        if ($this->proc !== null) {
            proc_close($this->proc);
            $this->proc = null;
        }

        $raw = @file_get_contents($path);
        self::assertIsString($raw, 'the server must have written its transcript');

        return array_values(array_filter(explode("\n", rtrim($raw, "\n")), static fn (string $l): bool => $l !== ''));
    }
}
