<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Core\Department;

/**
 * The postcode → departement lookup behind the notification's facts line.
 *
 * Small, and every test here is about a way a display can assert something the data did not say.
 */
#[CoversClass(Department::class)]
final class DepartmentTest extends TestCase
{
    public function testEveryIleDeFranceDepartementIsNamed(): void
    {
        // Named one by one rather than counted: these eight are exactly the prefixes
        // `config/rent/criteria.json` filters on, and a missing one is a match that arrives without
        // saying where it is.
        $expected = [
            '75001' => 'Paris (75)',
            '77300' => 'Seine-et-Marne (77)',
            '78500' => 'Yvelines (78)',
            '91940' => 'Essonne (91)',
            '92000' => 'Hauts-de-Seine (92)',
            '93600' => 'Seine-Saint-Denis (93)',
            '94240' => 'Val-de-Marne (94)',
            '95110' => "Val-d'Oise (95)",
        ];

        foreach ($expected as $postcode => $label) {
            self::assertSame($label, Department::label((string) $postcode));
        }
    }

    public function testAPostcodeOutsideIleDeFranceIsSilentRatherThanGuessed(): void
    {
        // Logirep publishes nationally. Naming a departement this table does not have would be a
        // guess presented as a fact, and returning null lets the caller simply omit the line.
        self::assertNull(Department::label('33000'));
        self::assertNull(Department::name('33000'));
    }

    public function testNonDigitsAreStrippedTheSameWayTheFilterStripsThem(): void
    {
        // `Criteria::matchesCommune()` normalises the same way. If the two disagreed, a listing
        // could pass the 78 filter and be displayed as belonging somewhere else — a display
        // contradicting the filter that admitted it is worse than no display at all.
        self::assertSame('Yvelines (78)', Department::label('78 500'));
        self::assertSame('Yvelines (78)', Department::label('F-78500'));
    }

    public function testATruncatedValueIsNotTreatedAsAPostcode(): void
    {
        // `78` is a departement CODE, not a postcode. Accepting it would mean any two-digit field
        // that happened to land in `cp` starts naming a place.
        self::assertNull(Department::label('78'));
        self::assertNull(Department::label('785'));
        self::assertNull(Department::label('785000'));
    }

    public function testAnAbsentOrEmptyPostcodeIsNotAnError(): void
    {
        // In'li's card gives a postcode; a future source might not. The formatter must be able to
        // ask without guarding, because a formatter that throws takes down a whole pass.
        self::assertNull(Department::label(null));
        self::assertNull(Department::label(''));
        self::assertNull(Department::label('   '));
    }
}
