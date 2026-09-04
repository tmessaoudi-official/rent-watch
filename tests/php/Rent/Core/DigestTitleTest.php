<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Core;

use PHPUnit\Framework\TestCase;
use Scout\Rent\Core\DigestCause;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\Verdict;
use Scout\Rent\Notify\Formatter;

/**
 * The digest title may not assert a regime the batch has already determined.
 *
 * The *à vérifier* bin is §1's only landing zone, and until Track 6-C2 it had exactly one entrance:
 * a tenure the classifier could not resolve. The price-per-m² plausibility branch opened a SECOND
 * entrance — a listing that is `LLI 97/100`, tenure fully determined, digested because its rent and
 * its surface do not describe the same dwelling — while the rollup title still read
 * *"N annonce(s) au régime indéterminé"* for the whole batch.
 *
 * That is hard rule 9 at the display layer, the shape this repo already fixed twice on the push: an
 * extraction that produced nothing is not a value, and a regime that WAS determined must not be
 * announced as undetermined. The operator reads the title on a phone; the per-entry body carries
 * the real reason, but the title is what decides whether the digest is opened at all.
 *
 * The guarantee asserted here is deliberately one-directional: the regime clause appears only when
 * EVERY entry earned it. A batch mixing the two causes, or one whose cause the store did not
 * record, gets the neutral title — saying less rather than saying something untrue.
 */
final class DigestTitleTest extends TestCase
{
    public function testABatchOfPureTenureDoubtsKeepsTheRegimeClause(): void
    {
        $n = (new Formatter())->digest([
            $this->entry('a', DigestCause::TENURE_UNDETERMINED),
            $this->entry('b', DigestCause::TENURE_UNDETERMINED),
        ]);

        self::assertStringContainsString('2 annonce(s)', $n->title);
        self::assertStringContainsString('régime indéterminé', $n->title);
    }

    public function testOneNonTenureEntryDropsTheRegimeClauseForTheWholeBatch(): void
    {
        // The refuted shape. Two genuine tenure doubts and one price-implausibility entry whose
        // tenure is fully determined: the title used to speak for all three.
        $n = (new Formatter())->digest([
            $this->entry('a', DigestCause::TENURE_UNDETERMINED),
            $this->entry('b', DigestCause::TENURE_UNDETERMINED),
            $this->entry('c', DigestCause::OTHER),
        ]);

        self::assertStringContainsString('3 annonce(s)', $n->title);
        self::assertStringNotContainsString('régime indéterminé', $n->title);
    }

    public function testABatchWithNoTenureDoubtAtAllClaimsNoRegime(): void
    {
        $n = (new Formatter())->digest([$this->entry('a', DigestCause::OTHER)]);

        self::assertStringContainsString('1 annonce(s)', $n->title);
        self::assertStringNotContainsString('régime indéterminé', $n->title);
    }

    public function testTheEntryBodyStillCarriesItsOwnReasonWhicheverTheCause(): void
    {
        // The counterweight. Dropping the clause from the title must not be satisfied by dropping
        // the reason from the entry — that would be a quieter digest, not a truer one.
        $n = (new Formatter())->digest([
            $this->entry('a', DigestCause::OTHER, 'loyer implausible pour la surface annoncée'),
        ]);

        self::assertStringContainsString('loyer implausible pour la surface annoncée', implode(' ', $n->reasons));
    }

    /** @return array{listing: RawListing, verdict: Verdict} */
    private function entry(string $id, DigestCause $cause, string $reason = 'régime indéterminé'): array
    {
        return [
            'listing' => new RawListing(
                sourceName: 'seloger',
                externalId: $id,
                title: 'T4 Sartrouville',
                url: 'https://example.test/' . $id,
                rentCc: 1450,
            ),
            'verdict' => Verdict::digest([$reason], $cause),
        ];
    }
}
