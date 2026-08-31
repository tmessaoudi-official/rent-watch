<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Config\ConfigError;
use Scout\Car\VehicleSourceLoader;

/**
 * TRACK 1c — a parameter no adapter reads is REFUSED, not accepted.
 *
 * Three keys sit in `VehicleSourceLoader::PATTERN_PARAMS` — `title_pattern`, `seller_pattern` and
 * `postcode_pattern` — and are read by no vehicle adapter at all. Measured rather than assumed:
 * `grep -rn "'title_pattern'" src/php/Car/` finds the loader and nothing else. So a config carrying
 * one loaded cleanly, passed its regex compile-check, and then did absolutely nothing.
 *
 * That is the inert-parameter defect the RENT side already paid for: `title_pattern` was declared
 * and unread on every non-segmented email source until 2026-08-26, which silently made
 * `exclude_title_patterns` unreachable on PAP — an exclusion added because four of one source's
 * first nine matches were coliving rooms.
 *
 * The plan named two of the three; the third was found by measuring all seven.
 *
 * Refusing costs nothing today — neither shipped source configures one — and it is the only way the
 * next person to reach for one finds out. When an adapter learns to read one, it leaves
 * `UNREAD_PARAMS` in the same change.
 */
#[CoversClass(VehicleSourceLoader::class)]
final class VehicleSourceLoaderInertParamsTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    public function testTheShippedFileStillLoads(): void
    {
        // The counterweight, and it is not decoration: a refusal that also refused the shipped
        // config would be satisfied by deleting the feature.
        $definitions = VehicleSourceLoader::load(self::ROOT . '/config/car/sources.json');

        self::assertArrayHasKey('paruvendu', $definitions);
        self::assertArrayHasKey('autohero', $definitions);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function inertKeys(): iterable
    {
        yield 'title_pattern' => ['title_pattern'];
        yield 'seller_pattern' => ['seller_pattern'];
        yield 'postcode_pattern' => ['postcode_pattern'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('inertKeys')]
    public function testConfiguringAParameterNoAdapterReadsIsRefused(string $key): void
    {
        $path = $this->write([
            'sources' => [
                'probe' => [
                    'enabled' => false,
                    'family' => 'portal',
                    'type' => 'email_alert',
                    'params' => [
                        'from' => 'alerts@portal.test',
                        // A perfectly valid regex: the point is that it is never CONSULTED, which no
                        // compile-check can detect.
                        $key => '~^(.+)$~',
                    ],
                ],
            ],
        ]);

        try {
            $this->expectException(ConfigError::class);
            $this->expectExceptionMessageMatches('~' . $key . '~');
            VehicleSourceLoader::load($path);
        } finally {
            @unlink($path);
        }
    }

    /** A key an adapter DOES read is accepted, so the refusal is not simply "no patterns allowed". */
    public function testAParameterAnAdapterDoesReadIsAccepted(): void
    {
        $path = $this->write([
            'sources' => [
                'probe' => [
                    'enabled' => false,
                    'family' => 'portal',
                    'type' => 'email_alert',
                    'params' => [
                        'from' => 'alerts@portal.test',
                        'price_pattern' => '~([\d ]+)\s*€~',
                    ],
                ],
            ],
        ]);

        try {
            self::assertArrayHasKey('probe', VehicleSourceLoader::load($path));
        } finally {
            @unlink($path);
        }
    }

    /** @param array<string, mixed> $config */
    private function write(array $config): string
    {
        $path = sys_get_temp_dir() . '/scout-car-src-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR));

        return $path;
    }
}
