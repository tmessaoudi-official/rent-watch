<?php

declare(strict_types=1);

namespace Scout\Tests\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Core\RecoverableForms;

/**
 * THE ONE DECODE CASCADE, PINNED DIRECTLY — it had no test of its own.
 *
 * `tools/scrub-eml.php` (which refuses to write a fixture while the subscriber's address is
 * RECOVERABLE) and `tests/php/Repo/FixtureSecretsTest.php` (the CI guard over what is already
 * committed) both call it. They were two copies until 2026-09-05 and the copy was one decode short,
 * so `base64(percent-encoded(address))` passed CI while the tool refused it.
 *
 * Its only coverage was inside the guard's own assertions, and the nightly ledger showed what that
 * is worth: mutating the guard's `quoted_printable_decode($content)` expectation to `$content` left
 * the whole suite GREEN, because the raw form is in the list anyway and the assertion then proved
 * nothing. A guarantee whose only witness is one assertion in one test can be deleted by editing
 * that assertion.
 *
 * Each layer below is asserted through a SHAPE THIS REPO HAS ACTUALLY SEEN — the four encodings the
 * scrubber learned from real captures — and the rule the file carries: *a check that only
 * understands the encodings already seen is the same defect with a later date.*
 */
#[CoversClass(RecoverableForms::class)]
final class RecoverableFormsTest extends TestCase
{
    private const string ADDRESS = 'victim@example.invalid';

    /** @return list<string> */
    private static function forms(string $message): array
    {
        return RecoverableForms::of($message);
    }

    private static function findsTheAddress(string $message): bool
    {
        foreach (self::forms($message) as $form) {
            if (str_contains($form, self::ADDRESS)) {
                return true;
            }
        }

        return false;
    }

    public function testTheRawBytesAreAlwaysScanned(): void
    {
        self::assertTrue(self::findsTheAddress("To: " . self::ADDRESS . "\r\n\r\nbody\r\n"));
    }

    /** Quoted-printable: a portal folds its headers, and `=3D` is what a JWT's `=` becomes. */
    public function testTheQuotedPrintableFormIsScanned(): void
    {
        $qp = 'X-Custom: victim=40example.invalid';

        self::assertFalse(str_contains($qp, self::ADDRESS), 'the premise: not present in the raw bytes');
        self::assertTrue(self::findsTheAddress($qp . "\r\n\r\nbody\r\n"));
    }

    /** Percent-encoding: PAP puts `email=x%40y` in the link it emails. */
    public function testThePercentEncodedFormIsScanned(): void
    {
        $pct = 'X-Custom: https://portal.test/a?email=' . rawurlencode(self::ADDRESS);

        self::assertFalse(str_contains($pct, self::ADDRESS));
        self::assertTrue(self::findsTheAddress($pct . "\r\n\r\nbody\r\n"));
    }

    /** A base64 BODY, which is the encoding after quoted-printable. */
    public function testABase64BodyIsDecodedAndScanned(): void
    {
        $blob = chunk_split(base64_encode('Bonjour ' . self::ADDRESS . ', voici vos annonces'), 40, "\r\n");
        $msg = "Content-Transfer-Encoding: base64\r\n\r\n" . $blob;

        self::assertFalse(str_contains($msg, self::ADDRESS));
        self::assertTrue(self::findsTheAddress($msg));
    }

    /** A base64url RUN — where a JWT payload lives (Bien'ici's `signedRecipient`). */
    public function testABase64urlRunIsDecodedAndScanned(): void
    {
        $jwt = rtrim(strtr(base64_encode('{"email":"' . self::ADDRESS . '"}'), '+/', '-_'), '=');
        $msg = "X-Custom: https://portal.test/a?signedRecipient=" . $jwt . "\r\n\r\nbody\r\n";

        self::assertFalse(str_contains($msg, self::ADDRESS));
        self::assertTrue(self::findsTheAddress($msg));
    }

    /** BASE64 OF PERCENT-ENCODED — the layer the drifted copy was short, and the one that passed CI. */
    public function testBase64OfAPercentEncodedAddressIsScanned(): void
    {
        $msg = "X-Custom: " . base64_encode('redirect=https://t.test/?email=' . rawurlencode(self::ADDRESS) . '&c=42') . "\r\n\r\nbody\r\n";

        self::assertFalse(str_contains($msg, self::ADDRESS));
        self::assertTrue(self::findsTheAddress($msg));
    }

    /** A HEADER FOLD: La Centrale's `X-MSFBL` splits its base64 across continuation lines. */
    public function testAnAddressStraddlingAHeaderFoldIsScanned(): void
    {
        $blob = base64_encode('{"addr":"' . self::ADDRESS . '"}');
        $folded = "X-MSFBL: " . substr($blob, 0, 12) . "\r\n\t" . substr($blob, 12) . "\r\n\r\nbody\r\n";

        self::assertFalse(str_contains($folded, self::ADDRESS));
        self::assertTrue(
            self::findsTheAddress($folded),
            'no FRAGMENT of a folded blob decodes to the address; only unfolding first can see it',
        );
    }

    /** THE COUNTERWEIGHT: it must not invent an address that is not there. */
    public function testAMessageWithNoAddressYieldsNoneInAnyForm(): void
    {
        self::assertFalse(self::findsTheAddress("Subject: rien\r\nX-Custom: " . base64_encode('nothing to see') . "\r\n\r\nbody\r\n"));
    }
}
