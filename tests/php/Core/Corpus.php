<?php

declare(strict_types=1);

namespace RentWatch\Tests\Core;

use RentWatch\Core\RawListing;
use RentWatch\Core\SourceProfile;
use RentWatch\Core\Tenure;

/**
 * Reader for `tests/fixtures/tenure/corpus.json`.
 *
 * Deliberately thin. The corpus is a language-neutral data file so that the phorj implementation
 * reads the SAME bytes and can be diffed case-by-case against this one; anything clever here would
 * be logic phorj has to reproduce before the two are even comparable.
 */
final class Corpus
{
    public const string PATH = __DIR__ . '/../../fixtures/tenure/corpus.json';

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
     * @return iterable<string, array{RawListing, SourceProfile, array<string,mixed>, string}>
     */
    public static function provider(): iterable
    {
        $corpus = self::load();

        foreach ($corpus['cases'] as $case) {
            /** @var array{id:string, source:string, title:string, description:string, fields:array<string,string>, expect:array<string,mixed>, why:string} $case */
            $profile = self::profile($corpus['sources'][$case['source']]);

            $listing = new RawListing(
                sourceName: $profile->name,
                externalId: $case['id'],
                title: $case['title'],
                description: $case['description'],
                fields: $case['fields'],
            );

            yield $case['id'] => [$listing, $profile, $case['expect'], $case['why']];
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
