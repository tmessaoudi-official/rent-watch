<?php

declare(strict_types=1);

namespace Scout\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Scout\Cli\Domains;
use Scout\Cli\Scout;

/**
 * The generic entry point routes by `--domain=` and NEVER defaults. Every case here is a way the
 * old shape — rent implicit, `--domain=car` special-cased inside the rent CLI — would have watched
 * the wrong domain with a green heartbeat.
 *
 * Every dispatch runs against an EMPTY temporary root, not the repo: a sabotage that makes a
 * missing or unknown domain fall through to rent would otherwise run a real `doctor` against the
 * developer's `state/rent-watch.sqlite3`. In the empty root the fall-through surfaces as a config
 * refusal with the wrong message, which is exactly what the assertions below distinguish.
 */
final class ScoutDispatchTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/scout-dispatch-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/state', 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/state/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->root . '/state');
        @rmdir($this->root);
    }

    public function testAMissingDomainIsARefusalThatListsTheRegistry(): void
    {
        $r = $this->dispatch(['doctor']);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('--domain=<domaine> est requis', $r['err']);
        foreach (Domains::slugs() as $slug) {
            self::assertStringContainsString("  $slug ", $r['err'], "the refusal must list `$slug`");
        }
        self::assertSame('', $r['out'], 'nothing on stdout: a refusal is not a result');
    }

    public function testHelpWithoutADomainIsUsageNotARefusal(): void
    {
        foreach (['help', '--help', '-h'] as $verb) {
            $r = $this->dispatch([$verb]);
            self::assertSame(0, $r['code'], $verb);
            self::assertStringContainsString('usage : scout --domain=<domaine>', $r['out'], $verb);
            self::assertSame('', $r['err'], $verb);
        }

        $r = $this->dispatch([]);
        self::assertSame(0, $r['code'], 'no arguments at all reads as help');
    }

    public function testAnUnknownDomainIsRefusedByName(): void
    {
        $r = $this->dispatch(['--domain=boat', 'doctor']);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('domaine inconnu « boat »', $r['err']);
        self::assertStringContainsString('domaines : ' . implode(', ', Domains::slugs()), $r['err']);
    }

    public function testTwoDomainsOnOneLineAreRefusedRatherThanFirstWins(): void
    {
        $r = $this->dispatch(['--domain=rent', 'help', '--domain=car']);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('un seul --domain=', $r['err']);
    }

    public function testEachRegisteredDomainReachesItsOwnCli(): void
    {
        // Each domain's `help` names its own config directory — the one string that cannot be the
        // other domain's. `--domain=` may sit anywhere on the line (compose puts it in the
        // ENTRYPOINT, ahead of the verb; a human types it after).
        foreach (Domains::all() as $domain) {
            foreach ([['--domain=' . $domain->slug, 'help'], ['help', '--domain=' . $domain->slug]] as $argv) {
                $r = $this->dispatch($argv);
                self::assertSame(0, $r['code'], implode(' ', $argv) . ': ' . $r['err']);
                self::assertStringContainsString($domain->configDir . '/', $r['out'], implode(' ', $argv));
            }
        }
    }

    public function testTheUsageIsGeneratedFromTheRegistry(): void
    {
        // A usage typed beside the table drifts the day a domain is added; this pins that the
        // registry is the ONLY source. Every domain's label and env prefix must appear.
        $r = $this->dispatch(['help']);
        foreach (Domains::all() as $domain) {
            self::assertStringContainsString($domain->label, $r['out']);
            self::assertStringContainsString($domain->envPrefix . '*', $r['out']);
        }
    }

    public function testTheRegistryIsCoherent(): void
    {
        foreach (Domains::all() as $slug => $domain) {
            self::assertSame($slug, $domain->slug);
            self::assertSame("$slug-watch", $domain->label, 'labels follow `<slug>-watch`');
            self::assertSame(strtoupper($slug) . '_', $domain->envPrefix, 'env keys follow `<SLUG>_*`');
            self::assertSame("config/$slug", $domain->configDir);
            self::assertTrue(class_exists($domain->cli), $domain->cli);
            self::assertSame(1, preg_match('/^Scout\\\\' . ucfirst($slug) . '\\\\/', $domain->cli), 'the CLI lives in the domain namespace');
            self::assertDirectoryExists(dirname(__DIR__, 3) . '/' . $domain->configDir, 'the shipped tree carries the domain config');
        }
    }

    /**
     * @param list<string> $argv
     * @return array{code: int, out: string, err: string}
     */
    private function dispatch(array $argv): array
    {
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        $code = (new Scout($this->root, $out, $err, '2026-08-29T12:00:00+02:00'))->run($argv);
        rewind($out);
        rewind($err);

        return ['code' => $code, 'out' => (string) stream_get_contents($out), 'err' => (string) stream_get_contents($err)];
    }
}
