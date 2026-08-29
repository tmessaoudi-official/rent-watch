<?php

declare(strict_types=1);

namespace Scout\Cli;

/**
 * One watched domain — what makes `rent` and `car` two instances of the same program rather than
 * two programs (generic-scout restructuring, 2026-08-29).
 *
 * The four strings are the domain's ENTIRE footprint outside its own namespace, and they follow one
 * scheme so the next domain is one line in {@see Domains}: namespace `Scout\<Slug>\`, config under
 * `config/<slug>/`, environment keys `<SLUG>_*`, a `<slug>-watch` label on every push, the database
 * at `state/<slug>-watch.sqlite3`, markers at `state/<slug>-*.txt`, and a ntfy topic
 * `<initial>w-<32 hex>`. The label is what a phone shows, so it is spelled here once.
 *
 * @template T of object
 */
final readonly class Domain
{
    /**
     * @param class-string<T> $cli the domain's CLI — constructed with the dispatcher's injected
     *                             seams (root, streams, clock, HTTP, notifier) and handed the
     *                             command line with `--domain=` already consumed
     */
    public function __construct(
        public string $slug,
        public string $label,
        public string $envPrefix,
        public string $configDir,
        public string $cli,
    ) {
    }
}
