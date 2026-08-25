<?php

declare(strict_types=1);

namespace RentWatch\Tests\Adapters;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RentWatch\Adapters\Mail\EmailMessage;

/**
 * The MIME parser, tested against the shapes a REAL mailer emits.
 *
 * Every case here was written after running an actual SeLoger alert through the parser and watching
 * it return an empty body. The class was authored blind — its own docblock said so — and the
 * committed `email_demo` fixtures happen to omit the two structures below, which is why 1879 green
 * tests said nothing about either.
 */
#[CoversClass(EmailMessage::class)]
final class EmailMessageTest extends TestCase
{
    /**
     * RFC 2046 §5.1.1: everything between the headers and the FIRST boundary is the *preamble*, and
     * it is not a body part. Nearly every real mailer writes one — `This is a multi-part message in
     * MIME format.` — for clients that predate MIME.
     *
     * Read as a part it has no `Content-Type`, so it defaults to `text/plain`, splits to an empty
     * body, and runs `$plain ??= ''`. `??=` assigns only on `null`, so the REAL `text/plain` part
     * that follows can never overwrite it and the whole message parses to nothing.
     *
     * That is this project's signature silent failure reached without a single `catch`: zero
     * listings, no exception, a source that looks like a quiet market for ever (hard rule 3).
     */
    public function testAPreambleDoesNotSwallowTheBody(): void
    {
        $raw = self::multipart(
            preamble: 'This is a multi-part message in MIME format.',
            plain: "Appartement Conflans-Sainte-Honorine\n980 €/mois charges comprises",
            html: '<p>Appartement</p>',
        );

        $message = EmailMessage::parse($raw);

        self::assertStringContainsString('980', $message->body, 'the plain part must survive a preamble');
        self::assertStringContainsString('Conflans-Sainte-Honorine', $message->body);
    }

    /**
     * The same bug one layer down: a nested `multipart/*` that resolves to nothing must not claim
     * the answer either. `multipart/mixed` wrapping `multipart/alternative` is the ordinary shape
     * for an alert carrying an attachment or a tracking image.
     */
    public function testAnEmptyNestedPartDoesNotClaimTheAnswer(): void
    {
        $inner = "--INNER\nContent-Type: text/plain; charset=\"utf-8\"\n\n\n--INNER--\n";

        $raw = "Subject: nested\n"
            . "Content-Type: multipart/mixed; boundary=\"OUTER\"\n"
            . "\n"
            . "preamble text\n"
            . "--OUTER\n"
            . "Content-Type: multipart/alternative; boundary=\"INNER\"\n"
            . "\n"
            . $inner
            . "--OUTER\n"
            . "Content-Type: text/plain; charset=\"utf-8\"\n"
            . "\n"
            . "1 200 € charges comprises\n"
            . "--OUTER--\n";

        self::assertStringContainsString('1 200', EmailMessage::parse($raw)->body);
    }

    /**
     * An empty `text/plain` part falls through to the HTML one rather than winning by being first.
     *
     * Some mailers ship a deliberately blank plain alternative. Preferring plain is right (stripping
     * markup is where a tenure label in an attribute gets lost) — preferring an EMPTY plain is the
     * same `''`-is-not-`null` mistake wearing different clothes.
     */
    public function testAnEmptyPlainPartFallsThroughToHtml(): void
    {
        $raw = self::multipart(preamble: '', plain: '', html: '<p>Studio Dourdan 915 €</p>');

        self::assertStringContainsString('Dourdan', EmailMessage::parse($raw)->body);
    }

    /**
     * RFC 2047 §6.2: linear whitespace BETWEEN two adjacent encoded words is not displayed. A long
     * French subject is folded across two of them constantly, and the split lands mid-word.
     *
     * `Consilium vous adresse ses dernières exclusivités` arrives as `…exclusivit?= =?UTF-8?Q?=C3=A9s`
     * and must not decode to `exclusivit és`. The collapse existed but ran AFTER the decode, where
     * no `?= =?` sequence survives to match — dead from the line it was written on.
     *
     * A mangled subject is not cosmetic here: the subject is a listing's `title`, which
     * `exclude_title_patterns` filters on and which the tenure classifier reads.
     */
    public function testAdjacentEncodedWordsJoinWithNoSeparator(): void
    {
        $raw = "Subject: =?UTF-8?Q?Consilium_vous_adresse_ses_derni=C3=A8res_exclusivit?=\n"
            . " =?UTF-8?Q?=C3=A9s?=\n"
            . "Content-Type: text/plain; charset=\"utf-8\"\n"
            . "\n"
            . "corps\n";

        self::assertSame(
            'Consilium vous adresse ses dernières exclusivités',
            EmailMessage::parse($raw)->subject(),
        );
    }

    /** A single encoded word keeps working, so the collapse cannot be blamed for a regression. */
    public function testASingleEncodedWordStillDecodes(): void
    {
        $raw = "Subject: =?UTF-8?B?" . base64_encode('1 nouvelle annonce : Île-de-France') . "?=\n"
            . "Content-Type: text/plain\n"
            . "\n"
            . "corps\n";

        self::assertSame('1 nouvelle annonce : Île-de-France', EmailMessage::parse($raw)->subject());
    }

    /**
     * Two encoded words separated by a word that is NOT encoded keep their real spacing. Only
     * whitespace between two ADJACENT encoded words is dropped, and collapsing more than that would
     * run `Paris` into the next token.
     */
    public function testWhitespaceAroundAPlainWordSurvives(): void
    {
        $raw = "Subject: =?UTF-8?Q?Alerte?= Paris =?UTF-8?Q?imm=C3=A9diate?=\n"
            . "Content-Type: text/plain\n"
            . "\n"
            . "corps\n";

        self::assertSame('Alerte Paris immédiate', EmailMessage::parse($raw)->subject());
    }

    /** A boundary containing regex-significant and RFC-2047-looking bytes — SeLoger's is `8PaVqvzMwU9R=_?:`. */
    public function testAnAwkwardBoundaryStillSplits(): void
    {
        $raw = "Subject: awkward\n"
            . "Content-Type: multipart/alternative;\n"
            . "\tboundary=\"8PaVqvzMwU9R=_?:\"\n"
            . "\n"
            . "This is a multi-part message in MIME format.\n"
            . "\n"
            . "--8PaVqvzMwU9R=_?:\n"
            . "Content-Type: text/plain;\n"
            . "\tcharset=\"utf-8\"\n"
            . "Content-Transfer-Encoding: 8bit\n"
            . "\n"
            . "44,71 m² à Conflans-Sainte-Honorine\n"
            . "--8PaVqvzMwU9R=_?:--\n";

        self::assertStringContainsString('44,71', EmailMessage::parse($raw)->body);
    }

    private static function multipart(string $preamble, string $plain, string $html): string
    {
        return "Subject: alerte\n"
            . "Content-Type: multipart/alternative; boundary=\"BOUND\"\n"
            . "\n"
            . ($preamble === '' ? '' : $preamble . "\n\n")
            . "--BOUND\n"
            . "Content-Type: text/plain; charset=\"utf-8\"\n"
            . "\n"
            . $plain . "\n"
            . "--BOUND\n"
            . "Content-Type: text/html; charset=\"utf-8\"\n"
            . "\n"
            . $html . "\n"
            . "--BOUND--\n";
    }
}
