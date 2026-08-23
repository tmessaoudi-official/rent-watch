<?php

declare(strict_types=1);

namespace RentWatch\Core;

/**
 * The offline tripwire, in ONE place, because it guards a promise the whole suite rests on.
 *
 * `RENT_WATCH_OFFLINE=1` turns every outbound request into a loud refusal, and
 * `tests/bootstrap.php` sets it for the whole suite. Spec §11 says parser tests run offline; before
 * 2026-08-19 that held only BY ACCIDENT, because every source in `config/sources.json` was disabled
 * and so there was nothing to poll. Enabling In'li turned the suite into a four-page crawler of a
 * live landlord's site, once per test.
 *
 * **It lived on `CurlHttpClient` alone, which made the promise smaller than it read.**
 * `tests/bootstrap.php` calls that client "the backstop for the ones that are not given fakes", and
 * {@see \RentWatch\Core\Notify\NtfyChannel} calls libcurl DIRECTLY — its own sibling test says so —
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
    public static function refusal(string $url): ?string
    {
        if (getenv('RENT_WATCH_OFFLINE') !== '1' || self::isLoopback($url)) {
            return null;
        }

        return 'RENT_WATCH_OFFLINE=1 — refusing to request ' . $url
            . '. Tests and dry runs must not reach the network; use a fake client or a frozen fixture';
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
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) {
            return false;
        }

        return in_array(strtolower(trim($host, '[]')), ['127.0.0.1', 'localhost', '::1'], true);
    }
}
