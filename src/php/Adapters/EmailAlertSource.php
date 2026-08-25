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
use RentWatch\Core\Text;
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
    /**
     * Most specific first — the first pattern to yield a PLAUSIBLE figure wins.
     *
     * The third entry is the reason the order matters, and it was added on 2026-08-25 after a live
     * *"Baisse de prix"* alert. That template quotes three amounts: the reduction
     * (`baissé de 100 €`), the new rent (`1 100 €/mois`) and the struck-through old one
     * (`1 200 € ↘ 8%`). Only one of the three carries a PERIOD, and it is the only one that is a
     * rent — so a periodic amount without `charges comprises` outranks a bare figure, and the bare
     * figure stays last for cards that state nothing else.
     *
     * Without it the reader returned the DISCOUNT. Here that was 100 €, below the plausibility
     * floor, so the card was refused and the source reported `broken` on an unchanged template —
     * the benign direction. A 300 € reduction would have been returned as the rent: inside the
     * band, six hundred euros wrong, clearing a ceiling the flat comes nowhere near.
     */
    private const array RENT_PATTERNS = [
        '~(\d[\d\h.,]{2,})\h*(?:€|EUR|euros?)\h*(?:/\h*mois|par mois|mensuel)?\h*(?:CC|charges comprises)~iu',
        '~(?:loyer|prix)\h*(?:CC|charges comprises)?\h*:?\h*(\d[\d\h.,]{2,})\h*(?:€|EUR|euros?)~iu',
        '~(\d[\d\h.,]{2,})\h*(?:€|EUR|euros?)\h*(?:/\h*mois|par mois|mensuel)~iu',
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
     * **Both halves of that paragraph fail on a real SeLoger alert, which is why `card_separator`
     * exists.** Every link in one is `click.by.seloger.com/?qs=<opaque token>`; strip the query and
     * all sixteen collapse to `https://click.by.seloger.com/`. Sixteen cards, one identity. And
     * "the surrounding text" is the whole message, so each of the sixteen would take the FIRST rent
     * and the FIRST surface in it — one flat's facts, sixteen times.
     *
     * With a separator configured, the body is cut into cards and each is read on its own. Without
     * one, the behaviour above is unchanged: `email_demo` and every portal whose alerts do carry a
     * real listing URL keep working exactly as before.
     *
     * @return list<RawListing>
     */
    private function listingsIn(EmailMessage $message): array
    {
        $separator = $this->stringParam('card_separator');

        if ($separator !== null) {
            return $this->cardsIn($message, $separator);
        }

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

    /**
     * The message cut into cards, one listing each.
     *
     * The separator is the card's terminal call to action — *Voir l'annonce* — which is the one
     * element every portal's card template ends with. Splitting on it makes each card the TAIL of
     * its segment: segment 0 carries the message header plus card 1, segment 1 carries card 2, and
     * the final segment is the trailer, which yields no rent and is therefore not a card.
     *
     * **The description is the CARD, not the message**, and that is the Cityloger ruling applied to
     * a new surface: a map must address the listing, never the page. Whole-body description is the
     * furniture failure class that sent 14 of 16 correctly-badged CDC listings to the digest, and
     * an alert's CNIL trailer, campaign code and "gérer mes alertes" block are furniture by
     * construction. The subject travels with each card because it is the listing's title when the
     * card carries none.
     *
     * @return list<RawListing>
     */
    private function cardsIn(EmailMessage $message, string $separator): array
    {
        $segments = explode($separator, $message->body);
        $out = [];
        $seenIds = [];

        foreach ($segments as $segment) {
            $listing = $this->cardListing($message, $segment);

            if ($listing === null) {
                continue;
            }

            // Two DISTINCT cards resolving to one identity is an extraction failure, not a re-send.
            // Scoped to the message on purpose: ACROSS messages the same id is exactly the
            // legitimate re-send that content-addressing exists to recognise, so the guard cannot
            // live in the store. Within one message it means the fields that tell two flats apart
            // were not read, and the second flat would be silently swallowed for ever.
            if (isset($seenIds[$listing->externalId])) {
                throw new SourceError(
                    $this->name(),
                    'deux annonces distinctes du même message partagent une identité ('
                        . $listing->externalId . ') — les champs qui les distinguent n\'ont pas été lus',
                );
            }

            $seenIds[$listing->externalId] = true;
            $out[] = $listing;
        }

        // Hard rule 2's shape. A message that plainly contains cards — it carries the separator —
        // and yields none is a broken parser, and a broken parser here is indistinguishable from a
        // market that went quiet. The alert itself usually says how many listings it carries.
        if ($out === [] && count($segments) > 1) {
            throw new SourceError(
                $this->name(),
                'le message contient ' . (count($segments) - 1) . ' séparateur(s) de carte mais '
                    . 'aucune annonce n\'a pu en être extraite — le gabarit du portail a changé',
            );
        }

        return $out;
    }

    /**
     * One card, or `null` when the segment is not one.
     *
     * A segment qualifies only if it yields a rent AND enough to locate the flat. The rent gate is
     * what drops the trailer; the locating gate is the **no-information floor**, and it is the part
     * that would otherwise have shipped as a defect.
     *
     * Without it a card whose extraction failed across the board hashes to `sha1('seloger|||||')`
     * — and EVERY such card collapses onto that one id. That is the store's own *"nothing collapses
     * onto a shared key"* identity guarantee violated one layer up, where the store cannot see it.
     * Refusing costs nothing real: a listing with no commune, no postcode, no rooms and no surface
     * can never match anyway (Q32 — no location evidence is a rejection), so the alternative to
     * skipping it is a listing that is rejected AND poisons an identity every other broken card
     * would share.
     */
    private function cardListing(EmailMessage $message, string $segment): ?RawListing
    {
        $rent = self::rentIn($segment);

        if ($rent === null) {
            return null;
        }

        $commune = $this->communeIn($segment);
        $postcode = self::postcodeIn($segment);
        $rooms = self::roomsIn($segment);
        $surface = self::surfaceIn($segment);
        $residence = $this->matchParam('residence_pattern', $segment);

        $id = $this->identityFor($commune, $postcode, $rooms, $surface, $residence);

        if ($id === null) {
            return null;
        }

        // THE LAST LINK, NOT THE FIRST — and the difference is the whole value of the notification.
        //
        // Reported by the developer 2026-08-25: clicking a SeLoger push opened THE SAVED SEARCH they
        // had created, not the flat. Taking the first qualifying link reads whatever furniture the
        // portal put above the card. Measured on a live five-card alert: card one's first link is
        // "mettre en pause les envois" (alert management), card two's is a third-party advert
        // ("Estimez le prix de votre déménagement"), card three's is the photo. One of three reached
        // the flat, by luck.
        //
        // The last one reaches it structurally: `card_separator` IS the call to action, and a URL
        // precedes its own anchor text in this rendering, so a segment ENDS with the CTA's link
        // whatever sits above it. Verified against the message's HTML part, which names each anchor
        // — price, title, details, location and `Voir l'annonce` all address the listing, the header
        // does not. No redirect was followed to establish it: the tokens are per-subscriber, and
        // following one manufactures an engagement signal from a click nobody made.
        //
        // Only on the SEGMENTED path. Without a separator every link IS a listing, and reversing
        // there would pick a different flat rather than a different link on the same flat.
        $link = null;
        foreach (array_reverse(self::linksIn($segment)) as $candidate) {
            if ($this->looksLikeAListing($candidate)) {
                $link = $candidate;

                break;
            }
        }

        return new RawListing(
            sourceName: $this->name(),
            externalId: $id,
            title: $this->matchParam('title_pattern', $segment) ?? $message->subject(),
            description: trim($message->subject() . "\n" . $segment),
            fields: [
                'email.from' => $message->from(),
                'email.subject' => $message->subject(),
            ],
            // The tracking redirect goes in UNRESOLVED, and that is a ruling. Following it here
            // would be one third-party request per listing, on a token tied to the subscriber's
            // identity, manufacturing an engagement signal from a click nobody made — hard rule 5's
            // "identify honestly" one step further out. The link is clicked by the human who wanted
            // to see the ad, which is what it is for.
            url: $link,
            commune: $commune,
            postcode: $postcode,
            rentCc: $this->definition->map->chargesIncluded === true ? $rent : null,
            rentHc: $this->definition->map->chargesIncluded === false ? $rent : null,
            surfaceM2: $surface,
            rooms: $rooms,
        );
    }

    /**
     * A content-addressed identity, or `null` below the no-information floor.
     *
     * **Rent is deliberately absent from the key.** A price drop is an event this project exists to
     * detect; a rent in the identity turns every drop into a brand-new listing — notified as new,
     * with no price history and no *en baisse* reason — which is the silent opposite of what the
     * store's price-history table is for.
     *
     * What IS in the key are the dwelling's structural facts, which is why this does not fall foul
     * of `ListingMapper`'s refusal of synthetic ids. That refusal is about a hash of the ad's TEXT:
     * it changes whenever the copy is touched, so the listing is new on every run and notifies for
     * ever. Commune, postcode, rooms, surface and residence do not move when the prose is rewritten.
     *
     * **Stated cost, twice.** Two units in one residence with identical rooms and surface share an
     * identity, so the second is treated as already seen — a miss, in the §1-safe direction. And a
     * card that gains a previously-missing surface in a later email changes identity once, and so
     * notifies once more.
     */
    private function identityFor(
        ?string $commune,
        ?string $postcode,
        ?int $rooms,
        ?float $surface,
        ?string $residence,
    ): ?string {
        if ($this->stringParam('id_from') !== 'content') {
            return null;
        }

        // The floor: something that LOCATES the flat, and something that DESCRIBES it. Either half
        // alone is not an identity — every card in a message shares a commune often enough, and a
        // bare `3 pièces` is shared by half a portal.
        $locating = $commune ?? $postcode;
        $describing = $rooms !== null || $surface !== null || ($residence !== null && $residence !== '');

        if ($locating === null || $locating === '' || !$describing) {
            return null;
        }

        $key = implode('|', [
            $this->name(),
            Text::fold($commune ?? ''),
            $postcode ?? '',
            $rooms === null ? '' : (string) $rooms,
            // Two decimals: a portal that reports 44.71 one week and 44.7 the next must not mint a
            // second identity for one flat.
            $surface === null ? '' : number_format($surface, 2, '.', ''),
            Text::fold($residence ?? ''),
        ]);

        return sha1($key);
    }

    /** @return list<string> */
    private static function linksIn(string $text): array
    {
        preg_match_all('~https?://[^\s<>"\'\)\]]+~i', $text, $matches);

        return array_values(array_unique(array_map(
            static fn (string $l): string => rtrim($l, '.,;:'),
            $matches[0],
        )));
    }

    /** A configured `params` entry, when it is a non-empty string. */
    private function stringParam(string $key): ?string
    {
        $value = $this->definition->params[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /** First capture of a configured regex, or `null` when the key is unset or matches nothing. */
    private function matchParam(string $key, string $subject): ?string
    {
        $pattern = $this->stringParam($key);

        if ($pattern === null) {
            return null;
        }

        if (@preg_match($pattern, $subject, $m) !== 1) {
            return null;
        }

        $captured = trim($m[1] ?? '');

        return $captured === '' ? null : $captured;
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

    /**
     * The commune this card is in — read from the portal's own layout first, the ranked vocabulary
     * second.
     *
     * **The vocabulary alone is not a reader, and on a region-mode config it reads nothing.**
     * `communeLabels` is built from the RANKED communes, which are a preference and not a filter
     * (Q1, 2026-08-22): a watch covering all of Île-de-France ranks a handful of towns and knows the
     * names of no others. Every SeLoger listing therefore came back with a commune of `null` while
     * its postcode parsed correctly — measured 2026-08-25 against the live mailbox, 9 cards, 9
     * nulls. Nothing about that looks like a fault from outside: the listing still matches on its
     * postcode, so the operator sees a notification that cannot say where the flat is, a weaker
     * `Dedup` key, and an S1 commune score that never fires.
     *
     * `commune_pattern` is the fix and it is CONFIG, per portal, because the anchor is the portal's
     * template rather than anything general about French addresses. SeLoger's is the parenthesised
     * postcode on the line BELOW the name — chosen over the *quartier* comma on the line above
     * because that line is optional: three of the nine live cards have no quartier at all.
     *
     * ORDER MATTERS, and the pattern wins. It is structural — it reads the field the portal laid
     * out — while the vocabulary is a substring scan that will happily return a ranked commune the
     * copy merely mentions (*"proche Chatou"*), which is the prototype's over-matching defect this
     * repo already documents. The scan stays as the fallback so a source with no `commune_pattern`
     * behaves exactly as it did before this existed.
     *
     * A pattern that matches nothing returns `null` rather than a guess (hard rule 9): unknown, not
     * elsewhere.
     */
    private function communeIn(string $body): ?string
    {
        $configured = $this->matchParam('commune_pattern', $body);

        if ($configured !== null) {
            return $configured;
        }

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

    /**
     * The rent in a card, or `null` when no figure in it is credible as one.
     *
     * **Every match of a pattern is examined, not just the first**, and that is the other half of
     * the price-drop fix. `preg_match` stops at the first hit, so on a card reading
     * `baissé de 100 €` … `1 100 €/mois` the bare-figure pattern returned 100 and the reader gave
     * up — one implausible figure hiding a perfectly readable rent three lines below it. Scanning
     * all matches and taking the first PLAUSIBLE one costs nothing and removes a whole class of
     * "the first number that looks like money" failures.
     */
    private static function rentIn(string $body): ?int
    {
        foreach (self::RENT_PATTERNS as $pattern) {
            if (preg_match_all($pattern, $body, $matches, PREG_PATTERN_ORDER) === false) {
                continue;
            }

            foreach ($matches[1] ?? [] as $candidate) {
                $value = Payload::int(['v' => $candidate], ['v']);

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
