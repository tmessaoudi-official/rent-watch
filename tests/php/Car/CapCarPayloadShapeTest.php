<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use PHPUnit\Framework\TestCase;
use Scout\Adapters\Mail\EmailMessage;

/**
 * TRACK 6-B1 — CapCar does NOT fit the car email adapter as it stands, and this is the measurement.
 *
 * The plan files B1 as *"structured labelled fields per card, 4 cards/message"* and puts it with
 * B2/B3 under *"payloads banked in Gmail; build offline"* — that is, config-only. **It is not
 * config-only**, and the reason is structural rather than a missing parameter:
 *
 * - `VehicleEmailSource` applies `title_pattern` to `$message->subject()`, never to the card
 *   segment. CapCar's subject is one banner for the whole message — *"Nouvelle sélection de
 *   véhicules disponibles !"* — so it can name no individual car.
 * - With no `title_pattern`, the adapter falls back to the line ABOVE the price line. In this
 *   payload that is `Kilométrage : 24409`.
 * - `make_model_source: title` then reads the make out of that, and the alternative haystack —
 *   the link — is an opaque per-recipient `sendibt3.com` redirect carrying no make at all.
 *
 * So a config-only CapCar would store a mileage label as the title and no make, and `brand_avoid`
 * reads `make`: an unextracted make scores 0 on that component, so every CapCar car would rank ten
 * points below an identical one from a source that states its make. That is the silent-ordering
 * failure Track 1d exists for, and shipping it would be worse than not shipping the source.
 *
 * **What the adapter would need is a per-SEGMENT field reader** — the labelled block is perfectly
 * regular — which is a code change with its own tests and its own review, not a config block. Filed
 * rather than bodged.
 *
 * This file pins the measurement so the finding is reproducible without the mailbox, and so that
 * whoever builds the reader has the ground truth to write it against. The fixture is scrubbed and
 * was audited for a recoverable identity in every encoding before it was committed.
 */
final class CapCarPayloadShapeTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../fixtures/car/capcar/2026-09-03-001-quatre-cartes.eml';

    private function body(): string
    {
        return EmailMessage::parse((string) file_get_contents(self::FIXTURE))->body;
    }

    /**
     * FOUR CARDS, each a regular labelled block ending in its own link.
     *
     * The link matters: `harvestHrefs()` places it INSIDE the card, after the CTA. That is why the
     * CTA cannot be the card separator — splitting there would leave every card holding the
     * PREVIOUS card's link, which is the PAP phantom-listing shape with the fields shifted by one.
     */
    public function testTheMessageCarriesFourRegularLabelledCards(): void
    {
        $body = $this->body();

        foreach (['Marque', 'Modèle', 'Finition', 'Motorisation', 'Carburant', 'Année', 'Kilométrage', 'Prix'] as $label) {
            self::assertSame(4, preg_match_all('~' . preg_quote($label, '~') . '~u', $body), $label . ' appears once per card');
        }

        self::assertSame(4, substr_count($body, 'Voir ce véhicule'), 'each card ends on its own CTA');
    }

    /**
     * THE LABELS ARE NOT ASCII, and a config written from the rendered text would miss every one.
     *
     * `Marque` is followed by U+00A0 before the colon, and the price thousands separator is U+202F.
     * `explode('Marque :', $body)` — the obvious separator — returns ONE segment, silently, which
     * is a source that yields nothing while reporting a healthy fetch.
     */
    public function testTheLabelsUseNonAsciiSpacesThatABlindConfigWouldMiss(): void
    {
        $body = $this->body();

        self::assertStringContainsString("Marque\u{00A0}:", $body, 'NBSP before the colon');
        self::assertSame(1, count(explode('Marque :', $body)), 'the ASCII form matches nothing at all');
        self::assertSame(5, count(explode("Marque\u{00A0}:", $body)), 'the real form segments the four cards');

        self::assertSame(1, preg_match('~Prix\s*:\s*([\d\x{202F}\x{00A0} ]+)~u', $body, $m));
        self::assertStringContainsString("\u{202F}", $m[1], 'the price uses a narrow no-break space as its thousands mark');
    }

    /**
     * THE BLOCKER, asserted rather than described: the subject names no vehicle.
     *
     * `title_pattern` is applied to the subject, so no pattern over it can produce a per-card title.
     * If a future adapter gains a per-segment reader, this assertion is what should change.
     */
    public function testTheSubjectCannotNameAnIndividualVehicle(): void
    {
        $subject = EmailMessage::parse((string) file_get_contents(self::FIXTURE))->subject();

        self::assertStringContainsString('Nouvelle sélection', $subject);

        foreach (['Renault', 'Clio', 'Dacia', 'Duster'] as $vehicle) {
            self::assertStringNotContainsString($vehicle, $subject, 'the subject is one banner for the whole message');
        }
    }

    /** And the links carry no make either — the only other haystack `make_model_source` offers. */
    public function testTheLinksAreOpaquePerRecipientRedirects(): void
    {
        $links = EmailMessage::parse((string) file_get_contents(self::FIXTURE))->links;

        self::assertNotSame([], $links);

        foreach ($links as $link) {
            self::assertStringContainsString('sendibt3.com', $link, 'every link is a tracking redirect');
            self::assertStringNotContainsStringIgnoringCase('renault', $link, 'and states no make');
        }
    }
}
