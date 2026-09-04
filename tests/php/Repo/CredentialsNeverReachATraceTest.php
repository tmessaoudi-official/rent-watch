<?php

declare(strict_types=1);

namespace Scout\Tests\Repo;

use PHPUnit\Framework\TestCase;
use Scout\Adapters\Mail\ImapMailbox;
use Scout\Core\Notify\SmtpTransport;

/**
 * NO CREDENTIAL MAY REACH A STACK TRACE.
 *
 * PHP ships `zend.exception_ignore_args = Off` and `zend.exception_string_param_max_len = 15`, so
 * an uncaught trace prints the first 15 characters of every string ARGUMENT of every LIVE frame —
 * this codebase's frames and the built-ins' alike. Object properties and locals are not printed;
 * parameters are. That one distinction is the whole subject of this file.
 *
 * ## Three surfaces, one shape, found in three different ways
 *
 * 1. `tools/dump-eml.php` sent `$cmd('LOGIN "u" "…"')`. Fixed 2026-09-04 by a zero-argument
 *    closure, with the threat model written into its docblock.
 * 2. `Adapters\Mail\ImapMailbox` sent the identical construction through `command()` — **left
 *    standing by the very commit that documented the threat model**, which is this repo's named
 *    recurring defect: a correct rule applied to a subset of the surfaces it belongs on. A C2
 *    round-2 review panel found it and measured two password characters escaping behind a
 *    three-character username. Nothing leaked with the real `IMAP_USER` only because a long
 *    username spends the budget first — luck, not a guard.
 * 3. `Core\Notify\SmtpTransport` passed `base64_encode($this->password)` to `say()`. Found by
 *    asking what else the panel's question reached rather than fixing only its instance, and it is
 *    the WORST of the three: the credential was the only string argument, so the whole budget went
 *    to it whatever the username, and the base64 prefix decodes to eleven characters.
 *
 * ## The fix needed TWO levels, and the first was not enough
 *
 * Moving the credential out of the helper's parameter list left it in `fwrite`'s. A trace prints
 * built-in frames too, and `@` suppresses warnings while doing nothing to the `TypeError` a closed
 * stream raises. Both writes are therefore wrapped, with the original exception DISCARDED rather
 * than chained — a `previous` carries the trace being escaped. A second draft then took the encoded
 * value as a parameter of the wrapper, which put it straight back; the shipped form passes a
 * SELECTOR and reads the credential from `$this` into a local.
 *
 * The stated cost, in both classes: the underlying stream error is lost on that one call, so a
 * failed AUTH or LOGIN write reports only that it could not be written.
 */
final class CredentialsNeverReachATraceTest extends TestCase
{
    private const string PASSWORD = 'SuperSecretPassword';
    private const string USER = 'annette-the-subscriber';

    /**
     * THE MECHANISM, proven on this machine's own PHP before anything is asserted about the code.
     *
     * Without this the tests below could pass on a runtime where arguments are never printed, and
     * would then be guarding nothing while looking green.
     */
    public function testAnArgumentReachesTheTraceOnThisRuntimeButALocalDoesNot(): void
    {
        // The argument is the credential ALONE, which is both the worst real shape (SmtpTransport's)
        // and the only one this assertion can state exactly: the budget is 15 characters, so
        // `LOGIN "u" "SuperSecretPassword"` truncates at `Supe` and a probe looking for `Super`
        // fails while the mechanism it is testing works perfectly. Counted, not guessed.
        $viaArgument = static function (string $line): void {
            throw new \RuntimeException('refused');
        };

        try {
            $viaArgument(self::PASSWORD);
            self::fail('the probe must throw');
        } catch (\Throwable $e) {
            self::assertStringContainsString(
                substr(self::PASSWORD, 0, 15),
                $e->getTraceAsString(),
                'an argument IS printed on this runtime, truncated to zend.exception_string_param_max_len',
            );
        }

        $viaLocal = function (): void {
            $line = 'LOGIN "u" "' . self::PASSWORD . '"';
            self::assertNotSame('', $line);
            throw new \RuntimeException('refused');
        };

        try {
            $viaLocal();
            self::fail('the probe must throw');
        } catch (\Throwable $e) {
            self::assertStringNotContainsString('Super', $e->getTraceAsString(), 'a local is NOT printed');
        }
    }

    /**
     * A CLOSED STREAM is the shape that matters: it makes `fwrite` RAISE rather than return false,
     * which is the path `@` does not cover and the reason the wrapper exists.
     */
    public function testTheImapLoginPutsNoCredentialInATrace(): void
    {
        $r = new \ReflectionClass(ImapMailbox::class);
        $mailbox = $r->newInstanceWithoutConstructor();
        $r->getProperty('user')->setValue($mailbox, self::USER);
        $r->getProperty('password')->setValue($mailbox, self::PASSWORD);
        $r->getProperty('tag')->setValue($mailbox, 0);
        $r->getProperty('socket')->setValue($mailbox, self::closedStream());

        try {
            $r->getMethod('login')->invoke($mailbox);
            self::fail('a closed stream must be refused');
        } catch (\Throwable $e) {
            $seen = $e->getMessage() . "\n" . $e->getTraceAsString();
            self::assertStringNotContainsString(self::PASSWORD, $seen);
            self::assertStringNotContainsString('Super', $seen, 'not even the 15-character prefix');
            self::assertStringNotContainsString('LOGIN', $seen, 'and not the command line carrying it');
        }
    }

    public function testTheSmtpAuthLinesPutNoCredentialInATrace(): void
    {
        $r = new \ReflectionClass(SmtpTransport::class);

        foreach (['sayUser', 'sayPassword'] as $method) {
            $transport = $r->newInstanceWithoutConstructor();
            $r->getProperty('user')->setValue($transport, self::USER);
            $r->getProperty('password')->setValue($transport, self::PASSWORD);

            try {
                $r->getMethod($method)->invoke($transport, self::closedStream());
                self::fail($method . ' must refuse a closed stream');
            } catch (\Throwable $e) {
                $seen = $e->getMessage() . "\n" . $e->getTraceAsString();

                // Both forms: AUTH LOGIN sends base64, so the plaintext alone is not enough to look for.
                foreach ([self::PASSWORD, base64_encode(self::PASSWORD), self::USER, base64_encode(self::USER)] as $needle) {
                    self::assertStringNotContainsString($needle, $seen, $method . ' leaked ' . substr($needle, 0, 6));
                }
                self::assertStringNotContainsString(substr(base64_encode(self::PASSWORD), 0, 15), $seen, 'nor the truncated prefix');
            }
        }
    }

    /**
     * THE STRUCTURAL HALF, tying the code to the mechanism above so the shape cannot return.
     *
     * Comment lines are stripped first: both classes DOCUMENT the construction they no longer use,
     * and a naive grep reads the documentation of a guarantee as its violation — a red run already
     * paid for once in `tests/test-dump-eml.sh`.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function forbiddenConstructions(): iterable
    {
        yield 'IMAP LOGIN through the generic command helper' => [
            __DIR__ . '/../../../src/php/Adapters/Mail/ImapMailbox.php',
            '~\$this->command\(\s*.LOGIN~',
        ];
        yield 'SMTP credential as a say() argument' => [
            __DIR__ . '/../../../src/php/Core/Notify/SmtpTransport.php',
            '~\$this->say\([^)]*base64_encode~',
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenConstructions')]
    public function testTheConstructionCannotComeBack(string $path, string $pattern): void
    {
        $code = (string) file_get_contents($path);
        $lines = preg_split('/\R/', $code) ?: [];
        $codeOnly = implode("\n", array_filter(
            $lines,
            static fn (string $l): bool => preg_match('~^\s*(\*|/\*|//|#)~', $l) !== 1,
        ));

        self::assertSame(0, preg_match($pattern, $codeOnly), 'the credential is a call argument again');
    }

    /** @return resource */
    private static function closedStream(): mixed
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fclose($stream);

        return $stream;
    }
}
