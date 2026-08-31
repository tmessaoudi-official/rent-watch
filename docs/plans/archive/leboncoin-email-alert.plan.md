> SUPERSEDED (2026-08-31) by docs/plans/scout-unified-execution.plan.md. Kept for its
> Decisions Log and measurements; do not execute from this file.

# leboncoin — email-alert source #7 Plan

leboncoin fired its first alert ever on **2026-08-26 at 07:33 Paris**. Before that morning the
mailbox held one new-device notice from them and nothing else. `scout doctor --source=leboncoin`
returns **3 annonces, `ok`, 864 ms**; a seeded pass matches **1 of 3**.

## Decisions Log

- [2026-08-26 08:05] AGREED: **identity is the LINK**, and this is recorded because it is a ONE-WAY
  DOOR. `/vi/3256902167.htm` is a real ad id, and `stableId()` rebuilds from scheme+host+path so it
  drops both the query and the `#fragment` the tracking parameters live in. Nothing migrates a
  stored row between key schemes, so a source that changes identity later re-notifies its entire
  backlog — the choice had to be made before the first enabled pass, and was.
  **Rejected:** SeLoger's `id_from: content`, which exists because that portal sends sixteen cards
  behind one opaque redirect. A real id has neither of the content key's stated costs.
- [2026-08-26 08:10] AGREED: **the card separator is the CTA, `"Voir l'annonce"`, measured after the
  obvious form failed.** `"\nVoir l'annonce\n"` — Bien'ici's shape — matched NOTHING, because the
  CTA sits inside a run of spaces rather than alone on its line, and the whole message then parsed
  as ONE card carrying card 3's URL with card 1's rent, commune and surface. One plausible-looking
  listing, and nothing about it reads as a fault from outside. Every extracted value is now asserted
  against hand-read ground truth so it cannot drift back.
- [2026-08-26 08:15] AGREED: **`charges_included: false`.** The alert mentions charges NOWHERE —
  measured, zero occurrences of `charges`, `CC` or `HC` in the whole payload — so the Logirep
  precedent applies: the figure lands in `rentHc`, `max_rent_cc` never fires on it, and the score
  line says the ceiling is unverifiable. **Stated cost: the rent ceiling is not checkable for this
  source.** **To reverse:** a later capture that states the basis.
- [2026-08-26 08:20] AGREED: **a URL's QUERY and FRAGMENT are stripped from classified text; its
  PATH is kept** (`RawListing::text()`). Harvesting hrefs into the body made every tracking
  parameter part of the tenure scan, and a campaign string carrying `plus`, `lli` and `plai` fired
  two label signals and conflicted a correct verdict into the digest. The split is §1 and is
  measured both ways: `plai` as a path SEGMENT classifies PLAI/REJECT, the same acronym in
  `?c=plai_plus` classifies nothing at all. Blanking the whole URL would be simpler and would lose a
  social signal to save a campaign string — the dangerous direction. Corpus `url-001` and `url-002`.
- [2026-08-26 08:40] AGREED: **`title_pattern` off the portal's own type line.** Without it every
  listing's title was the EMAIL SUBJECT — `3 nouveaux biens à louer à Ile-de-France` for all three
  cards — which makes `exclude_title_patterns` structurally dead, the In'li lesson verbatim, on the
  portal with the largest coliving market in France. It anchors on `Appartement · 3 pièces · 48 m²`
  rather than on a list of dwelling types, so a `Chambre · 1 pièce · 12 m²` matches it too and the
  anchored `^\s*chambre\b` exclusion can fire.
- [2026-08-26 08:25] AGREED: **the URL-ordering sabotage case is RETIRED, not left green.** The rule
  is real — the URL is emitted after its anchor text so reading order matches the rendered one — but
  this payload CANNOT distinguish the two orders, because each card links twice (image anchor and
  CTA anchor) and the last qualifying link in a segment is the same either way. A case whose
  guarantee no test can separate reports coverage it does not have. The ordering therefore stands as
  a **reasoned default, not a measured one**; a portal whose card links only once would distinguish
  them, and that payload is the case to write when it arrives.
- [2026-08-26 08:30] NOTED: **n=1.** One message, three cards, the first this subscription ever
  produced. The separator, `commune_pattern` and `title_pattern` are all measured on that single
  capture, and this repo has twice paid for generalising from one (the In'li lift claim, the
  project-wide "0 matches" claim). **The second alert to arrive is the first regression test.**

## What it cost outside the source

- **`EmailMessage::harvestHrefs()`** — leboncoin sends no `text/plain` alternative, the first portal
  to do so, so every URL lived in an `href` that `strip_tags()` removed. The parser produced a
  perfect 15 975-character body carrying all three listings and ZERO links: a source that yields no
  listings and reports a quiet market for ever, while `doctor` says `ok`. The URL goes into the BODY
  rather than only the side array because `cardListing()` associates a link with a card by scanning
  that segment's text. Only the HTML path is touched — Bien'ici's identity IS its links, so a
  changed link set would re-key the stored backlog and re-notify every flat already seen.
- **`tools/scrub-eml.php`** — it reported `0 tokens replaced` and wrote `Bonjour tmessaoudi`, the
  account's saved-search UUID and three analytics hexes into a committable file. Every address check
  passed, correctly: a username is not an address. Fixed in its own commit (`4aa93a9`).

## Verification

- `MAILBOX_DIR=tests/fixtures/leboncoin scout doctor --source=leboncoin` — offline, no network.
- Live, before the flag flipped: 3 annonces, `ok`, 864 ms; seeded pass 1 match of 3, the other two
  rejected at 48 m² and 45 m² against the 50 m² floor.
