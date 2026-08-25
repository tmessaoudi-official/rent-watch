<?php

declare(strict_types=1);

namespace RentWatch\Tests\Adapters;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RentWatch\Adapters\EmailAlertSource;
use RentWatch\Adapters\Mail\FileMailbox;
use RentWatch\Config\ConfigLoader;
use RentWatch\Core\Outcome;
use RentWatch\Core\Tenure;
use RentWatch\Core\TenureClassifier;
use RentWatch\Store\Store;

/**
 * The first REAL portal alerts, parsed with the shipped `seloger` config.
 *
 * `spec/PROJECT_BRIEF.md` §11 asks for one frozen payload per source, offline, no network. These
 * two were captured from a live subscription on 2026-08-25 and scrubbed by `tools/scrub-eml.php`,
 * which refuses to write while the address, the ESP list ids or any original tracking token
 * survives.
 *
 * **The structure is deliberately NOT tidied.** The awkward `=_?:` MIME boundary, the 8bit UTF-8
 * transfer encoding, the folded headers and the RFC 2047 subject split mid-word are each a parser
 * defect this project has already had — two of them found by these very files. Rewriting the
 * messages into something well-behaved would delete the evidence and leave the fixture proving
 * only that a tidy message parses.
 *
 * Everything here reads the SHIPPED `config/sources.json` block rather than an inline copy, so a
 * change to the field map fails here rather than in production.
 */
#[CoversClass(EmailAlertSource::class)]
final class SelogerFixtureTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    private string $dbPath = '';

    protected function tearDown(): void
    {
        if ($this->dbPath !== '') {
            @unlink($this->dbPath);
        }
    }

    /** @return array<string,\RentWatch\Core\RawListing> keyed by commune */
    private function listings(): array
    {
        $this->dbPath = sys_get_temp_dir() . '/rentwatch-slg-' . bin2hex(random_bytes(8)) . '.sqlite3';

        $definition = ConfigLoader::loadSources(self::ROOT . '/config/sources.json')['seloger'];
        $criteria = ConfigLoader::loadCriteria(self::ROOT . '/config/criteria.json');

        $source = new EmailAlertSource(
            $definition,
            Store::open($this->dbPath),
            new FileMailbox(self::ROOT . '/tests/fixtures/seloger'),
            $criteria->communeLabels,
        );

        $out = [];
        foreach ($source->fetch() as $listing) {
            $out[(string) $listing->postcode] = $listing;
        }

        return $out;
    }

    /**
     * Two messages, two cards, two listings — and NOT one listing per link.
     *
     * The two fixtures carry 16 and 19 links respectively, every one of them a
     * `click.by.seloger.com` redirect. Under link-identity that is 35 listings sharing a single id
     * and a single flat's facts. This assertion is the one that would go red if `card_separator`
     * were dropped from the config.
     */
    public function testEachMessageYieldsExactlyOneListing(): void
    {
        self::assertCount(2, $this->listings(), 'two alerts, two flats');
    }

    /**
     * The alert's own card, read field by field.
     *
     * `Appartement … 3 pièce(s) 45…` is the title as SeLoger truncates it; the surface comes from
     * the card's own `3 pièces . 44,71 m²` line, NOT from the `45` in the truncated title.
     */
    public function testTheAlertCardIsReadCorrectly(): void
    {
        $listing = $this->listings()['78700'] ?? null;

        self::assertNotNull($listing, 'the Conflans-Sainte-Honorine card');
        self::assertSame(980, $listing->rentCc, 'charges comprises, from the card not the message');
        self::assertNull($listing->rentHc);
        self::assertSame(44.71, $listing->surfaceM2, 'the card line, not the 45 in the truncated title');
        self::assertSame(3, $listing->rooms);
        self::assertStringStartsWith('Appartement', (string) $listing->title);
    }

    /** The exclusivity's card. Same selectors, different template framing — one format, not two. */
    public function testTheExclusivityCardIsReadCorrectly(): void
    {
        $listing = $this->listings()['91410'] ?? null;

        self::assertNotNull($listing, 'the Dourdan card');
        self::assertSame(915, $listing->rentCc);
        self::assertSame(52.37, $listing->surfaceM2);
        self::assertSame(3, $listing->rooms);
    }

    /**
     * The two flats do not share an identity, which is the whole reason `id_from: content` exists.
     *
     * Under the link-identity path both would be `https://click.by.seloger.com/` — the second flat
     * silently swallowed as already seen, for ever.
     */
    public function testTheTwoFlatsHaveDistinctIdentities(): void
    {
        $ids = array_map(static fn ($l) => $l->externalId, $this->listings());

        self::assertCount(2, array_unique($ids));
        foreach ($ids as $id) {
            self::assertMatchesRegularExpression('~^[0-9a-f]{40}$~', $id, 'content-addressed, not a URL');
        }
    }

    /**
     * The tracking link is carried UNRESOLVED, and it is carried.
     *
     * Both halves matter. Dropping it would leave a notification with nothing to click; resolving
     * it would be a request per listing on a subscriber-bound token (hard rule 5).
     */
    public function testTheTrackingLinkIsCarriedButNotResolved(): void
    {
        foreach ($this->listings() as $listing) {
            self::assertNotNull($listing->url, 'a notification needs something to click');
            self::assertStringContainsString('click.by.seloger.com', (string) $listing->url);
        }
    }

    /**
     * A card's description is its own card, not the message and not the trailer.
     *
     * The Cityloger ruling on a new surface. Every alert ends in a CNIL notice, a postal address
     * and a campaign code, and those are furniture by construction — the same class of text that
     * sent 14 of 16 correctly-badged CDC listings to the digest.
     */
    public function testTheDescriptionExcludesTheTrailer(): void
    {
        foreach ($this->listings() as $listing) {
            self::assertStringNotContainsString('Conformément à la loi', $listing->description);
            self::assertStringNotContainsString('rue des Italiens', $listing->description);
        }
    }

    /**
     * **The classifier's VERDICT on each real card, which is the guarantee that matters.**
     *
     * A first version of this test asserted that the word `plus` never appears in a card's text.
     * That is unachievable and wrong in kind: `plus` is one of the commonest words in French, and
     * SeLoger's own card carries the CTA `En savoir plus →`. Asserting the absence of a word is
     * asserting something about French; asserting the classifier's output is asserting the thing
     * §1 actually forbids.
     *
     * This is the FOURTH recorded instance of that vocabulary class — after CDC's `au plus près`
     * tooltip, Cityloger's `bailleur social`, and Logirep's `Plain-pied` filter facet. Each cost a
     * fix; this one cost only a test, because the classifier's `_text`-as-prose handling already
     * covers it. Which is precisely what the assertion below proves, on the real payload.
     */
    public function testTheClassifierReadsEveryRealCardAsEligible(): void
    {
        $classifier = new TenureClassifier();
        $definition = ConfigLoader::loadSources(self::ROOT . '/config/sources.json')['seloger'];

        foreach ($this->listings() as $postcode => $listing) {
            $verdict = $classifier->classify($listing, $definition->profile());

            self::assertNotSame(
                Outcome::REJECT,
                $verdict->outcome,
                "card {$postcode} was rejected on tenure — SeLoger is a private-market portal, so "
                    . 'this means UI furniture is being read as a financing regime',
            );

            self::assertNotContains(
                $verdict->tenure,
                [Tenure::SOCIAL],
                "card {$postcode} classified as social housing from a private-market alert",
            );
        }
    }

    /**
     * An explicit excluded LABEL in a card is still refused, so the test above cannot pass by the
     * classifier having simply stopped looking at this source.
     *
     * The counterweight matters because `mixed_tenure: false` plus `default_tenure: LIBRE` is a
     * configuration that could plausibly short-circuit tenure reasoning altogether. It does not,
     * and this is what says so.
     */
    public function testAnExplicitExcludedLabelInACardIsStillRefused(): void
    {
        $listing = $this->listings()['78700'] ?? null;
        self::assertNotNull($listing);

        $poisoned = new \RentWatch\Core\RawListing(
            sourceName: $listing->sourceName,
            externalId: $listing->externalId,
            title: $listing->title,
            description: $listing->description . "\nLogement conventionné PLUS, demande de logement social.",
            fields: $listing->fields,
            url: $listing->url,
            commune: $listing->commune,
            postcode: $listing->postcode,
            rentCc: $listing->rentCc,
            surfaceM2: $listing->surfaceM2,
            rooms: $listing->rooms,
        );

        $definition = ConfigLoader::loadSources(self::ROOT . '/config/sources.json')['seloger'];
        $verdict = (new TenureClassifier())->classify($poisoned, $definition->profile());

        self::assertSame(Outcome::REJECT, $verdict->outcome, 'an explicit PLUS label must still veto');
    }

    /**
     * The fixtures carry no personal data. Asserted here as well as in the scrubber, because the
     * scrubber runs once by hand and this runs on every push.
     */
    public function testTheFixturesCarryNoSubscriberIdentity(): void
    {
        foreach (glob(self::ROOT . '/tests/fixtures/seloger/*.eml') ?: [] as $file) {
            $raw = (string) file_get_contents($file);
            $name = basename($file);

            self::assertStringNotContainsStringIgnoringCase('takieddine', $raw, "{$name} leaks the address");
            self::assertStringNotContainsStringIgnoringCase('gmail', $raw, "{$name} leaks the mailbox host");
            self::assertDoesNotMatchRegularExpression('~51000[1-9]~', $raw, "{$name} leaks an ESP list id");

            preg_match_all('~qs=([A-Za-z0-9_\-]+)~', $raw, $tokens);
            foreach ($tokens[1] as $token) {
                self::assertStringStartsWith('FIXTURE', $token, "{$name} leaks a live tracking token");
            }
        }
    }

    /**
     * The structure that made these fixtures worth keeping is still in them.
     *
     * A future "tidy up the fixtures" would silently retire the two regression tests they carry:
     * the MIME preamble that made every real alert parse to nothing, and the RFC 2047 subject split
     * mid-word that decoded to `exclusivit és`.
     */
    public function testTheAwkwardStructureIsPreserved(): void
    {
        $alert = (string) file_get_contents(self::ROOT . '/tests/fixtures/seloger/2026-08-25-001-alert.eml');
        $exclusive = (string) file_get_contents(self::ROOT . '/tests/fixtures/seloger/2026-08-25-002-exclusive.eml');

        self::assertStringContainsString('This is a multi-part message in MIME format.', $alert, 'the preamble');
        self::assertStringContainsString('boundary="8PaVqvzMwU9R=_?:"', $alert, 'the awkward boundary');
        self::assertStringContainsString("exclusivit?=\r\n =?UTF-8?Q?", $exclusive, 'the mid-word 2047 split');
    }
}
