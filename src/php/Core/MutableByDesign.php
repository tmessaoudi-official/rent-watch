<?php

declare(strict_types=1);

namespace RentWatch\Core;

/**
 * Marks a class that genuinely cannot be `readonly`, and says so where the class is defined.
 *
 * `TenureCorpusTest::testEveryCoreValueObjectIsImmutable()` requires every class under `src/php/`
 * to be `final readonly`, because the defect it was written for was a `Classification` whose
 * `reasons[]` a caller could rewrite after the classifier had decided. That rule is right for value
 * objects and wrong for the handful of things that are not value objects at all — a consuming
 * parser, a cursor, anything whose whole mechanism is that it changes as it works.
 *
 * The alternative was a list of exempt class names inside the test. That is worse in the way this
 * repo keeps getting bitten by: the exemption sits far from the class, nobody re-reads it, and it
 * quietly grows until the test guarantees nothing. Implementing an interface puts the claim in the
 * file it is a claim about, next to the docblock that has to justify it.
 *
 * **The bar for implementing this is high, and it is not "readonly was inconvenient":**
 *
 * - The class must never be handed to a caller as a result. A value that leaves the module can be
 *   held, and anything held can be rewritten behind the code that produced it — which is the whole
 *   defect.
 * - Its mutation must BE the mechanism, not an optimisation. {@see \RentWatch\Config\Reader}
 *   qualifies: it consumes each key as it is read so that "everything left over is an unknown key"
 *   is enforced by the code's shape rather than by an allow-list maintained beside it.
 * - Do not reach for the loophole of a `readonly` property holding a mutable object. PHP permits it
 *   and reflection would call the class immutable, which turns the test into theatre. If a class
 *   needs to mutate, say so here.
 *
 * The test asserts the set of implementors matches what it expects, so adding one is a visible,
 * argued change rather than a silent one.
 */
interface MutableByDesign
{
}
