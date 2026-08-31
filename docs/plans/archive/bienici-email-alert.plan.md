> SUPERSEDED (2026-08-31) by docs/plans/scout-unified-execution.plan.md. Kept for its
> Decisions Log and measurements; do not execute from this file.

# Bien'ici email-alert source

Source #6, and the second portal on the Tier B email-alert route. Its alerts landed on
2026-08-25 at 17:27 (subscription confirmed), 17:55, 18:40 and 18:56 — four real messages, three
of them alerts and one an agency selection, plus the confirmation.

## Why it is easier than SeLoger, and where that stops being true

SeLoger's whole design problem was that it sends **no listing URL and no listing id**: every link
is `click.by.seloger.com/?qs=<opaque per-recipient token>`, so link identity collapses sixteen
cards onto one id and `id_from: content` had to be invented. Bien'ici does the opposite —
`https://www.bienici.com/annonce/laforet-immo-facile-22588736` carries a real, stable listing id
in the PATH, which `stableId()` already keeps when it strips the query.

So Bien'ici does not need content-addressing, and should not use it: `id_from: content` has two
stated costs (two identical units in one residence share an identity; a card that gains a
previously-missing surface changes identity once and notifies again) that a real id simply does
not have.

**That makes the identity decision ORDER-CRITICAL rather than a preference.** Identity is
permanent. The first enabled pass writes Bien'ici rows keyed on whatever scheme is live, and
switching content→link afterwards makes every stored row look new — the store has no migration for
*"same flat, new key"*, so the source would re-notify its entire backlog. Zero Bien'ici rows exist
right now; this is the only free moment to pick. *Ship config-only today and improve it later* is
the trap here, not the safe option.

## The separator was decided by measurement, not by symmetry

SeLoger splits cards on the terminal call to action (`Voir l'annonce`). Doing the same here is
wrong, and the frozen payloads say so — 13 listings extracted both ways:

| Listing | CTA separator | `Photo` separator | Title states |
|---|---|---|---|
| Choisy-le-Roi | surface **45 m²** | 65 m² | 65 m² |
| Angerville | surface **45 m²** | 56 m² | 56 m² |
| Limay | surface **45 m²** | 62 m² | 62 m² |
| Épône | rooms **4** | 3 | 3 pièces |

Two different contaminations, both invisible in production:

- The **45** is the alert's own criteria line — `Louer région Île-de-France - Maison, appartement -
  1 200 € max - 3 pièces min - 45 m² min` — sitting in segment 0 above the first card. Under
  `min_surface_m2: 50` that rejects the first listing of every alert, silently.
- The **4** is the PRECEDING card's `RÉFÉRENCE : Cocon_Loc_T4`, read by the `T4` branch of the room
  pattern. A card's identity read off its neighbour's reference string.

`Photo` is the line each card STARTS with, so each segment is exactly one card and the header is
its own segment. That segment carries `1 200 €` and would otherwise be a phantom listing; it drops
because it contains no `bienici.com/annonce/` link, which is structural rather than lucky — a
listing link only ever appears inside a card.

**Known non-blocker:** a card published without a photo would merge into its predecessor, yielding
a chimera (the predecessor's facts, the successor's link). 0 of 13 observed. Not guarded now; the
fixtures pin today's shape and the within-message duplicate-identity guard catches the collision
case.

## The scrubber could not see the address it was verifying (P0, found here)

`tools/scrub-eml.php` promises *"It VERIFIES its own work: the address … must be absent from the
output, or it refuses to write."* Bien'ici defeats that with no effort at all: every link carries
`signedRecipient=eyJhbGciOi…`, a JWT whose payload base64url-decodes to
`{"email":"<the developer's address>","iat":…}`. The literal-address check passes — the address
genuinely is not present as text — while the address is one `base64 -d` away.

Measured on `var/live-eml/bienici-com-01.eml`: literal address present in the decoded body,
**false**; address recovered from `signedRecipient`, **true**.

Two halves to the fix, and they are different things:
- **Strip**: JWT-shaped tokens are replaced the way `qs=` values already are — the VALUE goes, the
  three-segment shape stays, because a fixture whose links have no token at all would not exercise
  the link handling.
- **Verify**: decode every long base64url run in the output and refuse if the address or its local
  part appears in any of them. That is the general rule (*the address must not be RECOVERABLE*),
  not a Bien'ici special case, so the next portal's encoding is covered too. Quoted-printable is
  decoded for the same check, for the same reason.

`tests/php/Repo/FixtureSecretsTest.php` already refuses a committed JWT, so the repo would have
caught the fixture. It would not have caught the scrubber reporting success.

## `params.from` becomes mandatory for an enabled `email_alert`

Recorded 2026-08-25 as due *"with the next portal"*. This is the next portal.

Without it an enabled email source reads every message in the label within the window, which on a
shared mailbox means it ingests other portals' alerts as its own — the source-scoping lesson that
took SeLoger from 9 listings to 0 and back to 74. Refused at LOAD, not at fetch, because a source
that can only fail once it is polling is a source that fails in production.

## Decisions Log

- [2026-08-25 21:50] AGREED: Bien'ici is onboarded as source #6, `type: email_alert`, `family:
  private`, `default_tenure: LIBRE`, `mixed_tenure: false` — the same §1 residual as SeLoger, and
  reversed by the same one line.
- [2026-08-25 21:50] AGREED: identity is the LISTING LINK, not content. `cardListing()` gains a
  link-identity path for the segmented case; `id_from: content` still short-circuits, so SeLoger is
  byte-identical. Chosen before the first enabled pass because identity is permanent.
- [2026-08-25 21:50] AGREED: `card_separator` is `\nPhoto\n`, chosen by measuring both candidates
  against four real messages rather than by symmetry with SeLoger.
- [2026-08-25 21:50] AGREED: `commune_pattern` anchors on the postcode BEFORE the name
  (`94600 Choisy-le-Roi`) — the inverse of SeLoger's, whose postcode sits parenthesised on the line
  below. The anchor is the portal's template, which is why the pattern is per-source config.
- [2026-08-25 21:50] AGREED: `tools/scrub-eml.php` verifies that the address is not RECOVERABLE,
  not merely not present — decoding base64url runs and quoted-printable before it looks.
- [2026-08-25 21:50] AGREED: `params.from` is refused at load for an ENABLED `email_alert` source.
- [2026-08-29 20:30] VERIFIED in code, since this file carried only AGREED lines and no record that
  either P0-shaped ruling landed: `tools/scrub-eml.php::recoverableForms()` decodes every base64url
  run and the quoted-printable form before it looks (pinned by `tests/test-scrub-eml.sh`), and
  `ConfigLoader` throws `ConfigError` at `<source>.params.from` for an enabled `email_alert` with no
  sender. **This plan is CLOSED**; the photo-less-card chimera stays a stated non-blocker.
- [2026-08-25 21:50] AGREED: the agency selection mail (*"L'agence … a sélectionné pour vous ces
  annonces"*) and the *"Cette annonce peut également vous intéresser"* suggestion card are both
  ingested as ordinary listings. Over-inclusion costs a rejected row; under-inclusion costs a flat.
