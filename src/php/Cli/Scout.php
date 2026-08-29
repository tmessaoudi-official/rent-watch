<?php

declare(strict_types=1);

namespace Scout\Cli;

use Scout\Adapters\Http\HttpClient;
use Scout\Core\Notify\Notifier;

/**
 * `scout` — the generic entry point. It knows exactly one thing: which domain the command line
 * names, and it hands everything else to that domain's CLI unchanged.
 *
 * `--domain=<slug>` is REQUIRED for any verb. The first shape (2026-08-29, morning) was the rent
 * CLI dispatching `--domain=car` from inside its own `run()`, with rent as the implicit default —
 * which made a deployment that forgot the flag silently watch the wrong domain against the wrong
 * database, with a green heartbeat. A missing domain is a refusal that lists the registry, never a
 * default; `help` alone prints the same usage and exits 0, because asking is not a mistake.
 *
 * The injected seams (clock, HTTP client, notifier) are the same the domain CLIs take, so a test can
 * drive the whole program through this class exactly as `bin/scout` does.
 */
final readonly class Scout
{
    /** @var resource */
    private mixed $out;

    /** @var resource */
    private mixed $err;

    public function __construct(
        private string $rootDir,
        mixed $out = null,
        mixed $err = null,
        private ?string $nowIso = null,
        private ?HttpClient $http = null,
        private ?Notifier $notifier = null,
    ) {
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
    }

    /** @param list<string> $argv the arguments AFTER the program name */
    public function run(array $argv): int
    {
        $slugs = [];
        $rest = [];
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--domain=')) {
                $slugs[] = substr($arg, strlen('--domain='));
            } else {
                $rest[] = $arg;
            }
        }

        if (count($slugs) > 1) {
            fwrite($this->err, 'scout : un seul --domain= par commande (reçus : ' . implode(', ', $slugs) . ")\n");
            $this->usage($this->err);

            return 2;
        }

        if ($slugs === []) {
            $verb = $rest[0] ?? 'help';
            if (in_array($verb, ['help', '--help', '-h'], true)) {
                $this->usage($this->out);

                return 0;
            }
            fwrite($this->err, "scout : --domain=<domaine> est requis — aucun domaine n'est implicite.\n");
            $this->usage($this->err);

            return 2;
        }

        $domain = Domains::get($slugs[0]);
        if ($domain === null) {
            fwrite($this->err, 'scout : domaine inconnu « ' . $slugs[0] . ' » — domaines : ' . implode(', ', Domains::slugs()) . "\n");
            $this->usage($this->err);

            return 2;
        }

        $cli = new ($domain->cli)($this->rootDir, $this->out, $this->err, $this->nowIso, $this->http, $this->notifier);

        return $cli->run($rest);
    }

    /** @param resource $to */
    private function usage($to): void
    {
        fwrite($to, "scout — un veilleur, plusieurs domaines.\n");
        fwrite($to, "usage : scout --domain=<domaine> <commande> [options]\n");
        fwrite($to, "        scout --domain=<domaine> help        les commandes du domaine\n");
        fwrite($to, "domaines :\n");
        foreach (Domains::all() as $domain) {
            fwrite($to, sprintf("  %-6s %-12s %-14s %s*\n", $domain->slug, $domain->label, $domain->configDir . '/', $domain->envPrefix));
        }
    }
}
