<?php

declare(strict_types=1);

namespace RentWatch\Adapters;

use RentWatch\Adapters\Mail\EmailMessage;
use RentWatch\Adapters\Mail\Mailbox;
use RentWatch\Adapters\Mail\MailboxError;
use RentWatch\Config\SourceDefinition;
use RentWatch\Core\RawListing;
use RentWatch\Core\SourceHealth;
use RentWatch\Core\SourceProfile;
use RentWatch\Core\Tenure;
use RentWatch\Store\Store;

/**
 * Turns portal alert emails into listings. The primary path for private portals (hard rule 4).
 *
 * **THIS IS DELIBERATELY CONSERVATIVE, and the reason is worth stating up front.** No real portal
 * alert has been seen yet, so every extraction rule here is written against generic structures — a
 * link per listing, a rent that looks like a rent, a commune from the configured list — rather than
 * against any one portal's layout. It is built to be SHAPED by a real message, not to guess one:
 * the moment an actual alert lands in `tests/fixtures/<portal>/`, its quirks become a fixture and
 * this class grows a per-portal override under `Adapters/sites/`.
 *
 * The consequence is honest and stated: **a listing this parser cannot read confidently gets no
 * tenure signal, so on a `mixed_tenure` source it DIGESTS rather than matching.** That is the
 * fail-closed direction, and it means an under-powered parser costs digest entries rather than
 * wrong notifications.
 *
 * What the class is NOT allowed to do, ever: follow the links to scrape the portal page. That is
 * the scraping route hard rule 4 gates behind an explicit flag, and doing it invisibly from the
 * email path would route around the gate entirely.
 */
final readonly class EmailAlertSource implements Source
{
    /**
     * Where a rent lives in an alert. Ordered: the most explicit form wins.
     *
     * Accent-folded input is NOT assumed here — this reads the raw message text, before folding,
     * because the folded surface is the classifier's and this is extraction.
     *
     * **THE SEPARATOR IS `\h`, NEVER `\s`, AND THE DIFFERENCE IS A WRONG RENT.** French rents are
     * written `1 450 €` with a narrow no-break space, so the digit class has to admit whitespace as
     * a thousands separator — and it did that with `\s`, which also matches `\n`. A line ending in
     * a digit therefore glues itself to the price below it, and `Payload::int` truncates at the
     * newline, so the rent becomes whatever sat on the previous line: `ref 850` above
     * `1 450 EUR charges comprises` extracts **850** [Verified 2026-08-25], six hundred euros below
     * reality, inside the plausibility band, clearing a ceiling the real rent does not.
     *
     * `\h` under `/u` already covers U+00A0 and U+202F, which is what the explicit escapes were
     * for. The trailing `\s*` before the currency marker stays `\h*` for the same reason — a figure
     * and a currency sign on two different lines are not one price.
     */
    private const array RENT_PATTERNS = [
        '~(\d[\d\h.,]{2,})\h*(?:€|EUR|euros?)\h*(?:/\h*mois|par mois|mensuel)?\h*(?:CC|charges comprises)~iu',
        '~(?:loyer|prix)\h*(?:CC|charges comprises)?\h*:?\h*(\d[\d\h.,]{2,})\h*(?:€|EUR|euros?)~iu',
        '~(\d[\d\h.,]{2,})\h*(?:€|EUR|euros?)~iu',
    ];

    private const string SURFACE_PATTERN = '~(\d{1,4}(?:[.,]\d{1,2})?)\s*(?:m²|m2|m\^2)~iu';

    private const string ROOMS_PATTERN = '~(?:T|F)\s?(\d)\b|\b(\d)\s*pi[eè]ces?\b~iu';

    public function __construct(
        private SourceDefinition $definition,
        private Store $store,
        private Mailbox $mailbox,
        /**
         * Communes to look for in the message text, from `config/criteria.json`.
         *
         * Passed in rather than guessed, because a commune is the one field an alert reliably
         * carries and the one the criteria engine cannot do without (Q32: no location evidence is a
         * rejection). Matching against the configured list rather than trying to recognise any
         * French place name keeps this out of the business of geocoding.
         *
         * @var array<string,string> canonical key => label as configured
         */
        private array $communeLabels = [],
        private int $limit = 50,
    ) {}

    public function name(): string
    {
        return $this->definition->name;
    }

    /**
     * `null` on purpose, and it is a ruling rather than an omission. An alert mailbox is one IMAP
     * connection to the user's OWN mail provider — not a landlord's website, and not a party that
     * can rate-limit or ban this tool. Q37's host pacing exists to protect the sources; applying it
     * here would add dead time to every pass while protecting nobody, and would let the mailbox
     * consume the distinct-host slot ahead of the requests that actually need it.
     *
     * This is also why hard rule 4 makes email ingestion the PRIMARY path: there is no bot to block.
     */
    public function host(): ?string
    {
        return null;
    }

    public function family(): string
    {
        return $this->definition->family === 'private' ? 'private' : 'institutional';
    }

    public function defaultTenure(): ?Tenure
    {
        return $this->definition->defaultTenure;
    }

    public function profile(): SourceProfile
    {
        return $this->definition->profile();
    }

    public function fetch(): array
    {
        try {
            $messages = $this->mailbox->fetchRecent($this->limit);
        } catch (MailboxError $e) {
            throw new SourceError($this->name(), $e->getMessage(), $e);
        }

        $listings = [];

        foreach ($messages as $raw) {
            $message = EmailMessage::parse($raw);

            // Only messages this source sent. A shared mailbox receiving alerts from four portals
            // must not have every message attributed to whichever source polls first — that would
            // give each listing the wrong `mixed_tenure` flag, which is the §1 switch.
            if (!$this->isFrom($message)) {
                continue;
            }

            foreach ($this->listingsIn($message) as $listing) {
                $listings[] = $listing;
            }
        }

        return $listings;
    }

    public function health(?string $nowIso = null): SourceHealth
    {
        return $this->store->health($this->name(), $nowIso);
    }

    /** Where the alert must have come from, matched against the source's configured `params.from`. */
    private function isFrom(EmailMessage $message): bool
    {
        $expected = $this->definition->params['from'] ?? null;

        if ($expected === null || $expected === '') {
            // No filter configured: accept everything. Loud in the wrong direction, but the
            // alternative — silently dropping every message — would look exactly like an empty
            // mailbox, and hard rule 2 exists because that is indistinguishable from a quiet market.
            return true;
        }

        return stripos($message->from(), $expected) !== false;
    }

    /**
     * One listing per link, with whatever facts the surrounding text yields.
     *
     * **The link is the identity**, because it is the only thing in an alert that is stable across
     * messages: a portal re-sends the same flat in tomorrow's digest with different surrounding
     * prose, and keying on anything else would notify it twice. Query parameters are stripped from
     * the id — alert links carry tracking parameters that change per send, and keying on those
     * would make every listing new forever, which is precisely the failure `ListingMapper` refuses
     * a synthetic id for.
     *
     * @return list<RawListing>
     */
    private function listingsIn(EmailMessage $message): array
    {
        $body = $message->body;
        $out = [];

        foreach ($message->links as $link) {
            if (!$this->looksLikeAListing($link)) {
                continue;
            }

            $id = self::stableId($link);

            // The whole message is given as the description, and that is deliberate rather than
            // lazy: the classifier reads text for tenure vocabulary, and a portal that mentions
            // `logement conventionné` once for a batch must not have that lost by a segmentation
            // guess this class is not equipped to make. Over-inclusion costs a digest entry; the
            // under-inclusion it replaces would cost the §1 signal.
            $out[] = new RawListing(
                sourceName: $this->name(),
                externalId: $id,
                title: $message->subject(),
                description: $body,
                fields: ['email.from' => $message->from(), 'email.subject' => $message->subject()],
                url: $link,
                commune: $this->communeIn($body),
                postcode: self::postcodeIn($body),
                rentCc: $this->definition->map->chargesIncluded === true ? self::rentIn($body) : null,
                rentHc: $this->definition->map->chargesIncluded === false ? self::rentIn($body) : null,
                surfaceM2: self::surfaceIn($body),
                rooms: self::roomsIn($body),
            );
        }

        return $out;
    }

    /** A listing link, not an unsubscribe or a tracking pixel. */
    private function looksLikeAListing(string $link): bool
    {
        foreach (['unsubscribe', 'desabonnement', 'desinscription', 'preferences', 'mailto:', '/pixel', 'utm_medium=beacon'] as $noise) {
            if (stripos($link, $noise) !== false) {
                return false;
            }
        }

        $host = $this->definition->params['link_host'] ?? null;

        return $host === null || stripos($link, $host) !== false;
    }

    private static function stableId(string $link): string
    {
        $scheme = parse_url($link, PHP_URL_SCHEME) ?? 'https';
        $host = parse_url($link, PHP_URL_HOST) ?? '';
        $path = parse_url($link, PHP_URL_PATH) ?? '';

        return $scheme . '://' . $host . $path;
    }

    private function communeIn(string $body): ?string
    {
        $folded = \RentWatch\Core\Text::fold($body);

        foreach ($this->communeLabels as $key => $label) {
            if ($key !== '' && str_contains($folded, $key)) {
                return $label;
            }
        }

        return null;
    }

    private static function postcodeIn(string $body): ?string
    {
        // Anchored to the departments in scope rather than any five digits, because a rent, a
        // surface and a reference number are all five digits too, and a wrong postcode is worse
        // than none: it can pass the prefix filter for a listing that is nowhere near.
        return preg_match('~\b((?:78|95|92|91|93|94|77|75)\d{3})\b~', $body, $m) === 1 ? $m[1] : null;
    }

    private static function rentIn(string $body): ?int
    {
        foreach (self::RENT_PATTERNS as $pattern) {
            if (preg_match($pattern, $body, $m) === 1) {
                $value = Payload::int(['v' => $m[1]], ['v']);

                // A plausibility band. Without it, `2024` from a date and `95240` from a postcode
                // both parse as rents — and a rent of 2024 € would pass nothing while a rent of 95
                // would pass everything with maximum headroom.
                if ($value !== null && $value >= 200 && $value <= 20000) {
                    return $value;
                }
            }
        }

        return null;
    }

    private static function surfaceIn(string $body): ?float
    {
        if (preg_match(self::SURFACE_PATTERN, $body, $m) !== 1) {
            return null;
        }

        $value = Payload::float(['v' => $m[1]], ['v']);

        return $value !== null && $value >= 5.0 && $value <= 1000.0 ? $value : null;
    }

    private static function roomsIn(string $body): ?int
    {
        if (preg_match(self::ROOMS_PATTERN, $body, $m) !== 1) {
            return null;
        }

        $digits = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');
        $value = $digits === '' ? null : (int) $digits;

        return $value !== null && $value >= 1 && $value <= 12 ? $value : null;
    }
}
