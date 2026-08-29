<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Core;

use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\SourceProfile;
use Scout\Rent\Core\Tenure;

/**
 * Reader for `tests/fixtures/rent/tenure/corpus.json`.
 *
 * Deliberately thin. The corpus is a language-neutral data file so that the phorj implementation
 * reads the SAME bytes and can be diffed case-by-case against this one; anything clever here would
 * be logic phorj has to reproduce before the two are even comparable.
 */
final class Corpus
{
    public const string PATH = __DIR__ . '/../../../fixtures/rent/tenure/corpus.json';

    /** @return array{version:int, declared_counts:array{synthetic:int,captured:int}, sources:array<string,mixed>, cases:list<array<string,mixed>>} */
    public static function load(): array
    {
        $raw = file_get_contents(self::PATH);

        if ($raw === false) {
            throw new \RuntimeException('classifier corpus is unreadable: ' . self::PATH);
        }

        /** @var array{version:int, declared_counts:array{synthetic:int,captured:int}, sources:array<string,mixed>, cases:list<array<string,mixed>>} $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * One PHPUnit data-provider row per case, keyed by fixture id so a failure names the fixture
     * rather than an index.
     *
     * @return iterable<string, array{RawListing, SourceProfile, array<string,mixed>, string, string}>
     */
    public static function provider(): iterable
    {
        $corpus = self::load();

        foreach ($corpus['cases'] as $index => $case) {
            /** @var array{id:string, source:string, title:string, description:string, fields:array<string,string>, expect:array<string,mixed>, why:string} $case */
            $profile = self::profile($corpus['sources'][$case['source']]);

            // AN OPAQUE EXTERNAL ID, not the descriptive fixture id. `externalId` is the source's
            // own key — `ANN-2024-00017` shaped — and the classifier now scans it, because a review
            // found all 21 excluded literals reaching MATCH through it. Feeding it a fixture id
            // like `trap-001-plus-de-chambres` made a dozen fixtures fail on their own NAMES, which
            // is a property of the test data and not of any listing a source will ever emit. The
            // descriptive id stays the provider key, so failures still name it.
            $listing = new RawListing(
                sourceName: $profile->name,
                externalId: sprintf('ANN-2024-%06d', $index),
                title: $case['title'],
                description: $case['description'],
                fields: $case['fields'],
                // `detail_read` — has this listing's own detail page been examined? Defaults to
                // FALSE, which is the fail-closed direction: a case that says nothing about it is
                // treated as the weaker evidence, so a new case cannot acquire the stronger reading
                // by omission. Cases that DO set it are asserting the hydrated path, which is the
                // one In'li's listings take in production.
                detailRead: (bool) ($case['detail_read'] ?? false),
            );

            yield $case['id'] => [$listing, $profile, $case['expect'], $case['why'], $case['id']];
        }
    }

    /** @param array<string,mixed> $spec */
    public static function profile(array $spec): SourceProfile
    {
        /** @var array{name:string, family:string, default_tenure:?string, mixed_tenure:bool} $spec */
        return new SourceProfile(
            name: $spec['name'],
            family: $spec['family'],
            defaultTenure: $spec['default_tenure'] === null ? null : Tenure::from($spec['default_tenure']),
            mixedTenure: $spec['mixed_tenure'],
        );
    }
}
