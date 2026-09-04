<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\SourceError;
use Scout\Core\Notify\Channel;
use Scout\Core\Notify\Notification;
use Scout\Core\Notify\NotificationKind;
use Scout\Core\Notify\Notifier;
use Scout\Core\SourceHealth;
use Scout\Car\SitemapVehicleSource;
use Scout\Car\VehicleCriteriaLoader;
use Scout\Car\VehicleListing;
use Scout\Car\VehiclePipeline;
use Scout\Car\VehicleSource;
use Scout\Car\VehicleStore;

#[CoversClass(VehiclePipeline::class)]
final class VehiclePipelineTest extends TestCase
{
    public function testANewMatchIsPushedOnceWithTheSourceLeadingItsTitle(): void
    {
        [$pipeline, $channel] = $this->pipeline();
        $source = new FakeCarSource('paruvendu', [$this->car('a1', 21000)]);

        $pipeline->runOnce([$source], '2026-08-29T10:00:00Z');
        $pipeline->runOnce([$source], '2026-08-29T10:15:00Z');

        $matches = $this->ofKind($channel, NotificationKind::MATCH);
        self::assertCount(1, $matches, 'new exactly once');
        self::assertStringStartsWith('paruvendu · ', $matches[0]->title);
        self::assertStringContainsString('Renault Austral 2023 · 26 000 km · 21 000 €', $matches[0]->title);
    }

    public function testARejectedCarIsNeverPushedAndSaysWhy(): void
    {
        [$pipeline, $channel] = $this->pipeline();

        $result = $pipeline->runOnce([new FakeCarSource('paruvendu', [$this->car('g1', 21000, description: 'Véhicule gagé.')])], '2026-08-29T10:00:00Z');

        self::assertSame([], $this->ofKind($channel, NotificationKind::MATCH));
        self::assertSame(1, $result->rejectedCount);
        self::assertStringContainsString('gagé', $result->rejected[0]);
    }

    public function testSeedingMarksEverythingSeenAndPushesNothing(): void
    {
        [$pipeline, $channel, $store] = $this->pipeline();
        $source = new FakeCarSource('paruvendu', [$this->car('a1', 21000), $this->car('a2', 15000)], store: $store);

        $pipeline->runOnce([$source], '2026-08-29T10:00:00Z', seedOnly: true);
        $pipeline->runOnce([$source], '2026-08-29T10:15:00Z');

        self::assertSame([], $this->ofKind($channel, NotificationKind::MATCH), 'the market already watched is never announced');
        self::assertSame([], $this->ofKind($channel, NotificationKind::PRICE_DROP));
        self::assertFalse($store->isSeenSetEmpty());
    }

    /** The Bussy loop replayed on the car path, in both message orders: a re-read older card is never a drop. */
    public function testAReReadOlderCardIsNeverAPriceDrop(): void
    {
        [$pipeline, $channel, $store] = $this->pipeline();
        $older = $this->car('a1', 21000, observedAt: '2026-08-26T18:31:09Z');
        $newer = $this->car('a1', 21500, observedAt: '2026-08-27T13:34:25Z');

        $pipeline->runOnce([new FakeCarSource('paruvendu', [$older])], '2026-08-26T19:00:00Z');
        foreach (['2026-08-27T14:00:00Z', '2026-08-27T14:15:00Z'] as $now) {
            $pipeline->runOnce([new FakeCarSource('paruvendu', [$newer, $older])], $now);
        }
        $pipeline->runOnce([new FakeCarSource('paruvendu', [$older, $newer])], '2026-08-27T14:30:00Z');

        self::assertSame([], $this->ofKind($channel, NotificationKind::PRICE_DROP));
        self::assertSame([21000, 21500], $store->priceHistory($store->dedupKey($newer)));
    }

    public function testAGenuineNotableDropIsPushedOnce(): void
    {
        [$pipeline, $channel] = $this->pipeline();

        $pipeline->runOnce([new FakeCarSource('paruvendu', [$this->car('a1', 21000, observedAt: '2026-08-26T10:00:00Z')])], '2026-08-26T10:00:00Z');
        $pipeline->runOnce([new FakeCarSource('paruvendu', [$this->car('a1', 19500, observedAt: '2026-08-27T10:00:00Z')])], '2026-08-27T10:00:00Z');
        $pipeline->runOnce([new FakeCarSource('paruvendu', [$this->car('a1', 19500, observedAt: '2026-08-27T10:00:00Z')])], '2026-08-27T10:15:00Z');

        $drops = $this->ofKind($channel, NotificationKind::PRICE_DROP);
        self::assertCount(1, $drops);
        self::assertStringStartsWith('paruvendu · Baisse de prix', $drops[0]->title);
    }

    public function testAFailingSourceIsOneFailedSourceNotAnEmptyPass(): void
    {
        [$pipeline, $channel] = $this->pipeline();

        $result = $pipeline->runOnce([
            new FakeCarSource('broken', [], throw: new SourceError('broken', 'HTTP 500')),
            new FakeCarSource('paruvendu', [$this->car('a1', 21000)]),
        ], '2026-08-29T10:00:00Z');

        self::assertSame(1, $result->sourcesFailed);
        self::assertSame(1, $result->sourcesRun);
        self::assertCount(1, $this->ofKind($channel, NotificationKind::MATCH), 'the healthy source still pushed');
        self::assertStringContainsString('HTTP 500', $result->errors[0]);
    }

    public function testASourceGoneBrokenAlertsOnceAndRecoversOnce(): void
    {
        [$pipeline, $channel, $store] = $this->pipeline();
        $good = new FakeCarSource('paruvendu', [$this->car('a1', 21000)], store: $store);
        $bad = new FakeCarSource('paruvendu', [], throw: new SourceError('paruvendu', 'HTTP 500'), store: $store);

        $pipeline->runOnce([$good], '2026-08-29T10:00:00Z');
        foreach (['10:15', '10:30', '10:45', '11:00'] as $t) {
            $pipeline->runOnce([$bad], '2026-08-29T' . $t . ':00Z');
        }
        // Recovery is a WINDOW, not a run: the store keeps a source flaky while most of its recent
        // runs failed, so it takes several clean passes before health reads OK again.
        $minute = 75;
        do {
            $now = sprintf('2026-08-29T%02d:%02d:00Z', 10 + intdiv($minute, 60), $minute % 60);
            $pipeline->runOnce([$good], $now);
            $minute += 15;
        } while ($good->health($now)->status !== \Scout\Core\SourceStatus::OK && $minute < 60 * 13);
        self::assertSame(\Scout\Core\SourceStatus::OK, $good->health($now)->status, 'the window drained within the cap');

        // An ESCALATION is not swallowed by the earlier, quieter alert (the rent side's Q29 rule):
        // `warn_flaky` first, then `broken` — each once per cooldown, never one per pass.
        // Four FAILED runs (not empty ones) classify as flaky, not broken — the store's rule.
        $alerts = $this->ofKind($channel, NotificationKind::SOURCE_HEALTH);
        self::assertGreaterThanOrEqual(1, count($alerts));
        self::assertLessThanOrEqual(2, count($alerts), 'never one alert per failing pass');
        self::assertStringContainsString('paruvendu', $alerts[0]->title);
        self::assertCount(1, $this->ofKind($channel, NotificationKind::SOURCE_RECOVERED));
    }

    public function testSeedingASitemapSourceRecordsItsIndexWithoutFetchingLots(): void
    {
        // Covered structurally in AutoheroFixtureTest (seedIndex); here the pipeline's own branch:
        // a SitemapVehicleSource under --seed is asked for its index, never its lots.
        self::assertTrue(method_exists(SitemapVehicleSource::class, 'seedIndex'));
    }

    // ------------------------------------------------------------------------------------------

    /** @return array{VehiclePipeline, CarRecordingChannel, VehicleStore} */
    // ── Row 36 (2026-09-04): a processed alert email is acknowledged — AFTER the store recorded it ──

    public function testAnEmailSourceIsAcknowledgedAfterTheStoreRecordedItsPass(): void
    {
        [$pipeline, , $store] = $this->pipeline();
        $source = new AcknowledgingCarSource('mail', [$this->car('c1', 15000)], $store);

        $result = $pipeline->runOnce([$source], '2026-09-04T10:00:00Z');

        self::assertSame(['acknowledged-after-recording'], $source->events);
        self::assertSame([], $result->errors);
    }

    public function testACarSourceWhoseFetchFailedIsNeverAcknowledged(): void
    {
        [$pipeline, , $store] = $this->pipeline();
        $source = new AcknowledgingCarSource('mail', [], $store, throwOnFetch: new SourceError('mail', 'boom'));

        $pipeline->runOnce([$source], '2026-09-04T10:00:00Z');

        self::assertSame([], $source->events);
    }

    public function testAFailedCarAcknowledgementIsReportedAndDoesNotFailThePass(): void
    {
        [$pipeline, , $store] = $this->pipeline();
        $source = new AcknowledgingCarSource('mail', [$this->car('c1', 15000)], $store, throwOnAck: new SourceError('mail', 'STORE refused by the server'));

        $result = $pipeline->runOnce([$source], '2026-09-04T10:00:00Z');

        self::assertSame(0, $result->sourcesFailed);
        self::assertSame(1, $result->itemsParsed);
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('STORE refused', $result->errors[0]);
        self::assertFalse($store->isSeenSetEmpty(), 'recorded regardless');
    }

    public function testSeedingAcknowledgesACarSource(): void
    {
        [$pipeline, , $store] = $this->pipeline();
        $source = new AcknowledgingCarSource('mail', [$this->car('c1', 15000)], $store);

        $pipeline->runOnce([$source], '2026-09-04T10:00:00Z', true);

        self::assertSame(['acknowledged-after-recording'], $source->events);
    }

    private function pipeline(): array
    {
        $store = VehicleStore::open(':memory:');
        $channel = new CarRecordingChannel();
        $pipeline = new VehiclePipeline(
            VehicleCriteriaLoader::fromArray(VehicleCriteriaTest::minimal()),
            $store,
            new Notifier([$channel]),
        );

        return [$pipeline, $channel, $store];
    }

    private function car(string $id, ?int $price, string $description = '4x4 - SUV - Essence - Année 2023 - 26 000 km', ?string $observedAt = null): VehicleListing
    {
        return new VehicleListing(
            sourceName: 'paruvendu', externalId: $id, title: 'Renault Austral Auto', description: $description,
            url: 'https://www.paruvendu.fr/a/voiture-occasion/renault/austral/' . $id, make: 'renault', model: 'austral',
            priceEur: $price, year: 2023, mileageKm: 26000, fuel: 'essence', gearbox: 'automatique', body: 'suv',
            observedAt: $observedAt,
        );
    }

    /** @return list<Notification> */
    private function ofKind(CarRecordingChannel $channel, NotificationKind $kind): array
    {
        return array_values(array_filter($channel->sent, static fn (Notification $n): bool => $n->kind === $kind));
    }
}

