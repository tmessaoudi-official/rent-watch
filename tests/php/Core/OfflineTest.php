<?php

declare(strict_types=1);

namespace RentWatch\Tests\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RentWatch\Core\Offline;

/**
 * The offline tripwire's own predicate, which had no test of its own until round 7.
 *
 * `Offline` is the single funnel for all five egress points in the tree, and each of those five
 * guards IS pinned — remove any one and a test goes red. What nothing asked was whether the
 * predicate underneath them decides correctly. A reviewer weakened `isLoopback()` from a parsed-host
 * comparison to a substring search and the whole suite stayed green, while
 * `https://evil.test/?x=127.0.0.1` became a permitted request.
 *
 * `tests/bootstrap.php` sets `RENT_WATCH_OFFLINE=1` for the whole suite, so the refusing branch is
 * the ambient one here.
 */
#[CoversClass(Offline::class)]
final class OfflineTest extends TestCase
{
    public function testTheSuiteRunsWithTheTripwireArmed(): void
    {
        // Every assertion below is about the refusing branch, so this is the precondition rather
        // than a separate guarantee. Without it the file would pass by being inert.
        self::assertSame('1', getenv('RENT_WATCH_OFFLINE'));
    }

    public function testAThirdPartyHostIsRefused(): void
    {
        self::assertNotNull(Offline::refusal('https://www.inli.fr/recherche'));
    }

    public function testLoopbackIsPermittedBecauseTheWireTestsNeedARealSocket(): void
    {
        foreach ([
            'http://127.0.0.1:8080/x',
            'http://localhost:1025/',
            'http://[::1]:1025/',
        ] as $url) {
            self::assertNull(Offline::refusal($url), $url . ' must stay reachable');
        }
    }

    /**
     * The guarantee the docblock states, and the one nothing pinned.
     *
     * A substring search satisfies every other assertion in this file — which is exactly why this
     * one has to exist. The loopback exemption is what a test is allowed to reach; widening it by
     * accident is, in `Offline`'s own words, "two putenv lines away from a public POST carrying a
     * listing title and url to a documented-secret topic".
     */
    public function testALoopbackAddressInTheQUERYIsNotALoopbackHOST(): void
    {
        foreach ([
            'https://evil.test/?x=127.0.0.1',
            'https://evil.test/127.0.0.1',
            'https://evil.test/#localhost',
            'https://localhost.evil.test/',
            'https://evil.test/?redirect=http://localhost/',
        ] as $url) {
            self::assertNotNull(
                Offline::refusal($url),
                $url . ' merely CONTAINS a loopback string; its host is third-party',
            );
        }
    }

    /**
     * `refusalForHost()` takes a bare host, so it supplies a scheme before parsing.
     *
     * The IMAP and SMTP adapters are its callers, and hard rule 4 makes IMAP the primary path for
     * private portals — it sends a cleartext password to a host read from `.env`.
     */
    public function testABareHostWithAPortIsParsedRatherThanComparedLiterally(): void
    {
        self::assertNull(Offline::refusalForHost('127.0.0.1:1025', 'a local test server'));
        self::assertNull(Offline::refusalForHost('localhost', 'a local test server'));
        self::assertNotNull(Offline::refusalForHost('imap.example.test:993', 'the alert mailbox'));
    }

    /** The message names the component, never the credential — it is shown to the operator. */
    public function testTheRefusalNamesTheComponentAndNotTheCredential(): void
    {
        $problem = (string) Offline::refusalForHost('imap.example.test:993', 'the alert mailbox');

        self::assertStringContainsString('the alert mailbox', $problem);
        self::assertStringContainsString('RENT_WATCH_OFFLINE=1', $problem);
    }

    /**
     * `mail()` has no host to inspect, so there is no loopback exemption and none is wanted.
     */
    public function testLocalDeliveryIsRefusedOutrightBecauseAnMtaMayRelay(): void
    {
        self::assertNotNull(Offline::refusalForLocalDelivery('a notification'));
    }

    /**
     * One list, two entry points — the round-7 finding that `SmtpTransport` kept a second copy.
     *
     * Asserted as an EQUIVALENCE rather than by re-listing the addresses: a test that repeats the
     * list is a third copy, and would drift the same way the second one did.
     */
    public function testTheHostPredicateAndTheUrlPredicateAgree(): void
    {
        foreach (['127.0.0.1', 'localhost', '[::1]', 'LOCALHOST', 'evil.test', 'mailhog', 'mailpit'] as $host) {
            self::assertSame(
                Offline::isLoopbackHost($host),
                Offline::isLoopback('//' . $host),
                $host . ': the bare-host and url forms must never disagree',
            );
        }
    }

    /**
     * A BARE IPv6 loopback, which the equivalence test above found diverging.
     *
     * `parse_url('//::1')` returns the host `:`, so the exemption missed it and a wire test bound
     * to `::1` was refused. Fail-closed, so never a leak — but `isLoopbackHost('::1')` said true
     * while this said false, the same two-predicates-disagreeing shape as the `SmtpTransport` copy.
     * `refusalForHost()` now brackets it before parsing.
     */
    public function testABareIpv6LoopbackIsExemptedLikeItsBracketedForm(): void
    {
        self::assertNull(Offline::refusalForHost('::1', 'a local test server'));
        self::assertNull(Offline::refusalForHost('[::1]:1025', 'a local test server'));
        self::assertNotNull(Offline::refusalForHost('2001:db8::1', 'a third-party host'));
    }
}
