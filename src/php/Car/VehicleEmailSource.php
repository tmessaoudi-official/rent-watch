<?php

declare(strict_types=1);

namespace Scout\Car;

use Scout\Adapters\FeedFreshness;
use Scout\Adapters\Mail\EmailMessage;
use Scout\Adapters\Mail\Mailbox;
use Scout\Adapters\Mail\MailboxError;
use Scout\Adapters\SourceError;
use Scout\Core\MalformedText;
use Scout\Core\SourceHealth;
use Scout\Core\Text;

/**
 * A car portal's saved-search alert, read from a mailbox — the rent side's `EmailAlertSource`
 * rebuilt for cars, with the lessons that adapter paid for already applied:
 *
 * - **every reader is POSITIONAL**, anchored on the card's own layout (a `€` line, a facts line
 *   `<body> - <fuel> - Année <year> - <km> km`), never a body-wide scan: the alert quotes the
 *   subscriber's own criteria above the cards (`Jusqu'à 30 000 € … Jusqu'à 100 000 km`) and a
 *   first-match reader returns the search floor — PAP's defect, on the day it was built;
 * - **the subject is part of the source's scope**: one sender serves the rent alert, the car alert
 *   and marketing, so `params.from` alone admits three kinds of message;
 * - **identity is the ad link** (`/a/voiture-occasion/<make>/<model>/<id>`), the LAST matching link
 *   in a segment, which is the card's own; make and model are read off the same path;
 * - **a listing is observed when its message was sent** — `observedAt` from `sentAt()` — so a
 *   re-read older message is a superseded sighting at the store, never a price drop.
 *
 * A card whose price line is missing still yields a listing with `priceEur = null` (unknown is
 * not zero); a segment with no ad link yields nothing — it is the header or the footer.
 */
final readonly class VehicleEmailSource implements VehicleSource, FeedFreshness
{
    /** @param ?\Closure(string): void $warn */
    public function __construct(
        private readonly VehicleSourceDefinition $definition,
        private readonly VehicleStore $store,
        private readonly Mailbox $mailbox,
        private readonly ?\Closure $warn = null,
        private readonly int $limit = 50,
    ) {}

    public function name(): string
    {
        return $this->definition->name;
    }

    public function family(): string
    {
        return $this->definition->family;
    }

    public function host(): ?string
    {
        return null;
    }

    public function newestFeedItemAt(): ?string
    {
        return $this->mailbox->newestMessageAt();
    }

    public function fetch(): array
    {
        try {
            $messages = $this->mailbox->fetchRecent($this->limit);
        } catch (MailboxError $e) {
            throw new SourceError($this->name(), $e->getMessage(), $e);
        }

        $from = strtolower((string) $this->definition->param('from'));
        $subjectPattern = $this->definition->param('subject_pattern');
        $separator = $this->definition->param('card_separator');
        $out = [];

        foreach ($messages as $raw) {
            $message = EmailMessage::parse($raw);
            if ($from !== '' && !str_contains(strtolower($message->from()), $from)) {
                continue;
            }
            if ($subjectPattern !== null && preg_match($subjectPattern, $message->subject()) !== 1) {
                continue;
            }

            $segments = $separator === null ? [$message->body] : explode($separator, $message->body);
            $seen = [];
            foreach ($segments as $segment) {
                $card = $this->cardListing($message, $segment);
                if ($card === null) {
                    continue;
                }
                if (isset($seen[$card->externalId])) {
                    ($this->warn)?->__invoke(sprintf('%s : annonce %s en double dans un même courrier — une seule gardée', $this->name(), $card->externalId));
                    continue;
                }
                $seen[$card->externalId] = true;
                $out[] = $card;
            }
        }

        return $out;
    }

    public function health(?string $nowIso = null): SourceHealth
    {
        return $this->store->runs()->health($this->name(), $nowIso, $this->definition->feedSilentDays);
    }

    private function cardListing(EmailMessage $message, string $segment): ?VehicleListing
    {
        $link = $this->adLinkIn($segment);
        if ($link === null) {
            return null;
        }
        $path = (string) (parse_url($link, PHP_URL_PATH) ?? '');
        $id = basename(rtrim($path, '/'));
        if ($id === '' || $id === '/') {
            return null;
        }

        $lines = preg_split('~\R~u', $segment) ?: [];
        $isUrl = static fn (string $l): bool => str_starts_with(trim($l), 'http://') || str_starts_with(trim($l), 'https://');

        // PRICE — the card's own `€` line, the last one in the segment (the header's criteria line
        // is not a bare price line and the anchored pattern never reads it).
        $price = null;
        $priceLine = null;
        $pricePattern = $this->definition->param('price_pattern');
        if ($pricePattern !== null && preg_match_all($pricePattern, $segment, $m, PREG_OFFSET_CAPTURE) > 0) {
            $last = end($m[1]);
            $price = self::int($last[0]);
            $priceLine = substr_count(substr($segment, 0, $last[1]), "\n");
        }

        // TITLE — the SUBJECT when a pattern names it there, otherwise the card's own lines.
        //
        // **`title_pattern` WAS DECLARED AND UNREAD** (Track 1c), which is the inert-parameter
        // defect the rent side already paid for twice. It is read now because leboncoin needs it:
        // that portal states the vehicle in its SUBJECT — `<dealer> vous propose <MAKE MODEL …> à
        // <price> € à <Commune> (<postcode>)` — and puts nothing but the dealer's name, its rating
        // and `vous présente ses bonnes affaires :` above the price line. Without this the title
        // would be that last sentence, and `gearboxFromTitle()` reads the title, so a card
        // advertising `BOITE AUTOMATIQUE` would score as if it stated no gearbox at all.
        //
        // A CONFIGURED PATTERN THAT MISSES YIELDS `''`, never the positional fallback — the rule
        // `cardTitle()` on the rent side already carries. Falling back would restore the defect and
        // give it an alibi: the row would read as a car whose title is a marketing sentence rather
        // than as an extraction that failed.
        $title = '';
        $titlePattern = $this->definition->param('title_pattern');
        $fromSubject = $titlePattern !== null;

        if ($fromSubject) {
            $title = preg_match($titlePattern, $message->subject(), $t) === 1 ? trim($t[1] ?? '') : '';
        } elseif ($priceLine !== null) {
            for ($i = min($priceLine, count($lines) - 1) - 1; $i >= 0; $i--) {
                $l = trim($lines[$i]);
                if ($l !== '' && !$isUrl($l)) {
                    $title = $l;
                    break;
                }
            }
        }
        if ($title === '' && !$fromSubject) {
            foreach ($lines as $l) {
                $l = trim($l);
                if ($l !== '' && !$isUrl($l)) {
                    $title = $l;
                    break;
                }
            }
        }

        // FACTS — body, fuel, year, mileage, from the one line the portal lays out for them.
        $body = $fuel = null;
        $year = $km = null;
        $factsPattern = $this->definition->param('facts_pattern');
        if ($factsPattern !== null && preg_match_all($factsPattern, $segment, $f, PREG_SET_ORDER) > 0) {
            $facts = end($f);
            $body = self::foldOrNull($facts['body'] ?? null);
            $fuel = self::fuel($facts['fuel'] ?? null);
            $year = isset($facts['year']) ? (int) $facts['year'] : null;
            $km = isset($facts['km']) ? self::int($facts['km']) : null;
        }

        // FURNITURE, not a card. The CTA link that ends a card sits on the line AFTER the separator,
        // so the segment following the last card is the footer carrying that card's link and
        // nothing else — it re-yielded the last card on every message ("en double dans un même
        // courrier", six times per doctor run on 2026-08-29). When the portal lays out a price line
        // and a facts line and a segment has neither, it is not a card.
        if ($pricePattern !== null && $factsPattern !== null && $price === null && $body === null && $year === null) {
            return null;
        }

        // MAKE / MODEL — from wherever the portal states them, and the SOURCE IS NAMED.
        //
        // ParuVendu encodes them in the ad path (`/voiture-occasion/<make>/<model>/`); leboncoin's
        // path is `/vi/<id>.htm` and states the make in the subject instead. `make_model_source`
        // says which, rather than trying the link and falling back to the title: a fallback would
        // let a pattern written for one haystack quietly match the other, which is how an
        // extraction failure acquires an alibi. Unconfigured means `link`, so ParuVendu is
        // unchanged byte for byte.
        //
        // IT MATTERS TO THE SCORE, not just to the display: `brand_avoid` is read off `make`, and
        // an unextracted make scores 0 on that component (Track 1d). A source that states its make
        // and does not map it would rank ten points below an identical car from a source that does.
        $make = $model = null;
        $mm = $this->definition->param('make_model_pattern');
        if ($mm !== null) {
            $haystack = $this->definition->param('make_model_source') === 'title' ? $title : $link;

            if (preg_match($mm, $haystack, $g) === 1) {
                $make = self::foldOrNull($g[1] ?? null);
                $model = self::foldOrNull($g[2] ?? null);
            }
        }

        return new VehicleListing(
            sourceName: $this->name(),
            externalId: $id,
            title: $title,
            description: trim(preg_replace('~https?://\S+~', '', $segment) ?? $segment),
            fields: ['email.from' => $message->from(), 'email.subject' => $message->subject()],
            url: preg_replace('~[?#].*$~', '', $link),
            make: $make,
            model: $model,
            priceEur: $price,
            year: $year,
            mileageKm: $km,
            fuel: $fuel,
            gearbox: self::gearboxFromTitle($title),
            body: $body,
            observedAt: $message->sentAt(),
        );
    }

    /** The LAST link on the ad host in the segment — the card's own; the header's links precede the card. */
    private function adLinkIn(string $segment): ?string
    {
        $host = $this->definition->param('link_host');
        if ($host === null || preg_match_all('~https?://\S+~', $segment, $m) === 0) {
            return null;
        }
        foreach (array_reverse($m[0]) as $candidate) {
            $bare = preg_replace('~^https?://~', '', $candidate) ?? $candidate;
            if (str_starts_with($bare, $host) || str_starts_with($bare, 'www.' . $host)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function int(string $digits): ?int
    {
        return VehicleFacts::int($digits);
    }

    private static function foldOrNull(?string $raw): ?string
    {
        return VehicleFacts::fold($raw);
    }

    public static function fuel(?string $raw): ?string
    {
        return VehicleFacts::fuel($raw);
    }

    public static function gearboxFromTitle(string $title): ?string
    {
        return VehicleFacts::gearbox($title);
    }
}
