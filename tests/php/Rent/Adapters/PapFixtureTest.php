<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Adapters;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Adapters\EmailAlertSource;
use Scout\Adapters\Mail\FileMailbox;
use Scout\Rent\Config\ConfigLoader;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\SourceProfile;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Core\TenureClassifier;
use Scout\Rent\Store\Store;

/**
 * PAP (De Particulier a Particulier) — the simplest alert shape in the tree, and the one that
 * proved a naive config wrong on four counts at once.
 *
 * ONE listing per message, a `text/plain` part, and a real ad id in the URL, so none of SeLoger's
 * content-addressing is needed. What it does have is a preamble quoting the SUBSCRIBER'S OWN SEARCH
 * CRITERIA above the listing — *"jusqu'a 1.200 EUR a partir de 45 m2"* — and every generic reader in
 * `EmailAlertSource` is a first-match-wins `preg_match`. Measured on the real capture before any of
 * this was written:
 *
 *   surfaceIn()  -> 45.0   (the search FILTER, not the flat's 50 m2)
 *   roomsIn()    -> 3      (right, and only by coincidence: the criteria line also says 3)
 *   rentIn()     -> 800    (correct — the periodic-figure rule earns its keep here)
 *   communeIn()  -> null   (Milly-la-Foret is not a RANKED commune, so the vocabulary scan is blind)
 *
 * 45 is below `min_surface_m2`, so the first PAP alert ever sent would have been rejected for being
 * too small — silently, with nothing anywhere reading as a fault. That is Bien'ici's defect a second
 * time, down to the same number 45, and it is why the anchors below are POSITIONAL: they key on the
 * `(NNNNN)` postcode line, which is the one structural landmark the template guarantees, rather than
 * on any vocabulary an owner might or might not type.
 *
 * **n=2, and the second capture is why that matters.** The anchors were measured on ONE message,
 * with the n=1 risk stated — a risk this repo has twice paid for. It lasted about three hours: a
 * second alert arrived the same afternoon and is frozen here beside the first. It confirms every
 * anchor on a different commune and adds the case the first lacked, a rent written `1.150 EUR`
 * where the dot is a thousands separator. Append a third; never renumber.
 *
 * Everything here reads the SHIPPED `config/rent/sources.json`, so a config edit that breaks extraction
 * fails here rather than in production.
 */
#[CoversClass(EmailAlertSource::class)]
final class PapFixtureTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    private ?string $dbPath = null;

    protected function tearDown(): void
    {
        if ($this->dbPath !== null && is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
        $this->dbPath = null;
    }

    public function testTheUnsubscribeLinkIsNotAPhantomSecondListing(): void
    {
        // The message carries TWO links and both are on `www.pap.fr`: the annonce, and the
        // unsubscribe page at `/utilisateur/alertes`. `looksLikeAListing()` rejects noise by
        // substring — `unsubscribe`, `desinscription`, `preferences` — and PAP's own wording matches
        // NONE of them, so with a host-only `link_host` the second link is accepted as a listing.
        //
        // A non-segmented source builds one listing PER ACCEPTED LINK, each given the whole message
        // as its body, so that phantom carries the real flat's rent, commune, surface and rooms
        // under its own identity: notified as a separate flat, and never delisted because the
        // unsubscribe page never goes away.
        //
        // Asserted as one-per-MESSAGE rather than a bare count, so adding a third capture cannot
        // silently satisfy it.
        $messages = glob(self::ROOT . '/tests/fixtures/rent/pap/*.eml') ?: [];
        $listings = $this->listings();

        self::assertCount(count($messages), $listings);
        self::assertNotSame([], $messages, 'the fixture directory must not be empty');

        foreach ($listings as $listing) {
            self::assertStringContainsString('/annonces/', (string) $listing->url);
            self::assertStringNotContainsString('/utilisateur/', (string) $listing->url);
        }
    }

    public function testTheSecondCaptureExtractsToo(): void
    {
        // n=1 was the stated risk when the anchors were written, and it lasted about three hours —
        // a second alert arrived the same afternoon, from a different commune, and is frozen here as
        // the regression test the docblock promised.
        //
        // It carries the case the first one did not: a rent written `1.150 EUR / mois`, where the
        // dot is a THOUSANDS separator. "The rightmost separator is the decimal point" would read
        // that as 1 € — a flat that clears every ceiling and scores maximum headroom.
        $listing = $this->byCommune()['Meulan-en-Yvelines'];

        self::assertSame('78250', $listing->postcode);
        self::assertSame(1150, $listing->rentHc, 'a dot before three digits is a thousands group');
        self::assertSame(90.0, $listing->surfaceM2, 'the flat\'s 90 m², not the search floor of 45');
        self::assertSame(3, $listing->rooms);
        self::assertSame('https://www.pap.fr/annonces/-r453201284', (string) $listing->externalId);
    }

    public function testTheCardsOwnFactsBeatTheSearchCriteriaQuotedAboveThem(): void
    {
        $listing = $this->byCommune()['Milly-la-Forêt'];

        // THE ONE THAT WAS MEASURED WRONG. The preamble says `a partir de 45 m2`; the flat is 50.
        self::assertSame(50.0, $listing->surfaceM2, 'the flat\'s surface, not the search floor');

        // Right before the fix too, and only by coincidence — the criteria line also says 3 pieces.
        // Anchored anyway: a coincidence is not a guarantee, and a 4-piece flat found by a
        // "3 pieces et plus" alert would have read 3.
        self::assertSame(3, $listing->rooms);

        self::assertSame('91490', $listing->postcode);

        // RENT IS `hors charges` BY MEASUREMENT, not by omission: the payload mentions charges
        // NOWHERE — zero occurrences of `charges`, `CC` or `HC`. The Logirep and leboncoin precedent
        // applies, so the figure lands in rentHc, `max_rent_cc` never fires on it, and the score
        // line says the ceiling is unverifiable.
        self::assertSame(800, $listing->rentHc);
        self::assertNull($listing->rentCc, 'an unstated CC must never be invented');
    }

    public function testTheCommuneIsReadFromTheLayoutAndNotFromTheRankedVocabulary(): void
    {
        // `communeIn()` scans `Criteria::communeLabels`, which in REGION MODE is built from the
        // ranked communes only — ten names. Milly-la-Foret is not one of them, so the scan returns
        // null while the postcode parses fine: the listing still MATCHES, and the push simply cannot
        // say where the flat is, Dedup gets a weaker key, and the S1 score cannot fire. Nothing
        // about that reads as a fault from outside. Exactly the SeLoger regression of 2026-08-25.
        $communes = array_keys($this->byCommune());
        sort($communes);

        self::assertSame(
            ['Meulan-en-Yvelines', 'Milly-la-Forêt'],
            $communes,
            'both captures name a commune, and NEITHER is a ranked one — sorted, because the '
            . 'mailbox does not promise an order and pinning an incidental one is a false guarantee',
        );
    }

    public function testTheTitleIsTheCardsOwnAndNeverTheSubjectLine(): void
    {
        // Unconfigured, the title falls back to the message SUBJECT — here
        // `Alerte email : Appartement 3 pieces Milly-la-Foret (91490)`. That is not merely untidy:
        // `exclude_title_patterns` is matched against the TITLE ONLY, and the `Alerte email : `
        // prefix defeats the anchored `^\s*chambre\b` that exists because four of SeLoger's first
        // nine matches were coliving ROOMS passing every numeric filter. The exclusion would be
        // structurally unreachable on this source — the In'li and SeLoger lesson a third time.
        $title = (string) $this->byCommune()['Milly-la-Forêt']->title;

        self::assertSame('Location appartement 3 pièces', $title);
        self::assertStringNotContainsString('Alerte email', $title);
    }

    public function testIdentityIsTheAnnoncePathStrippedOfItsPerRecipientQuery(): void
    {
        // The ad id `-r458301723` is real and survives `stableId()`, which rebuilds from
        // scheme+host+path — dropping `email=`, the `md5=` token and the utm campaign, all of which
        // are per-recipient and would otherwise re-key the row on every send.
        //
        // Link identity was chosen BEFORE the source was first enabled, deliberately: nothing
        // migrates a stored row from one key scheme to another, so switching later re-notifies the
        // entire backlog.
        $listing = $this->byCommune()['Milly-la-Forêt'];

        self::assertSame('https://www.pap.fr/annonces/-r458301723', (string) $listing->externalId);
        self::assertStringNotContainsString('md5=', (string) $listing->externalId);
        self::assertStringNotContainsString('email=', (string) $listing->externalId);
    }

    public function testTheAlertSenderIsScopedAndNotThePortalsAccountAddress(): void
    {
        // PAP sends from TWO addresses and only one is alerts. `users@pap.fr` carries the
        // creation/deletion receipts and the marketing ("Le kit du locataire"); `users-alertes@pap.fr`
        // carries the search alerts. One mailbox serves every portal, so `from` is this source's
        // scope AND what scopes the IMAP `SEARCH ... FROM` — getting it wrong ingests a newsletter
        // as listings and starves the real alerts out of the window.
        $params = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['pap']->params;

        self::assertSame('users-alertes@pap.fr', $params['from']);
    }

    public function testAnExplicitSocialLabelIsStillCaughtOnThisSource(): void
    {
        // §1's residual, stated again. `mixed_tenure: false` means a card stating no tenure takes
        // the LIBRE default — which is every real card here. What it does NOT do is switch off the
        // tier-2 label rules, which never consult the flag: an explicit PLS injected into a real
        // card must still REJECT.
        $listing = $this->byCommune()['Milly-la-Forêt'];

        $poisoned = new RawListing(
            sourceName: $listing->sourceName,
            externalId: $listing->externalId,
            title: $listing->title,
            description: ($listing->description ?? '') . "\nLogement conventionné, financement PLS.",
            commune: $listing->commune,
            postcode: $listing->postcode,
            rentHc: $listing->rentHc,
            surfaceM2: $listing->surfaceM2,
            rooms: $listing->rooms,
        );

        $result = (new TenureClassifier())->classify(
            $poisoned,
            new SourceProfile(name: 'pap', defaultTenure: Tenure::LIBRE, mixedTenure: false),
        );

        self::assertTrue($result->tenure->isExcluded());
    }

    /** @return list<RawListing> */
    private function listings(): array
    {
        $this->dbPath ??= sys_get_temp_dir() . '/rentwatch-pap-' . bin2hex(random_bytes(8)) . '.sqlite3';

        $definition = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['pap'];
        $criteria = ConfigLoader::loadCriteria(self::ROOT . '/config/rent/criteria.json');

        $source = new EmailAlertSource(
            $definition,
            Store::open($this->dbPath),
            new FileMailbox(self::ROOT . '/tests/fixtures/rent/pap'),
            $criteria->communeLabels,
        );

        return $source->fetch();
    }

    /** @return array<string, RawListing> keyed by commune, in fetch order */
    private function byCommune(): array
    {
        $out = [];

        foreach ($this->listings() as $listing) {
            $out[(string) $listing->commune] = $listing;
        }

        return $out;
    }
}
