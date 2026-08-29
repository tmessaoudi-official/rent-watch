<?php

declare(strict_types=1);

/**
 * Test bootstrap — exists to turn one silent failure into a loud one.
 *
 * `composer dump-autoload` WITHOUT `--dev` omits the `Scout\Tests\` PSR-4 entry. PHPUnit then
 * still discovers and runs the test FILES (it loads those itself), but the corpus reader they share
 * is a normal autoloaded class, so every corpus test errors with `Class ... not found` while the
 * unit tests carry on passing. The run looks like a code regression and is actually a build state.
 *
 * That is exactly the failure shape this project treats as the enemy — see `CLAUDE.md` hard rules 2
 * and 3: a broken thing that reports something other than "I am broken". So the bootstrap asserts
 * its own preconditions and says what to run.
 */
$autoload = __DIR__ . '/../vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "rent-watch: vendor/autoload.php is missing.\n  run: composer install\n");
    exit(1);
}

require $autoload;

// NO TEST REACHES THE NETWORK. Spec §11: parser tests run against frozen fixtures, offline.
//
// Set here rather than trusted to discipline, because until 2026-08-19 this held only by accident —
// every source in `config/rent/sources.json` was disabled, and the tests that run the real CLI against
// the real config therefore had nothing to poll. Enabling the first real source (In'li) turned the
// suite into a four-page-per-test crawler of a live landlord's site within one run.
//
// `CurlHttpClient::send()` reads this and refuses, so an accidental real client fails instantly and
// says why, instead of hanging or quietly succeeding on a developer's machine and hammering the
// site from CI. Adapters under test are given fakes; this is the backstop for the ones that are not.
putenv('SCOUT_OFFLINE=1');

if (!class_exists(\Scout\Tests\Rent\Core\Corpus::class)) {
    fwrite(
        STDERR,
        "rent-watch: the Scout\\Tests\\ namespace is not autoloadable, so the corpus suite cannot\n"
        . "  load its fixtures. The autoloader was generated without dev namespaces.\n"
        . "  run: composer dump-autoload --dev\n",
    );
    exit(1);
}
