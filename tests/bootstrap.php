<?php

declare(strict_types=1);

/**
 * Test bootstrap — exists to turn one silent failure into a loud one.
 *
 * `composer dump-autoload` WITHOUT `--dev` omits the `RentWatch\Tests\` PSR-4 entry. PHPUnit then
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

if (!class_exists(\RentWatch\Tests\Core\Corpus::class)) {
    fwrite(
        STDERR,
        "rent-watch: the RentWatch\\Tests\\ namespace is not autoloadable, so the corpus suite cannot\n"
        . "  load its fixtures. The autoloader was generated without dev namespaces.\n"
        . "  run: composer dump-autoload --dev\n",
    );
    exit(1);
}
