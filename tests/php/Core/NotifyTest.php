<?php

declare(strict_types=1);

namespace RentWatch\Tests\Core;

use PHPUnit\Framework\TestCase;
use RentWatch\Core\Notify\Channel;
use RentWatch\Core\Notify\ChannelError;
use RentWatch\Core\Notify\ConsoleChannel;
use RentWatch\Core\Notify\EmailChannel;
use RentWatch\Core\Notify\FileTransport;
use RentWatch\Core\Notify\Formatter;
use RentWatch\Core\Notify\Notification;
use RentWatch\Core\Notify\NotificationKind;
use RentWatch\Core\Notify\Notifier;
use RentWatch\Core\Notify\NtfyChannel;
use RentWatch\Core\Notify\Priority;
use RentWatch\Core\RawListing;
use RentWatch\Core\Redact;
use RentWatch\Core\SourceHealth;
use RentWatch\Core\SourceStatus;
use RentWatch\Core\Verdict;

/**
 * The notification layer — the product's only user-facing output.
 *
 * Categories: **payload** (what a phone lock screen actually shows) · **routing** (priority, and the
 * Q31 confidence gate) · **refusal scope** (Q28) · **delivery** (a failure is never silent) ·
 * **secrets** (the ntfy topic, which no name-based masker can reach).
 */
final class NotifyTest extends TestCase
{
    private function listing(array $o = []): RawListing
    {
        // `array_key_exists`, not `??`. The null coalescer swallows an EXPLICIT null override, so
        // `['rentCc' => null]` silently kept the 1450 default and the hors-charges test asserted
        // against a listing that had a CC rent after all. A helper that quietly ignores what a test
        // asked for makes the test prove something other than what it says.
        $pick = static fn (string $key, mixed $default): mixed
            => array_key_exists($key, $o) ? $o[$key] : $default;

        return new RawListing(
            sourceName: $pick('source', 'inli'),
            externalId: $pick('id', 'x-1'),
            title: $pick('title', 'SUPERBE APPARTEMENT RARE SUR LE MARCHE'),
            url: $pick('url', 'https://example.test/a/1'),
            commune: $pick('commune', 'Sartrouville'),
            postcode: $pick('postcode', '78500'),
            rentCc: $pick('rentCc', 1450),
            rentHc: $pick('rentHc', null),
            surfaceM2: $pick('surface', 88.0),
            rooms: $pick('rooms', 4),
            floor: $pick('floor', null),
            hasElevator: $pick('hasElevator', null),
        );
    }

    // ---------------------------------------------------------------- payload

    public function testTheHeadlineLeadsWithFactsNotTheSourcesMarketing(): void
    {
        // A notification is read on a lock screen. The source's own title is written to sell, so it
        // leads with adjectives and buries the commune, the size and the rent — which are the three
        // things that decide whether the developer opens it.
        $n = (new Formatter())->match(
            $this->listing(),
            Verdict::matched(82, ['mention explicite « LLI »'], true),
        );

        // The postcode joined the commune on 2026-08-22 — see
        // testTheHeadlineNamesThePostcodeAsWellAsTheCommune, which owns that decision. What this
        // test is about is unchanged and is the reason it keeps its own name: the source's TITLE is
        // still absent, and still must be.
        self::assertSame('82/100 — Sartrouville 78500 · T4 88 m² · 1450 € CC', $n->title);
        self::assertStringNotContainsString('SUPERBE', $n->title);
    }

    public function testTheHeadlineNamesThePostcodeAsWellAsTheCommune(): void
    {
        // Added 2026-08-22, on a real complaint: In'li ships no title and the headline carried the
        // commune alone, so a notification read as a bare price and a link. A commune name without
        // its postcode is genuinely ambiguous in Île-de-France — there is a Neuilly in three
        // departements — and the postcode is the one fact every source already provides.
        $n = (new Formatter())->match(
            $this->listing(),
            Verdict::matched(82, ['mention explicite « LLI »'], true),
        );

        self::assertSame('82/100 — Sartrouville 78500 · T4 88 m² · 1450 € CC', $n->title);
    }

    public function testTheFactsLineNamesTheDepartmentAndWhatIsKnownAboutTheBuilding(): void
    {
        // Everything on this line was ALREADY extracted and simply never shown. It costs no request
        // and no new parsing — which is why it ships before the detail-page hydration that floor and
        // lift need on the sources that do not put them on the card.
        $n = (new Formatter())->match(
            $this->listing(['floor' => 2, 'hasElevator' => true]),
            Verdict::matched(82, ['mention explicite « LLI »'], true),
        );

        self::assertContains('Yvelines (78) · 2e étage · avec ascenseur', $n->reasons);
    }

    public function testTheGroundFloorIsSaidRatherThanTreatedAsAbsent(): void
    {
        // Hard rule 9 at the display layer: `floor === 0` is falsy and REAL. A RDC flat that
        // silently loses its floor is the same defect as one rejected for not stating it.
        $n = (new Formatter())->match(
            $this->listing(['floor' => 0]),
            Verdict::matched(50, [], true),
        );

        self::assertContains('Yvelines (78) · RDC', $n->reasons);
    }

    public function testAnUNMENTIONEDLiftIsNotReportedAsAbsentOne(): void
    {
        // `null` is not `false`. "sans ascenseur" about a building nobody described is a fact the
        // notification invented, and it is the kind that makes someone skip a flat that has one.
        $withoutInfo = (new Formatter())->match($this->listing(), Verdict::matched(50, [], true));
        $knownAbsent = (new Formatter())->match(
            $this->listing(['hasElevator' => false]),
            Verdict::matched(50, [], true),
        );

        self::assertContains('Yvelines (78)', $withoutInfo->reasons);
        self::assertContains('Yvelines (78) · sans ascenseur', $knownAbsent->reasons);
    }

    public function testAListingOutsideTheKnownDepartmentsStillFormats(): void
    {
        // Logirep publishes nationally. Nothing outside Île-de-France can match today, but a
        // formatter that throws on an unexpected postcode would take the whole pass down for a
        // listing it was only ever going to reject.
        $n = (new Formatter())->match(
            $this->listing(['postcode' => '33000', 'commune' => 'Bordeaux']),
            Verdict::matched(50, [], true),
        );

        self::assertSame('50/100 — Bordeaux 33000 · T4 88 m² · 1450 € CC', $n->title);
        foreach ($n->reasons as $reason) {
            self::assertStringNotContainsString('(33)', $reason, 'an unknown departement is omitted, never guessed');
        }
    }

    public function testAnUnlocatedListingIsNamedByItsTitleRatherThanByThePlaceholder(): void
    {
        // `commune inconnue` is a label for the ABSENCE of information, and printing it while a real
        // title sits unused throws away the only human-readable fact the notification had. This is
        // the standing shape of every pre-schema-v7 row `scout digest` rescues: its `listings` row
        // holds a title and no commune, so before this rule those entries announced themselves as
        // `commune inconnue · 1005 € CC` — a rescue nobody can act on.
        $n = (new Formatter())->match(
            $this->listing(['commune' => null, 'postcode' => null, 'title' => 'T3 à Longjumeau']),
            Verdict::matched(50, [], true),
        );

        self::assertStringContainsString('T3 à Longjumeau', $n->title);
        self::assertStringNotContainsString('commune inconnue', $n->title);
    }

    public function testThePlaceholderIsStillUsedWhenThereIsNoTitleEither(): void
    {
        // The counterweight. In'li ships no title at all, so the placeholder must survive as the
        // honest answer when there genuinely is nothing — a blank leading segment would read as a
        // formatting bug rather than as missing data.
        $n = (new Formatter())->match(
            $this->listing(['commune' => null, 'postcode' => null, 'title' => '   ']),
            Verdict::matched(50, [], true),
        );

        self::assertStringContainsString('commune inconnue', $n->title);
    }

    public function testAnHorsChargesRentIsFLAGGEDRatherThanShownAsIfComparable(): void
    {
        // A 1750 € HC flat is roughly 1900 € CC. Showing it as "1750 €" next to an 1800 € budget
        // invites exactly the wrong conclusion.
        $n = (new Formatter())->match(
            $this->listing(['rentCc' => null, 'rentHc' => 1750]),
            Verdict::matched(60, [], false),
        );

        self::assertStringContainsString('1750 € HC', $n->title);
        self::assertStringNotContainsString('CC', $n->title);
    }

    public function testAKnownDuplicateIsShownRatherThanDropped(): void
    {
        // The same flat on two portals is a second application route. A silently discarded duplicate
        // is indistinguishable from a listing that was never fetched.
        $n = (new Formatter())->match(
            $this->listing(),
            Verdict::matched(70, ['mention explicite « LLI »'], false),
            ['leboncoin:zz9'],
        );

        self::assertContains('également publié sur : leboncoin:zz9', $n->reasons);
    }

    public function testEveryMatchCarriesItsScoreAndItsReasons(): void
    {
        // spec/PROJECT_BRIEF.md §5. A notification saying "LLI, 0.9" is not actionable.
        $n = (new Formatter())->match(
            $this->listing(),
            Verdict::matched(82, ['champ structuré financement = LLI'], true),
        );

        self::assertSame(82, $n->score);
        self::assertContains('champ structuré financement = LLI', $n->reasons);
    }

    // ---------------------------------------------------------------- routing

    public function testAHighScoringConfidentMatchIsHighPriority(): void
    {
        $n = (new Formatter())->match($this->listing(), Verdict::matched(82, [], true));
        self::assertSame(Priority::HIGH, $n->priority);
    }

    public function testAMatchThatIsNotHighPriorityIsStillDeliveredImmediately(): void
    {
        // Nothing is batched (ruled 1c). The premise of the tool is that good stock goes within
        // hours, so a batching window would defeat it for the listings it exists to catch.
        $n = (new Formatter())->match($this->listing(), Verdict::matched(40, [], false));
        self::assertSame(Priority::NORMAL, $n->priority);
    }

    public function testARentDropCrossingTheCeilingIsAnnouncedAsANewMatch(): void
    {
        // Q33. A listing seen at 1810 € was disqualified, so nothing was sent. At 1795 € it is a
        // full match — and the reduction is only 15 €, which sits below BOTH rent-change
        // thresholds. Treating it as an ordinary price tweak loses the flat.
        $n = (new Formatter())->rentDrop($this->listing(), 1810, 1795, true);

        self::assertSame(Priority::HIGH, $n->priority);
        self::assertStringContainsString('PASSE SOUS LE PLAFOND', $n->title);
        self::assertContains('ce bien était écarté sur le loyer ; il est désormais dans le budget', $n->reasons);
    }

    public function testAnOrdinaryRentDropIsNormalPriority(): void
    {
        $n = (new Formatter())->rentDrop($this->listing(), 1500, 1400, false);

        self::assertSame(Priority::NORMAL, $n->priority);
        self::assertSame(NotificationKind::RENT_DROP, $n->kind);
    }

    public function testEveryAlertingSourceStatusCanBeFormatted(): void
    {
        // Q29: the 1c table routed SOURCE_BROKEN alone, while six statuses alert. NEVER_PRODUCED was
        // added precisely because it hid behind OK; deriving it and never sending it wastes it.
        $formatted = 0;

        foreach (SourceStatus::cases() as $status) {
            if (!$status->isAlerting()) {
                continue;
            }

            $n = (new Formatter())->sourceHealth(new SourceHealth(
                sourceName: 'inli',
                status: $status,
                detail: 'détail de test',
            ));

            self::assertSame(NotificationKind::SOURCE_HEALTH, $n->kind);
            self::assertStringContainsString($status->value, $n->title);
            ++$formatted;
        }

        self::assertGreaterThanOrEqual(5, $formatted, 'the alerting set shrank — check SourceStatus::isAlerting()');
    }

    public function testTheHeartbeatIsSentEvenWhenNothingMatched(): void
    {
        // Q27, and the whole reason it exists: a dead watcher and a quiet rental market both emit
        // nothing, so silence is only a signal if something breaks it on a schedule.
        $n = (new Formatter())->heartbeat(96, 0, [
            new SourceHealth(sourceName: 'inli', status: SourceStatus::OK),
        ], '2026-08-06T18:00:00Z');

        self::assertSame(NotificationKind::HEARTBEAT, $n->kind);
        self::assertStringContainsString('0 correspondance', $n->title);
        self::assertContains('toutes les sources sont OK', $n->reasons);
    }

    public function testTheHeartbeatNamesSourcesThatAreNotOk(): void
    {
        $n = (new Formatter())->heartbeat(96, 3, [
            new SourceHealth(sourceName: 'inli', status: SourceStatus::OK),
            new SourceHealth(sourceName: 'seqens', status: SourceStatus::BROKEN),
        ], '2026-08-06T18:00:00Z');

        self::assertStringContainsString('seqens (' . SourceStatus::BROKEN->value . ')', implode(' ', $n->reasons));
    }

    public function testTheDigestIsOneRollupRatherThanOneNotificationPerListing(): void
    {
        // What makes it a digest rather than a second notification stream — and the reason the
        // fail-closed rule can afford to send doubtful listings there at all.
        $n = (new Formatter())->digest([
            ['listing' => $this->listing(['id' => 'a']), 'verdict' => Verdict::digest(['régime indéterminé'])],
            ['listing' => $this->listing(['id' => 'b']), 'verdict' => Verdict::digest(['régime indéterminé'])],
        ]);

        self::assertSame(NotificationKind::DIGEST, $n->kind);
        self::assertSame(Priority::LOW, $n->priority);
        self::assertStringContainsString('2 annonce(s)', $n->title);
        self::assertCount(2, $n->reasons);
    }

    // ---------------------------------------------------------------- refusal scope (Q28)

    public function testNoUsableChannelIsAFatalRefusal(): void
    {
        $notifier = new Notifier([$this->brokenChannel('ntfy', 'NTFY_TOPIC is not set')]);

        $problem = $notifier->fatalProblem();
        self::assertNotNull($problem);
        self::assertStringContainsString('found and never delivered', (string) $problem);
        self::assertStringContainsString('NTFY_TOPIC is not set', (string) $problem);
    }

    public function testAnEmptyChannelListIsAlsoFatal(): void
    {
        self::assertNotNull((new Notifier([]))->fatalProblem());
    }

    public function testOneBrokenChannelDoesNotStopTheProcessWhenAnotherWorks(): void
    {
        // The Q28 correction. An expired SMTP password must not take down the sources, the seen-set
        // and the price history to punish one channel.
        $notifier = new Notifier([
            $this->brokenChannel('email', 'SMTP_TO is not set'),
            new ConsoleChannel($this->tempStream()),
        ]);

        self::assertNull($notifier->fatalProblem());
        self::assertSame(['email' => 'SMTP_TO is not set'], $notifier->disabledReport());
    }

    public function testConsoleAloneIsNotARemoteChannel(): void
    {
        // Under Q8's Docker deployment, `console` is the container log — which is not a
        // notification channel for anyone.
        $notifier = new Notifier([new ConsoleChannel($this->tempStream())]);

        self::assertNull($notifier->fatalProblem());
        self::assertFalse($notifier->hasRemoteChannel());
    }

    // ---------------------------------------------------------------- delivery

    public function testAFailedSendIsReportedRatherThanSwallowed(): void
    {
        // The hole Q28 closes: a delivery failure that returns silently means the run reports
        // success, the listing is marked notified, and the flat is gone.
        $notifier = new Notifier([$this->failingChannel('ntfy')]);
        $failures = $notifier->send($this->anyNotification());

        self::assertCount(1, $failures);
        self::assertFalse($notifier->delivered($failures));
    }

    public function testOneChannelFailingDoesNotPreventTheOtherFromDelivering(): void
    {
        $stream = $this->tempStream();
        $notifier = new Notifier([$this->failingChannel('ntfy'), new ConsoleChannel($stream)]);

        $failures = $notifier->send($this->anyNotification());

        self::assertCount(1, $failures, 'the working channel was still attempted');
        self::assertTrue($notifier->delivered($failures));
        rewind($stream);
        self::assertStringContainsString('Sartrouville', (string) stream_get_contents($stream));
    }

    public function testAChannelThrowingSomethingUnexpectedIsStillADeliveryFailure(): void
    {
        // It must not escape and abort the run: the next channel might have worked, and the caller
        // needs to know this listing was not delivered so it can retry.
        $notifier = new Notifier([new class implements Channel {
            public function name(): string
            {
                return 'wild';
            }

            public function check(): ?string
            {
                return null;
            }

            public function send(Notification $notification): void
            {
                throw new \LogicException('something nobody anticipated');
            }
        }]);

        $failures = $notifier->send($this->anyNotification());

        self::assertCount(1, $failures);
        self::assertFalse($notifier->delivered($failures));
    }

    // ---------------------------------------------------------------- secrets

    public function testTheNtfyTopicIsMaskedEvenOnASelfHostedServer(): void
    {
        // THE LEAK a review found. The topic is a secret that travels as a URL PATH SEGMENT, so
        // there is no `topic=` to anchor a name-based rule on. The pattern rule covers the default
        // `ntfy.*` host and leaks the moment the server is self-hosted under any other name —
        // which .env.example exists to permit.
        $leaked = Redact::text('echec POST https://push.mondomaine.fr/appart-9f3a2b : timeout');
        self::assertStringContainsString('appart-9f3a2b', (string) $leaked, 'the pattern rule alone cannot see this');

        $masked = Redact::text('echec POST https://push.mondomaine.fr/appart-9f3a2b : timeout', ['appart-9f3a2b']);
        self::assertStringNotContainsString('appart-9f3a2b', (string) $masked);
    }

    public function testAChannelErrorMasksTheLiteralsItWasGiven(): void
    {
        $e = new ChannelError('ntfy', 'POST https://push.example.test/appart-9f3a2b failed', null, ['appart-9f3a2b']);

        self::assertStringNotContainsString('appart-9f3a2b', $e->getMessage());
        self::assertStringContainsString('ntfy:', $e->getMessage());
    }

    public function testAnNtfyStatusCodeSurvivesMasking(): void
    {
        // A 401 and a 404 mean very different things — wrong token vs wrong topic — and both are
        // actionable. Masking that ate the diagnostic would be worse than the leak it prevents.
        $e = new ChannelError('ntfy', 'server answered HTTP 401', null, ['appart-9f3a2b']);

        self::assertStringContainsString('401', $e->getMessage());
    }

    public function testAnUnconfiguredNtfyChannelRefusesAtCheckRatherThanAtSend(): void
    {
        $problem = (new NtfyChannel(''))->check();

        self::assertNotNull($problem);
        self::assertStringContainsString('NTFY_TOPIC', (string) $problem);
        self::assertStringContainsString('secret', (string) $problem);
    }

    public function testNtfyRefusesANonHttpServer(): void
    {
        self::assertNotNull((new NtfyChannel('topic', 'ntfy.example.test'))->check());
    }

    // ---------------------------------------------------------------- helpers

    private function anyNotification(): Notification
    {
        return (new Formatter())->match($this->listing(), Verdict::matched(70, ['test'], false));
    }

    /** @return resource */
    private function tempStream()
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        return $stream;
    }

    private function brokenChannel(string $name, string $problem): Channel
    {
        return new class($name, $problem) implements Channel {
            public function __construct(private readonly string $n, private readonly string $p) {}

            public function name(): string
            {
                return $this->n;
            }

            public function check(): ?string
            {
                return $this->p;
            }

            public function send(Notification $notification): void
            {
                throw new ChannelError($this->n, 'must not be reached');
            }
        };
    }

    private function failingChannel(string $name): Channel
    {
        return new class($name) implements Channel {
            public function __construct(private readonly string $n) {}

            public function name(): string
            {
                return $this->n;
            }

            public function check(): ?string
            {
                return null;
            }

            public function send(Notification $notification): void
            {
                throw new ChannelError($this->n, 'the network went away');
            }
        };
    }

    public function testEmailSubjectAndFromCannotSmuggleAHeaderFromListingText(): void
    {
        // The structural twin of the ntfy Click finding: EmailChannel builds the Subject from the
        // landlord-controlled title and the From from config, both through headerSafe — but nothing
        // exercised that guard, so it was silently removable (a round-4 panel finding). The
        // transports (Smtp/File/Sendmail) build header lines raw and rely on this funnel. A CRLF in
        // the title is the classic Bcc-injection vector; it must not become its own header line.
        $dir = sys_get_temp_dir() . '/rentwatch-email-inj-' . bin2hex(random_bytes(6));

        // The From is separately validated by check() (its `\s` rejects a CRLF sender), so the
        // real injection surface is the Subject, built from the landlord-controlled title and
        // guarded ONLY by headerSafe. That is the guard under test.
        $channel = new EmailChannel(
            'moi@example.test',
            'rent-watch@localhost',
            '[rent-watch]',
            new FileTransport($dir),
        );

        $channel->send(new Notification(
            NotificationKind::MATCH,
            Priority::HIGH,
            "T4 a Chatou\r\nBcc: attacker@example.test\r\nSubject: forged",
            ['score 82'],
            'https://example.test/annonce/1',
        ));

        $files = glob($dir . '/*.eml') ?: [];
        self::assertCount(1, $files);
        $eml = (string) file_get_contents($files[0]);

        // Header block ends at the first blank line; the body may legitimately carry anything.
        $headerBlock = substr($eml, 0, strpos($eml, "\r\n\r\n") ?: strlen($eml));

        // A real injection would create its own header LINE; the collapse leaves the forged text as
        // harmless inline content of the one Subject line, so line-start is the right test.
        foreach (explode("\r\n", $headerBlock) as $line) {
            self::assertFalse(str_starts_with(strtolower(trim($line)), 'bcc:'), 'no injected Bcc header line: ' . $line);
        }
        // Exactly one Subject line — a smuggled `Subject:` continuation would make two.
        self::assertSame(1, preg_match_all('~^Subject: ~m', str_replace("\r\n", "\n", $headerBlock)));

        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    public function testASenderWithATrailingNewlineIsRejected(): void
    {
        // The sender check must use the `D` anchor: without it PHP's `$` matches before a trailing
        // newline, so `"rent-watch@localhost\n"` would pass validation and reach `MAIL FROM:<…>` as
        // a bare LF on the SMTP command line — the same trailing-newline hole closed for the header
        // token in the HTTP guards.
        $problem = (new EmailChannel(
            'moi@example.test',
            "rent-watch@localhost\n",
            '[rent-watch]',
            new FileTransport(sys_get_temp_dir() . '/rentwatch-unused-' . bin2hex(random_bytes(4))),
        ))->check();

        self::assertNotNull($problem);
        self::assertStringContainsString('sender address is not valid', (string) $problem);
    }
}
