<?php

declare(strict_types=1);

namespace Scout\Tests\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Config\ConfigError;
use Scout\Config\DotEnv;

/**
 * `.env` is PARSED, never executed.
 *
 * The bug this file exists for is concrete. Nothing loaded `.env` outside Docker, so the documented
 * workaround was `set -a; . ./.env; set +a` — the shell. A Gmail app password pasted with the
 * spaces Google displays it with (`abcd efgh ijkl mnop`) is not an assignment to bash: it is a
 * one-command environment prefix, so the variable was never exported AND bash ran the rest as a
 * command, printing four characters of a live credential to the terminal.
 *
 * Every test here is one property of a parser that cannot do that.
 */
#[CoversClass(DotEnv::class)]
final class DotEnvTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    /** @var list<string> */
    private array $touched = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }

        // Every name this test applied is removed from the real process environment. Without it a
        // value set here leaks into every test that runs afterwards in the same process, which is
        // the worst kind of test pollution: order-dependent, and invisible until a suite is
        // reordered.
        foreach ($this->touched as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }

        $this->files = [];
        $this->touched = [];
    }

    public function testAValueContainingSpacesSurvivesIntact(): void
    {
        // THE regression. A shell reads this line as "set RW_T_PASSWORD for the duration of the
        // command `efgh ijkl mnop`" — variable never exported, three words executed.
        $this->load("RW_T_PASSWORD=abcd efgh ijkl mnop\n", ['RW_T_PASSWORD']);

        self::assertSame('abcd efgh ijkl mnop', getenv('RW_T_PASSWORD'));
    }

    public function testTheRealEnvironmentWinsOverTheFile(): void
    {
        // Precedence, and it is load-bearing rather than tidy: `RENT_SCOUT_DB=/tmp/throwaway
        // bin/scout run` is how a live source is measured without touching the real seen-set, and
        // Compose's `environment:` outranks its own `env_file:` the same way. A file that could
        // override the environment would silently redirect a throwaway run at the real database.
        $this->touched[] = 'RW_T_WINNER';
        putenv('RW_T_WINNER=from-the-environment');

        $applied = $this->load("RW_T_WINNER=from-the-file\n", []);

        self::assertSame('from-the-environment', getenv('RW_T_WINNER'));
        self::assertNotContains('RW_T_WINNER', $applied, 'a skipped variable must not be reported as applied');
    }

    public function testAMissingFileIsNotAnError(): void
    {
        // `compose.yaml` declares `.env` `required: false` and a fresh clone has none. Refusing to
        // start would make the first command anyone runs on a new machine fail.
        self::assertSame([], DotEnv::load(sys_get_temp_dir() . '/rentwatch-absent-' . bin2hex(random_bytes(6))));
    }

    public function testCommentsAndBlankLinesAreSkipped(): void
    {
        $applied = $this->load(
            "# a comment\n\n   # an indented comment\nRW_T_KEPT=yes\n\n",
            ['RW_T_KEPT'],
        );

        self::assertSame(['RW_T_KEPT'], $applied);
        self::assertSame('yes', getenv('RW_T_KEPT'));
    }

    public function testAnExportPrefixIsAccepted(): void
    {
        // Because people paste it, and refusing would be pedantry that costs a startup.
        $this->load("export RW_T_EXPORTED=value\n", ['RW_T_EXPORTED']);

        self::assertSame('value', getenv('RW_T_EXPORTED'));
    }

    public function testWrappingQuotesAreStrippedAndInnerWhitespaceIsKept(): void
    {
        // Quotes are the ONLY way to express a value that really ends in a space, so what they
        // protect has to survive; and they must not survive themselves, or every quoted password
        // would be wrong by two characters.
        $this->load("RW_T_Q=\"  padded  \"\nRW_T_S='single'\n", ['RW_T_Q', 'RW_T_S']);

        self::assertSame('  padded  ', getenv('RW_T_Q'));
        self::assertSame('single', getenv('RW_T_S'));
    }

    public function testNothingInTheFileIsExpandedOrExecuted(): void
    {
        // The whole reason this class exists. Each of these is a live command or expansion to a
        // shell and must be four ordinary strings here.
        $this->load(
            "RW_T_SUB=\$(id -u)\nRW_T_TICK=`id -u`\nRW_T_VAR=\${HOME}\nRW_T_SEMI=a; id -u\n",
            ['RW_T_SUB', 'RW_T_TICK', 'RW_T_VAR', 'RW_T_SEMI'],
        );

        self::assertSame('$(id -u)', getenv('RW_T_SUB'));
        self::assertSame('`id -u`', getenv('RW_T_TICK'));
        self::assertSame('${HOME}', getenv('RW_T_VAR'));
        self::assertSame('a; id -u', getenv('RW_T_SEMI'));
    }

    public function testAMalformedLineIsRefusedAndTheREFUSALDoesNotQuoteIt(): void
    {
        // The refusal reaches a terminal and, for `run`, `state/rent-last-refusal.txt`. This file holds
        // the IMAP password, the SMTP password and the ntfy topic, so a parser that echoes what it
        // could not parse leaks a credential the day someone fat-fingers one.
        $path = $this->write("RW_T_OK=1\nthis is not an assignment sekret-value\n");

        try {
            DotEnv::load($path);
            self::fail('a malformed line must be refused');
        } catch (ConfigError $e) {
            self::assertStringContainsString('.env:2', $e->getMessage(), 'the refusal must name the line NUMBER');
            self::assertStringNotContainsString('sekret-value', $e->getMessage());
            self::assertStringNotContainsString('this is not an assignment', $e->getMessage());
        }
    }

    public function testAnEmptyValueIsAppliedRatherThanSkipped(): void
    {
        // `.env.example` ships `SMTP_PASSWORD=` empty, and the CLI distinguishes "set but empty"
        // from absent when it decides whether to say a channel is disabled.
        $this->load("RW_T_EMPTY=\n", ['RW_T_EMPTY']);

        self::assertSame('', getenv('RW_T_EMPTY'));
    }

    public function testTheShippedTemplateParsesCleanly(): void
    {
        // `.env.example` is what an operator copies. If the parser refuses it, the documented first
        // step of a deployment fails — and no test of hand-written fixtures would notice.
        $applied = DotEnv::load(dirname(__DIR__, 3) . '/.env.example');

        foreach ($applied as $name) {
            $this->touched[] = $name;
        }

        self::assertNotSame([], $applied);
    }

    /**
     * @param list<string> $expectApplied names to clean up afterwards
     *
     * @return list<string>
     */
    private function load(string $contents, array $expectApplied): array
    {
        foreach ($expectApplied as $name) {
            $this->touched[] = $name;
        }

        return DotEnv::load($this->write($contents));
    }

    private function write(string $contents): string
    {
        $path = sys_get_temp_dir() . '/rentwatch-dotenv-' . bin2hex(random_bytes(6));
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }
}
