# Milestone 1 — end-to-end pipeline Plan

Milestone 1 is the first run that goes all the way through: one source fetched, classified, filtered,
stored, and pushed. It is built bottom-up, because the layers that carry state are the ones whose
failures are silent, and they are cheapest to get right before anything depends on them.

## Status

| Piece | State |
|---|---|
| `Store` — seen-set, price history, run log, health derivation | **done**, `src/php/Store/Store.php` |
| `SourceHealth` / `SourceStatus` | **done**, `src/php/Core/` |
| `Redact` — masks credentials in adapter error text | **done**, `src/php/Core/Redact.php` |
| Cross-portal dedup (spec §7: price history per *logical* listing) | **not started** — the store keys per source; clustering the same flat across two portals is a separate problem with a separate failure profile |
| Tenure verdict persistence, `doctor` timing | **deferred to schema v2** — Q24, Q25 |
| Config loading (`config/criteria.yaml`, `config/sources.yaml`) | **blocked** — see the open decision below |
| `Source` adapter contract + first adapter | not started |
| `criteria` (score + hard disqualifiers) | not started |
| Notification formatter + one channel | not started |
| `scout` CLI (`doctor`, `dump <source>`, `run --once`) | not started |

## Open decision — the config file format

`spec/PROJECT_BRIEF.md` §9 and `CLAUDE.md`'s architecture table both specify `config/criteria.yaml`
and `config/sources.yaml`. **This container has no `ext-yaml`** and Composer cannot install anything
(the egress policy returns 403 on `codeload.github.com`, see `CLAUDE.md` § Gotchas), so a `.yaml`
file has nothing to parse it. The decision is recorded in `docs/OPEN-QUESTIONS.md`; it is a product
decision because it changes a file the developer edits by hand.

The reason it cannot be resolved by writing a small YAML parser without asking: `sources.yaml`
carries `mixed_tenure`, the flag that arms the §1 fail-closed rule. A subset-parser that
mis-reads one boolean disarms the one non-negotiable guarantee in the project, silently.

## Decisions Log

- [2026-08-07 14:20] AGREED: the store takes ISO-8601 timestamps as arguments rather than reading the
  clock. Health is a function of run history over time, and a store that calls `now()` internally can
  only be tested by waiting.
- [2026-08-07 14:20] AGREED: `rent_cc` is updated with `COALESCE(:rent, rent_cc)` — a source that
  stops publishing the rent does not erase the last known figure. Overwriting with null would make a
  later republication at a lower price read as a first sighting, silently swallowing the drop.
- [2026-08-07 14:20] AGREED: a FAILED run terminates the consecutive-empty streak rather than
  extending it. "The source did not answer" and "the source answered nothing" are different
  diagnoses, and the failure has its own louder rule.
- [2026-08-07 14:20] AGREED: the empty-run baseline is measured over the window ending BEFORE the
  streak began. Including the streak dilutes the very mean that is supposed to prove it abnormal.
- [2026-08-07 14:20] AGREED: `NEVER_RUN` is a distinct status and alerts. A source configured months
  ago that has never once fired is a configuration bug, invisible precisely because it never fails.
- [2026-08-07 14:20] AGREED: in the fallback dedup key, the URL host is folded and the path is not.
  RFC 3986 makes the path case-sensitive, and over-merging hides a flat completely while
  under-merging only costs a duplicate notification — so the tie breaks toward under-merging.
- [2026-08-07 14:20] AGREED: tracking parameters are NOT stripped from the fallback dedup URL. No
  source has been observed emitting one, and a guessed strip-list is a rule invented against a
  failure nobody has seen. Revisit when a real capture shows one.
- [2026-08-07 14:20] AGREED: timestamps are parsed strictly and refused when unreadable.
  `new DateTimeImmutable('')` silently means "now"; since the timestamp orders the price history and
  defines the health window, a reinterpreted one corrupts both with no visible symptom.
- [2026-08-07 14:20] AGREED: `tests/sabotage-check.sh` is extended beyond the classifier to the
  store. Same argument, same failure shape — none of the store's failure modes raise anything.

### Round 9 — the certification panel found 25 defects in the above, including two P0s

The two P0s were both hard rule 2's own failure shape, reintroduced by the module written to prevent
it: a dead source reporting `OK`. Neither was reachable by any test in the first cut, and both work
correctly in the shape the tests happened to use. That is the whole argument for the panel.

- [2026-08-07 17:05] AGREED: an **unknown** baseline is not a **zero** baseline. When the rolling
  window before an empty streak contains nothing — a gap longer than the window, from a holiday, a
  reclaimed container or a temporarily disabled source — fall back to the last successful run of any
  age. Sharing a branch with "normal is zero" made ten consecutive empty runs against a documented
  25-listing history report `OK`.
- [2026-08-07 17:05] AGREED: the run log is **monotonic per source** — `recordRun()` refuses a
  timestamp earlier than one already logged. One run stamped from a skewed clock sorted last forever
  and made every later run invisible to `health()`: twenty consecutive failures reported `OK`. Unlike
  a sighting, a run has no legitimate out-of-order case, so refusing is free.
- [2026-08-07 17:05] AGREED: a trailing `Z` is rewritten to `+00:00` before parsing. `\Z` in a
  `createFromFormat` pattern is decoration — the instant is built in the DEFAULT timezone — so on a
  `Europe/Paris` host, the natural deployment for an Île-de-France tool, `…T10:30:00Z` stored 08:30
  UTC and inverted the price history against a `+00:00` sibling.
- [2026-08-07 17:05] AGREED: `NEVER_PRODUCED` is a distinct status. A source that succeeds and never
  returns an item is the shape a wrong field map takes — HTTP 200, zero parsed items — and it hid
  behind `OK` because it never fails and its baseline really is zero.
- [2026-08-07 17:05] AGREED: `WARN_FLAKY` exists, with a chosen (not derived) threshold recorded as
  Q23. A source failing half its fetches misses half the market daily and was indistinguishable from
  a healthy one.
- [2026-08-07 17:05] AGREED: identity has a floor. `trim()` does not strip U+00A0, so an id of one
  no-break space passed the "does this source publish an id?" test and collapsed every listing in the
  run onto one key; and a listing with no id, no URL and no title is refused rather than hashed to a
  shared `sha256("\n")`. Both are over-merges, which hide a flat completely.
- [2026-08-07 17:05] AGREED: out-of-order sightings are expected (email alerts arrive out of
  publication order) so the rent comparison is against the chronologically PRECEDING recorded rent,
  never against whatever was written last, and a stale sighting does not overwrite current state.
- [2026-08-07 17:05] AGREED: `url` and `title` are COALESCEd like the rent. Markup drift is the
  premise of the whole health module, so a run with a missed link selector is the expected case — and
  it was erasing the two fields a notification needs to be actionable.
- [2026-08-07 17:05] AGREED: `Store::migrate()` refuses ANY version mismatch, not just a newer one.
  The older direction was the real gap: `CREATE TABLE IF NOT EXISTS` adds no columns and nothing
  re-stamped `schema_meta`, so schema v2 would have opened every existing database as v1 forever.
- [2026-08-07 17:05] AGREED: adapter error text is masked by `RentWatch\Core\Redact` at the store,
  because the store is the single funnel every adapter passes through. The text reaches
  `source_runs.error` AND a user-facing notification, so a cURL error carrying the IDFM key or an
  IMAP failure carrying the mailbox is hard rule 7's exact prohibition arriving through a channel
  nobody would grep. It fails CLOSED on a PCRE error — losing a diagnostic is recoverable, leaking a
  credential is not.
- [2026-08-07 17:05] AGREED: `record()` is transactional. The `listings` and `price_history` writes
  must agree; half of it leaves the one data set that cannot be reconstructed reading as complete.
- [2026-08-07 17:05] AGREED: `StoredListing` and `Store::snapshot()` exist. The notify layer needs the
  URL and title, and their preservation guarantee was silently violated for a whole round precisely
  because nothing could read the stored row back.
- [2026-08-07 17:05] AGREED: the store's test contract is written into `CLAUDE.md` § "Testing &
  verification" as six named categories. Five `SourceHealth` fields — including the three hard rule 2
  names by name — could be replaced with constants while the whole suite stayed green.
