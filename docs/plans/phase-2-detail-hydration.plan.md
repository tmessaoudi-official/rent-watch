# Phase 2 — persistent detail hydration

> **Status: PLANNED, not started.** Written 2026-08-23. Chosen by the developer over monitor-only,
> the unblocked small leftovers, and chasing the verdict flip.

## What this is for

An In'li card's ENTIRE text is `1 005 € cc 3 pièces · 55.32 m² Longjumeau`. Four facts, no title, no
floor, no lift, no description. In'li produces roughly two thirds of the current 83 matches, so
`exclude_title_patterns` is **structurally dead on the source that matters most** — nothing has ever
slipped through it because In'li lists only flats, which is luck rather than a filter. The phase-1
notification work (`Core/Department`, floor and lift on the reason line) can only display what the
adapter extracted, and on In'li that is nothing.

The fix is the second request per listing that `type: html` already supports — `detail_map`, built
for Cityloger — behind a gate cheap enough to run on a source with 174 listings.

## Why the ruled gate is not enough

`CLAUDE.md` rules the gate as **novelty**: hydrate a listing the first time it is ever seen. The
reasoning given is that this changes *when* a page is fetched, not *which* listings are judged on
what, so hard rule 8 is untouched. That is true of hard rule 8 and false of everything else, because
the ruling stops one step short of asking what pass 2 sees.

**Hydrate-on-first-sight-only makes a listing's verdict depend on which pass is looking at it.**

| | pass 1 (novel, hydrated) | pass 2+ (not novel, not hydrated) |
|---|---|---|
| title | from the detail page | absent |
| `exclude_title_patterns` | applies | **cannot fire** |
| tenure | detail-page evidence | `UNKNOWN` on a mixed source |

Two consequences, and the first is P0-shaped:

- **A listing the title filter REJECTED on pass 1 is a plain card on pass 2, passes criteria, and
  notifies.** The filter Phase 2 exists to resurrect would be bypassed by the mechanism meant to
  feed it, one pass later.
- A listing resolved `LLI` on pass 1 goes back to `UNKNOWN` on pass 2 and re-enters the *à vérifier*
  digest, permanently.

So hydration results **must persist**. That is the whole reason this is a Large task rather than the
S the remaining-work table implies: persistence means schema v5, and schema v5 means the store-test
category contract and the full sabotage ledger.

## Design

**`DetailCache`, a small interface** — `get(RawListing): ?CachedDetail` and `put(...)` — injected
into `HtmlSource` the way `$detailGate` is today, so the adapter stays ignorant of the Store. An
in-memory fake serves the tests; a `Store`-backed implementation sits behind schema v5.

Seven properties, each with the reason it is not the obvious alternative:

1. **Keyed on `(source, external_id)`, never on `dedup_key`.** Cache what was OBSERVED, not what was
   CONCLUDED. `dedupKey()` normalises, and normalisation evolves; a v5 row keyed on it silently
   orphans the entire cache the day that changes, which is the re-fetch-crawl failure with an extra
   step and no symptom. `url_fetched` is stored alongside, because a url can carry session params
   and the row should record which page actually answered.
2. **The RAW extracted strings are stored, not the mapped values.** Same reasoning `staleVerdicts()`
   already uses: a `ListingMapper` improvement must apply to cached rows rather than being frozen at
   capture time. Extraction is re-run through the mapper on every read.
3. **A failed fetch is cached as a FAILURE, never as "hydrated, nothing found".** The second is
   hard rule 3 wearing a new hat — a broken detail page would present as a listing that simply has
   no floor. Attempts and `last_attempt_at` are recorded, with backoff and a cap.
4. **Exhausting the retry cap is a HEALTH EVENT, not a log line.** Hard rule 2: an alert computed
   and never sent is worse than none. One flaky detail page is noise; 50 of 174 at cap means In'li
   changed its detail markup, which is precisely the broken-selector-forever scenario rule 2 exists
   for. It surfaces through the existing `SourceHealth` detail/alert path, above a threshold.
5. **The cache NEVER EXPIRES in v1.** The hydrated fields — floor, lift, title, description — are
   near-immutable for the life of an ad. The cost is real and stated: a landlord editing the
   description (a rent-drop note, a *loué* banner) is invisible for ever. **The one line that
   reverses this** is a `max_age_days` on the v5 row plus a staleness predicate in
   `DetailCache::get()`.
6. **A per-pass hydration BUDGET, which the ruled design does not mention and day one needs.**
   ~174 In'li listings are all novel on the first pass, and Q37 paces 60 s per host: unbudgeted,
   that is a ~3-hour first pass, a crawl under hard rule 5. Steady state is near zero extra
   requests — only the cold start bites — so the gate takes a cap `N` per source per pass and defers
   the remainder to later passes.
7. **The budget has an ORDER, and it is not insertion order.** Candidates are ordered by
   `Criteria::matchesCommune()` first, then FIFO. Ordering on card-complete fields only, so hard
   rule 8 is untouched — this decides *when* a page is fetched, not *whether* a listing is judged.
   Left implicit, the listings most likely to match would queue behind ones that never will.

### Cityloger's existing gate

Cityloger's `detail_map` is gated on `matchesCommune()` today. **Phase 2 converts it to the same
mechanism**, because novelty-with-persistence subsumes the commune gate's cost logic and two gate
semantics in one adapter is the two-implementations shape hard rule 9's funnel exists to prevent.
This widens the test surface to the Cityloger fixtures, which is part of the sizing.

Note the commune gate is nearly vacuous now anyway: it was cheap at ten communes (3 of 51 hydrated)
and the region is all of Île-de-France as of 2026-08-22.

## Steps

0. **The amend lands first.** `M config/sources.json` is uncommitted pending
   `! bash /tmp/amend-rw-20260823.sh`, and step 4 edits that same file. Rule 8 — do not write over
   an uncommitted change.
1. **Capture an In'li detail page and LOOK at it** before building anything. `robots.txt` first
   (only `/espace-membre/` is disallowed, re-verify), one page, frozen and scrubbed into
   `tests/fixtures/inli/detail.html`. **If it does not carry title, floor, lift and a description,
   this phase is worth less than it costs and should be re-scoped rather than built.**
2. `DetailCache` interface + in-memory fake + `HtmlSource` wiring. Tests against fixtures only.
3. Schema v5 + the `Store`-backed implementation. Store-test categories: persistence, identity,
   failure paths at minimum.
4. In'li `detail_map` in `config/sources.json`; convert Cityloger's gate.
5. Budget + ordering + health surfacing for cap exhaustion.
6. Full sabotage ledger (not a filtered mini-run — `Store/` is touched).

## Sabotage cases this must add

- a cached hydration that stops being READ → every pass re-fetches (the crawl)
- a cached hydration that stops being WRITTEN → same, from the other side
- **budget mutation**: the cap stops being enforced → the suite must notice the request count
  exceeding `N` against a fixture chain
- **cross-source poisoning**: the same `external_id` on two sources must not share a row. Asserted
  cross-source specifically, because `(source, external_id)` is exactly the compound key a lazy
  refactor drops half of
- `floor: 0` (RDC) surviving the round trip as 0 and not as absent
- `elevator: false` surviving as `false` and not as `null`
- empty-string versus absent-key out of `detailFields()`, pinned rather than assumed
- a failed fetch cached as a successful empty hydration

## Decisions Log

- [2026-08-23 02:4x] AGREED: Phase 2 is the next work, chosen over monitor-only, the small unblocked
  leftovers, and diagnosing the two-listing verdict flip.
- [2026-08-23 02:4x] AGREED: hydration results PERSIST — a novelty gate without persistence lets a
  listing the title filter rejected on pass 1 notify on pass 2, bypassing the filter this phase
  exists to resurrect.
- [2026-08-23 02:4x] AGREED: the cache stores RAW extracted strings and re-runs the mapper on read,
  so a mapper improvement reaches already-cached rows.
- [2026-08-23 02:4x] AGREED: the cache never expires in v1; reversed by a `max_age_days` on the v5
  row plus a staleness predicate in `DetailCache::get()`.
- [2026-08-23 02:4x] AGREED: hydration is budgeted per source per pass, ordered by
  `matchesCommune()` then FIFO, because 174 novel listings at 60 s per host is a ~3-hour first pass.
- [2026-08-23 02:4x] AGREED: Cityloger's commune gate converts to the same mechanism rather than
  living alongside it.
