<?php

declare(strict_types=1);

namespace Scout\Core;

/**
 * The offline tripwire, in ONE place, because it guards a promise the whole suite rests on.
 *
 * `SCOUT_OFFLINE=1` turns every outbound request into a loud refusal, and
 * `tests/bootstrap.php` sets it for the whole suite. Spec §11 says parser tests run offline; before
 * 2026-08-19 that held only BY ACCIDENT, because every source in `config/rent/sources.json` was disabled
 * and so there was nothing to poll. Enabling In'li turned the suite into a four-page crawler of a
 * live landlord's site, once per test.
 *
 * **It lived on `CurlHttpClient` alone, which made the promise smaller than it read.**
 * `tests/bootstrap.php` calls that client "the backstop for the ones that are not given fakes", and
 * {@see \Scout\Core\Notify\NtfyChannel} calls libcurl DIRECTLY — its own sibling test says so —
 * so it never passed the funnel. A review panel demonstrated it on 2026-08-24: with the flag set,
 * the channel resolved and dialled a non-loopback host. Nothing was breached (the only test that
 * builds a live channel points it at `127.0.0.1:1`), but the guarantee was two `putenv` lines away
 * from a public POST carrying a listing title and url to a documented-secret topic.
 *
 * So the predicate is here, in Core, where an adapter and a notification channel can both reach it
 * without either depending on the other. A second copy would be one edit away from disagreeing with
 * the first about what "loopback" means, and the disagreement would be invisible until it mattered.
 *
 * **Loopback stays allowed, deliberately.** A scripted server on `127.0.0.1` is how this project
 * proves the things only a real socket can prove — that the honest User-Agent is what actually
 * crosses the wire, and that SMTP refuses a credential on a connection the server did not upgrade.
 * Banning those would delete real evidence in order to enforce a rule they do not break.
 */
final class Offline
{
    /**
     * Why this url must not be requested, or `null` if it may be.
     *
     * A REASON rather than a bool, so every caller refuses with the same sentence — including the
     * instruction for what to do instead, which is the part that turns a mysterious failure into a
     * one-line fix.
     */
    public static function refusal(string $target): ?string
    {
        if (getenv('SCOUT_OFFLINE') !== '1' || self::isLoopback($target)) {
            return null;
        }

        return 'SCOUT_OFFLINE=1 — refusing to reach ' . $target
            . '. Tests and dry runs must not reach the network; use a fake client or a frozen fixture';
    }

    /**
     * The same refusal for a target that has no url — a bare `host:port`, or a local MTA handoff.
     *
     * **There are FIVE egress points in this tree, not one**, and the first version of this class
     * guarded two: `CurlHttpClient` and `NtfyChannel`. `SmtpTransport` and `ImapMailbox` open raw
     * sockets with `stream_socket_client()` and `SendmailTransport` hands to `mail()`, so all three
     * escaped — while this docblock claimed *every* outbound request was refused. A review panel
     * demonstrated SMTP and IMAP dialling a non-loopback host with the flag set, on 2026-08-24.
     *
     * IMAP is the one that matters most: hard rule 4 makes email-alert ingestion the PRIMARY path
     * for private portals, and it sends a cleartext password to a host read from `.env`.
     *
     * `$describe` is what the operator sees, so it must name the component rather than the
     * credential — never interpolate a user or a password into it.
     */
    public static function refusalForHost(string $host, string $describe): ?string
    {
        // The bare host may carry a port (`mail.example.test:993`) and `parse_url` will not read it
        // as a host without a scheme, so one is supplied. Anything unparseable falls through to the
        // literal comparison, which fails closed.
        //
        // A BARE IPv6 literal has to be bracketed first: `parse_url('//::1')` returns the host `:`,
        // not `::1`, so the loopback exemption silently missed it and a wire test bound to `::1`
        // was refused. Fail-closed, so it was never a leak — but `isLoopbackHost('::1')` says true
        // and this said false, which is precisely the two-predicates-disagreeing shape round 7
        // found between this class and `SmtpTransport`. Found by the equivalence test written for
        // that finding.
        if (self::refusal(str_contains($host, '://') ? $host : '//' . $host) === null) {
            return null;
        }

        return 'SCOUT_OFFLINE=1 — refusing to reach ' . $describe . ' at ' . $host
            . '. Tests and dry runs must not reach the network; use a fake transport or a frozen fixture';
    }

    /**
     * Refuse a handoff that has no host at all — `mail()`, which gives the message to a local MTA
     * that may relay it anywhere.
     *
     * No loopback exemption is possible here, and none is wanted: there is nothing to inspect, and
     * a test that reaches this has already lost control of where the message goes.
     */
    public static function refusalForLocalDelivery(string $describe): ?string
    {
        if (getenv('SCOUT_OFFLINE') !== '1') {
            return null;
        }

        return 'SCOUT_OFFLINE=1 — refusing to hand ' . $describe . ' to the local MTA, which may relay it. '
            . 'Tests and dry runs must not reach the network; use SMTP_TRANSPORT=file';
    }

    /**
     * Is this a loopback address?
     *
     * Compared against the parsed HOST rather than searched for in the url, because
     * `https://evil.test/?x=127.0.0.1` contains the string and is not loopback. The `[]` trim is for
     * the bracketed IPv6 form.
     */
    public static function isLoopback(string $url): bool
    {
        $host = parse_url(self::bracketBareIpv6($url), PHP_URL_HOST);
        if (!is_string($host)) {
            return false;
        }

        // `parse_url` reads `user:pw@localhost` as userinfo plus the host `localhost`, so a value
        // carrying credentials would take the loopback exemption on the strength of the part after
        // the `@`. Nothing in the tree produces that shape — the adapters pass `$host . ':' . $port`
        // — but it comes from `.env`, and this predicate decides whether a request is allowed to
        // leave. Fail closed.
        if (str_contains($url, '@')) {
            return false;
        }

        return self::isLoopbackHost($host);
    }

    /**
     * Bracket a BARE IPv6 literal so `parse_url` can read it as a host.
     *
     * `parse_url('//::1')` returns the host `:`, not `::1` — so the two predicates in this class
     * disagreed about `::1` for a round: {@see isLoopbackHost()} said loopback and
     * {@see isLoopback()} said third-party. Fail-closed, so never a leak, and that is exactly what
     * made it survive: it was worked around inside {@see refusalForHost()} rather than fixed here,
     * and the equivalence test written to catch two predicates disagreeing was given the BRACKETED
     * form and so could not see the surviving instance.
     *
     * Applied at the one place the parse happens, so there is a single truth.
     */
    private static function bracketBareIpv6(string $url): string
    {
        $authority = $url;
        $scheme = strpos($authority, '://');
        if ($scheme !== false) {
            $authority = substr($authority, $scheme + 3);
        } elseif (str_starts_with($authority, '//')) {
            $authority = substr($authority, 2);
        } else {
            return $url;
        }

        $authority = strtok($authority, '/?#');
        if ($authority === false || str_contains($authority, '[') || substr_count($authority, ':') < 2) {
            return $url;
        }

        return str_replace($authority, '[' . $authority . ']', $url);
    }

    /**
     * The same question asked of a BARE HOST, for callers that never had a URL.
     *
     * `SmtpTransport` is one: it holds `SMTP_HOST` and gates `SMTP_SECURITY=none` on it. It carried
     * a private copy of this list until round 7, and the copy had already drifted — it also
     * admitted `mailhog` and `mailpit`, two strings that appear nowhere else in the repo (not
     * `.env.example`, not `compose.yaml`, not a doc). The class docblock above says a second copy
     * "would be one edit away from disagreeing with the first about what loopback means, and the
     * disagreement would be invisible until it mattered". It was, and it did.
     *
     * Split rather than merged because the two inputs are genuinely different shapes: parsing
     * `mailpit` as a URL yields no host at all, so a bare host handed to {@see isLoopback()} reads
     * as third-party. One list, two entry points.
     */
    public static function isLoopbackHost(string $host): bool
    {
        return in_array(strtolower(trim($host, '[]')), ['127.0.0.1', 'localhost', '::1'], true);
    }
}
