<?php

declare(strict_types=1);

namespace RentWatch\Tests\Adapters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RentWatch\Adapters\EmailAlertSource;
use RentWatch\Adapters\Http\HttpClient;
use RentWatch\Adapters\Http\HttpError;
use RentWatch\Adapters\Http\HttpRequest;
use RentWatch\Adapters\Http\HttpResponse;
use RentWatch\Adapters\Http\Robots;
use RentWatch\Adapters\HttpJsonSource;
use RentWatch\Adapters\Mail\EmailMessage;
use RentWatch\Adapters\Mail\FileMailbox;
use RentWatch\Adapters\Mail\Mailbox;
use RentWatch\Adapters\Mail\MailboxError;
use RentWatch\Adapters\SourceError;
use RentWatch\Config\ConfigLoader;
use RentWatch\Config\FieldMap;
use RentWatch\Config\SourceDefinition;
use RentWatch\Core\Notify\ChannelError;
use RentWatch\Core\Notify\FileTransport;
use RentWatch\Core\Notify\SmtpTransport;
use RentWatch\Core\Outcome;
use RentWatch\Core\Tenure;
use RentWatch\Store\Store;

/**
 * The network adapters, exercised offline.
 *
 * These exist because a distinction got blurred and needed correcting: `CLAUDE.md` hard rule 1
 * forbids writing an ENDPOINT from memory, and says nothing about the transport. The adapters were
 * called "blocked" when only the URL in `config/sources.json` was. Every class here is fully tested
 * against a fake client or a directory of `.eml` files, and the only untested strip is the socket.
 *
 * Categories: **robots** (hard rule 5) · **http** (hard rule 3, honest identification) ·
 * **mime** (charset, folding, multipart) · **email extraction** · **transports** (secrets).
 */
final class NetworkAdaptersTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    private ?string $dbPath = null;

    protected function tearDown(): void
    {
        if ($this->dbPath !== null) {
            foreach (['', '-wal', '-shm'] as $suffix) {
                @unlink($this->dbPath . $suffix);
            }
            $this->dbPath = null;
        }
    }

    private function store(): Store
    {
        $this->dbPath = sys_get_temp_dir() . '/rentwatch-net-' . bin2hex(random_bytes(8)) . '.sqlite3';

        return Store::open($this->dbPath);
    }

    // ---------------------------------------------------------------- robots (hard rule 5)

    #[DataProvider('robotsCases')]
    public function testRobots(string $file, string $path, bool $allowed, string $why): void
    {
        self::assertSame($allowed, Robots::parse($file, 'rent-watch')->allows($path), $why);
    }

    /** @return iterable<string, array{string,string,bool,string}> */
    public static function robotsCases(): iterable
    {
        yield 'the CDC Habitat shape from Q15' => [
            "User-agent: *\nDisallow: /Recherche/show/",
            '/Recherche/show/12345',
            false,
            'the exact rule Q15 measured — this source must not be polled until its endpoint is known to sit outside it',
        ];
        yield 'a sibling path is unaffected' => [
            "User-agent: *\nDisallow: /Recherche/show/",
            '/api/annonces',
            true,
            'the disallow is a prefix, not a whole-site ban',
        ];
        yield 'an empty Disallow allows everything' => [
            "User-agent: *\nDisallow:",
            '/anything',
            true,
            'the documented way to opt out — reading it as "disallow /" would ban a site that welcomed us',
        ];
        yield 'Allow beats a shorter Disallow' => [
            "User-agent: *\nDisallow: /search\nAllow: /search/api",
            '/search/api/v1',
            true,
            'longest match wins, which is the convention a site author writes against',
        ];
        yield 'a longer Disallow beats a shorter Allow' => [
            "User-agent: *\nAllow: /\nDisallow: /search/private",
            '/search/private/x',
            false,
            '',
        ];
        yield 'a wildcard in the middle' => [
            "User-agent: *\nDisallow: /*/private",
            '/anything/private',
            false,
            'real files use wildcards heavily',
        ];
        yield 'an anchored rule does not match a longer path' => [
            "User-agent: *\nDisallow: /search$",
            '/search/results',
            true,
            '$ anchors the end',
        ];
        yield 'a group naming us beats the wildcard group ABOVE it' => [
            "User-agent: *\nDisallow: /\n\nUser-agent: rent-watch\nDisallow: /admin",
            '/annonces',
            true,
            'a single pass taking the first matching group would let the `*` block mask ours',
        ];
        yield 'the query string is part of what a rule can match' => [
            "User-agent: *\nDisallow: /*?search=",
            '/list?search=t4',
            false,
            'Disallow: /*?search= is a real shape, so the query must not be stripped before matching',
        ];
        yield 'a file about someone else says nothing about us' => [
            "User-agent: GPTBot\nDisallow: /",
            '/annonces',
            true,
            'no group applies — the ordinary no-restrictions case',
        ];
    }

    public function testAnUnreadableRobotsFileDisallowsEverything(): void
    {
        // FAIL CLOSED, which is the opposite of the usual convention and deliberate: a false
        // "disallowed" costs one source staying off until someone looks; a false "allowed" costs
        // polling a site that asked us not to, with an honest User-Agent naming whose tool it is.
        self::assertFalse(Robots::unavailable()->allows('/anything'));
    }

    public function testPathOfKeepsTheQueryAndDefaultsToRoot(): void
    {
        self::assertSame('/a/b?x=1', Robots::pathOf('https://example.test/a/b?x=1'));
        self::assertSame('/', Robots::pathOf('https://example.test'));
    }

    // ---------------------------------------------------------------- http (hard rule 3)

    private function httpSource(HttpClient $client, array $overrides = [], ?Robots $robots = null): HttpJsonSource
    {
        $definition = new SourceDefinition(
            name: $overrides['name'] ?? 'net',
            enabled: true,
            family: 'institutional',
            type: 'json',
            mixedTenure: true,
            url: $overrides['url'] ?? 'https://example.test/api/search',
            itemsPath: $overrides['itemsPath'] ?? 'results.items',
            map: new FieldMap(ref: ['id'], title: ['title'], commune: ['city'], rent: ['rent'], chargesIncluded: true),
        );

        return new HttpJsonSource($definition, $this->store(), $client, $robots);
    }

    public function testAWorkingEndpointMapsListingsThroughTheSameCodeTheFixtureAdapterUses(): void
    {
        $client = new FakeHttpClient(new HttpResponse(200, (string) json_encode([
            'results' => ['items' => [
                ['id' => 'x1', 'title' => 'T4 Sartrouville', 'city' => 'Sartrouville', 'rent' => '1 450 €'],
            ]],
        ])));

        $listings = $this->httpSource($client)->fetch();

        self::assertCount(1, $listings);
        self::assertSame('x1', $listings[0]->externalId);
        self::assertSame(1450, $listings[0]->rentCc, 'the shared Payload parser handles the French formatting');
    }

    public function testANonSuccessStatusIsAFailureNotAnEmptyResult(): void
    {
        // Hard rule 3. `docs/SOURCES.md` records five portals answering 403 to a plain client; that
        // fact must reach the run log, because it is what says "use the email route" rather than
        // "the market is quiet".
        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~HTTP 403~');

        $this->httpSource(new FakeHttpClient(new HttpResponse(403, 'nope')))->fetch();
    }

    public function testA403SuggestsTheEmailRouteRatherThanAWorkaround(): void
    {
        // Hard rule 5: never propose CAPTCHA solving, proxy rotation or fingerprint spoofing. The
        // message is where that guidance actually reaches whoever is debugging.
        try {
            $this->httpSource(new FakeHttpClient(new HttpResponse(403, '')))->fetch();
            self::fail('expected a SourceError');
        } catch (SourceError $e) {
            self::assertStringContainsString('email-alert', $e->getMessage());
        }
    }

    public function testATransportFailureIsWrappedAsASourceError(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~timed out~');

        $this->httpSource(new FakeHttpClient(null, new HttpError('the request timed out')))->fetch();
    }

    public function testMalformedJsonIsALoudFailure(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~not valid JSON~');

        $this->httpSource(new FakeHttpClient(new HttpResponse(200, '{"results": [')))->fetch();
    }

    public function testAMovedItemsPathThrowsRatherThanReadingAsAQuietMarket(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~is absent from the response~');

        $this->httpSource(new FakeHttpClient(new HttpResponse(200, '{"data":{"list":[]}}')))->fetch();
    }

    public function testAPlaceholderUrlIsRefusedEvenWhenTheSourceIsBuiltInCode(): void
    {
        // The config loader already refuses `enabled: true` next to REMPLACER; this second check
        // covers a source constructed in code, which is how a test or a future `--dry-run` builds one.
        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~hard rule 1~');

        $this->httpSource(new FakeHttpClient(new HttpResponse(200, '{}')), ['url' => 'https://example.test/REMPLACER'])->fetch();
    }

    public function testADisallowedPathIsNeverFetched(): void
    {
        $client = new FakeHttpClient(new HttpResponse(200, '{"results":{"items":[]}}'));
        $robots = Robots::parse("User-agent: *\nDisallow: /api/");

        try {
            $this->httpSource($client, [], $robots)->fetch();
            self::fail('expected a SourceError');
        } catch (SourceError $e) {
            self::assertStringContainsString('robots.txt', $e->getMessage());
            self::assertSame(0, $client->calls, 'the request must not be made at all');
        }
    }

    // ---------------------------------------------------------------- MIME

    public function testALatin1MessageIsReadAsCp1252SoTheEuroSignSurvives(): void
    {
        // THE BUG RUNNING IT EXPOSED. Mail declaring ISO-8859-1 is almost always CP1252, and they
        // differ exactly in 0x80-0x9F — where `€` lives. Under strict Latin-1 the euro sign becomes
        // an invisible control character, and EVERY rent pattern requires a currency marker, so the
        // rent came out NULL on an alert that stated it plainly.
        $raw = "Content-Type: text/plain; charset=ISO-8859-1\n"
            . "Content-Transfer-Encoding: quoted-printable\n\n"
            . "Loyer : 1 450 =80 charges comprises\nSurface : 88 m=B2\n";

        $message = EmailMessage::parse($raw);

        self::assertStringContainsString('€', $message->body);
        self::assertStringContainsString('m²', $message->body);
    }

    public function testAnEncodedWordSubjectIsDecoded(): void
    {
        $raw = "Subject: =?UTF-8?Q?Nouvelle_annonce_:_T4_=C3=A0_Sartrouville?=\n\nbody";

        self::assertSame('Nouvelle annonce : T4 à Sartrouville', EmailMessage::parse($raw)->subject());
    }

    public function testAFoldedHeaderIsJoinedRatherThanTruncated(): void
    {
        // Real subjects fold constantly; a parser that ignores continuation lines truncates them all.
        $raw = "Subject: Nouvelle annonce\n  pour votre alerte T4\n\nbody";

        self::assertSame('Nouvelle annonce pour votre alerte T4', EmailMessage::parse($raw)->subject());
    }

    public function testThePlainTextPartIsPreferredOverHtml(): void
    {
        $raw = "Content-Type: multipart/alternative; boundary=\"b\"\n\n"
            . "--b\nContent-Type: text/html\n\n<p>HTML VERSION</p>\n"
            . "--b\nContent-Type: text/plain\n\nTEXTE BRUT\n--b--";

        self::assertStringContainsString('TEXTE BRUT', EmailMessage::parse($raw)->body);
        self::assertStringNotContainsString('HTML VERSION', EmailMessage::parse($raw)->body);
    }

    public function testAnHtmlOnlyMessageHasItsBlockTagsTurnedIntoNewlines(): void
    {
        // Otherwise `<li>Chatou</li><li>Houilles</li>` collapses into `ChatouHouilles`, which
        // matches neither commune.
        $raw = "Content-Type: text/html; charset=UTF-8\n\n<ul><li>Chatou</li><li>Houilles</li></ul>";

        $body = EmailMessage::parse($raw)->body;

        self::assertStringContainsString('Chatou', $body);
        self::assertStringNotContainsString('ChatouHouilles', $body);
    }

    public function testCrlfLineEndingsDoNotBreakTheHeaderBodySplit(): void
    {
        $raw = "Subject: Test\r\nContent-Type: text/plain\r\n\r\nle corps du message";

        self::assertSame('Test', EmailMessage::parse($raw)->subject());
        self::assertStringContainsString('le corps', EmailMessage::parse($raw)->body);
    }

    public function testBase64BodiesAreDecoded(): void
    {
        $raw = "Content-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: base64\n\n"
            . base64_encode('Loyer : 1450 € CC');

        self::assertStringContainsString('1450 €', EmailMessage::parse($raw)->body);
    }

    // ---------------------------------------------------------------- email extraction

    private function emailSource(array $params = []): EmailAlertSource
    {
        $definition = new SourceDefinition(
            name: 'email_demo',
            enabled: true,
            family: 'private',
            type: 'email_alert',
            mixedTenure: true,
            defaultTenure: Tenure::LIBRE,
            params: $params + ['from' => 'example-portal.test', 'link_host' => 'example-portal.test'],
            map: new FieldMap(ref: ['url'], chargesIncluded: true),
        );

        return new EmailAlertSource(
            $definition,
            $this->store(),
            new FileMailbox(self::ROOT . '/tests/fixtures/mailbox'),
            ConfigLoader::loadCriteria(self::ROOT . '/config/criteria.json')->communeLabels,
        );
    }

    public function testTheAlertFixtureYieldsOneListingPerRealLink(): void
    {
        $listings = $this->emailSource()->fetch();

        self::assertCount(2, $listings, 'the unsubscribe link must not become a listing');
        foreach ($listings as $listing) {
            self::assertStringNotContainsString('desabonnement', (string) $listing->url);
        }
    }

    public function testFactsAreExtractedFromTheAlertText(): void
    {
        $byId = [];
        foreach ($this->emailSource()->fetch() as $l) {
            $byId[basename($l->externalId)] = $l;
        }

        $listing = $byId['12345'];
        self::assertSame(1450, $listing->rentCc);
        self::assertSame(88.0, $listing->surfaceM2);
        self::assertSame(4, $listing->rooms);
        self::assertSame('Sartrouville', $listing->commune);
        self::assertSame('78500', $listing->postcode);
    }

    public function testTrackingParametersAreStrippedFromTheIdentity(): void
    {
        // Alert links carry per-send tracking parameters. Keying on them would make the same flat
        // new on every digest — which is the "notifies forever" failure `ListingMapper` refuses a
        // synthetic id for.
        $ids = array_map(static fn ($l): string => $l->externalId, $this->emailSource()->fetch());

        foreach ($ids as $id) {
            self::assertStringNotContainsString('utm_', $id);
            self::assertStringNotContainsString('?', $id);
        }
    }

    public function testAnAnahAlertOnAPrivatePortalIsREJECTED(): void
    {
        // THE §1 BREACH A REVIEW FOUND, demonstrated closed through the real email path. ANAH
        // conventionné is signed by private individual landlords and advertised on exactly these
        // portals; before the Loc'Avantages label existed this classified LIBRE / 50 / MATCH.
        $source = $this->emailSource();
        $classifier = new \RentWatch\Core\TenureClassifier();
        $engine = new \RentWatch\Core\CriteriaEngine(ConfigLoader::loadCriteria(self::ROOT . '/config/criteria.json'));

        $found = false;
        foreach ($source->fetch() as $listing) {
            if (!str_contains($listing->externalId, '67890')) {
                continue;
            }

            $found = true;
            $classification = $classifier->classify($listing, $source->profile());

            self::assertSame(Tenure::ANAH, $classification->tenure);
            self::assertSame(Outcome::REJECT, $engine->judge($listing, $classification, null)->outcome);
        }

        self::assertTrue($found, 'the ANAH fixture must be present — it is the regression this pins');
    }

    public function testAMessageFromSomewhereElseIsIgnored(): void
    {
        // A shared mailbox receives alerts from several portals. Attributing every message to
        // whichever source polls first would give each listing the wrong `mixed_tenure` flag —
        // which is the §1 switch.
        self::assertSame([], $this->emailSource(['from' => 'un-autre-portail.test'])->fetch());
    }

    public function testAMissingMailboxDirectoryIsALoudFailure(): void
    {
        $this->expectException(MailboxError::class);
        $this->expectExceptionMessageMatches('~no such mailbox directory~');

        (new FileMailbox('/nonexistent/mailbox'))->fetchRecent();
    }

    public function testAMailboxFailureBecomesASourceErrorNotAnEmptyList(): void
    {
        $definition = new SourceDefinition(
            name: 'broken_mail',
            enabled: true,
            family: 'private',
            type: 'email_alert',
            mixedTenure: true,
            map: new FieldMap(ref: ['url']),
        );

        $this->expectException(SourceError::class);

        (new EmailAlertSource($definition, $this->store(), new FileMailbox('/nonexistent')))->fetch();
    }

    // ---------------------------------------------------------------- transports

    public function testTheFileTransportWritesAReadableMessage(): void
    {
        $dir = sys_get_temp_dir() . '/rentwatch-outbox-' . bin2hex(random_bytes(6));
        $transport = new FileTransport($dir);

        self::assertNull($transport->check());
        $transport->send('moi@example.test', 'Sujet', 'Le corps', ['From' => 'rent-watch@localhost']);

        $files = glob($dir . '/*.eml') ?: [];
        self::assertCount(1, $files);
        self::assertStringContainsString('Le corps', (string) file_get_contents($files[0]));

        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    public function testSmtpRefusesPlaintextCredentialsToARemoteHost(): void
    {
        // Not a warning. A credential on a plaintext connection to a remote host is a credential on
        // the wire, and "the user configured it" is not a reason to help.
        $problem = (new SmtpTransport('smtp.example.test', 25, 'user', 'pw', security: 'none'))->check();

        self::assertNotNull($problem);
        self::assertStringContainsString('loopback', (string) $problem);
    }

    public function testSmtpPermitsPlaintextToLoopbackForALocalTestServer(): void
    {
        self::assertNull((new SmtpTransport('localhost', 1025, security: 'none'))->check());
    }

    public function testSmtpRefusesAUserWithNoPassword(): void
    {
        $problem = (new SmtpTransport('smtp.example.test', 587, 'user', ''))->check();

        self::assertNotNull($problem);
        self::assertStringContainsString('SMTP_PASSWORD', (string) $problem);
    }

    public function testAnSmtpErrorMasksThePasswordInBothItsForms(): void
    {
        // The base64 form is what actually goes on the wire and what a server echoes back in a
        // rejection, so masking only the plaintext would leave the transmitted credential exposed.
        $password = 'hunter2-app-password';

        $e = new ChannelError('email', 'SMTP rejected: AUTH LOGIN ' . base64_encode($password), null, [
            $password,
            base64_encode($password),
        ]);

        self::assertStringNotContainsString($password, $e->getMessage());
        self::assertStringNotContainsString(base64_encode($password), $e->getMessage());
    }
}

/** An HTTP client the test drives. The seam that makes a network adapter testable offline. */
final class FakeHttpClient implements HttpClient
{
    public int $calls = 0;

    public ?HttpRequest $lastRequest = null;

    public function __construct(
        private readonly ?HttpResponse $response,
        private readonly ?HttpError $error = null,
    ) {}

    public function send(HttpRequest $request): HttpResponse
    {
        ++$this->calls;
        $this->lastRequest = $request;

        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->response ?? new HttpResponse(200, '');
    }
}
