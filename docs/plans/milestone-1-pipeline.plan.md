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
| Tenure verdict persistence, `doctor` timing | **deferred to schema v3** — Q24, Q25. v2 is spent: it carries `listings.seen_epoch` |
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

### Round 10 — 30 findings against round 9's fixes, four of them P0

Every P0 this round was the same shape: **the round-9 repair was one step shallower than the
defect**. That is the pattern the certification ladder exists to interrupt, and it is why two
consecutive clean rounds are required rather than one.

- [2026-08-07 20:40] AGREED: `SCHEMA_VERSION` is 2 and `migrate()` carries a real upgrade path. It
  was 1 while the same commit added `listings.seen_epoch` — so a database written the day before
  opened clean, reported version 1, and threw a raw `no such column` at the first sighting. The
  mismatch refusal introduced to prevent exactly that could not fire, demonstrated against itself.
- [2026-08-07 20:40] AGREED: the empty-run baseline falls back to the last **productive** run, not
  the last successful one. A single successful-but-empty run between the history and the streak
  zeroed the baseline, so a source with a 25-listing history went silent for three runs and reported
  `OK` — the round-9 defect, reachable one neighbouring case over.
- [2026-08-07 20:40] AGREED: **the monotonic run log is reverted.** It did not fix the
  skewed-clock freeze; it deleted the evidence of it. Nothing checks a timestamp against a clock, so
  the FIRST bad run was still accepted, and the refusal then discarded the real runs that followed —
  three outright failures unrecorded, `health()` reporting `OK` with `lastFailureAt` null. Recency is
  now read from the log's own insertion order, and the rolling window is bounded at BOTH ends so a
  run dated 2036 cannot sit in it forever. A log that drops entries is not a log.
- [2026-08-07 20:40] AGREED: a **superseded** sighting is never a price drop. A delayed alert
  carrying an older, intermediate price made the store answer "dropped to 900" for a flat it
  correctly believed to be at 1000 — the row was hardened and the verdict object left exposed.
  `Sighting::$isCurrent` carries the fact explicitly.
- [2026-08-07 20:40] AGREED: "has the rent changed since what we believe now?" is answered by the
  stored current rent, NOT by `price_history`. The history is changes-only, so it is not a record of
  observations: a backfilled 900 became the chronological predecessor of the real 900 and swallowed
  a 100-euro drop entirely.
- [2026-08-07 20:40] AGREED: a stale sighting may FILL a missing URL or title, though never
  overwrite one. `first_seen_at` deliberately does not move backwards — it records when *we* first
  saw the listing, which is what a seen-set is for.
- [2026-08-07 20:40] AGREED: `STALE` is a status. `NEVER_RUN` covered "never" and nothing covered
  "stopped"; one successful run three hundred days ago reported `OK` forever. It needs the current
  time, so `health()` takes an optional `$nowIso` — the class still never reads the clock.
- [2026-08-07 20:40] AGREED: `NEVER_PRODUCED` gets a time floor. Three empty polls at a 15-minute
  interval was accusing a source of a bad field map 45 minutes after onboarding.
- [2026-08-07 20:40] AGREED: `rollBackQuietly()` — SQLite auto-rolls-back on `SQLITE_FULL`, so an
  unguarded `rollBack()` throws "no active transaction" and that replaces "database or disk is
  full". Its `inTransaction()` fast path was written and then REMOVED: the surrounding catch already
  covered every case it did, so it was dead safety code that read as protection.
- [2026-08-07 20:40] AGREED: `Redact` gains path-segment, space-separated and French shapes — the
  Telegram bot token and the ntfy topic both travel in a URL PATH, IMAP `LOGIN user pass` and POP3
  `PASS` carry no delimiter at all, `pass=` was missing from the name list, and the RFR income
  figure had no pattern despite hard rule 7 naming it beside the IMAP password. Ambiguous names
  (`key`, `auth`) now require `=`, and a value that is itself a URL is never masked — `auth:` before
  a URL was destroying the failing endpoint, which is how a masker gets deleted.
- [2026-08-07 20:40] AGREED: the database default moves out of `var/` to `state/`. `var/` is
  documented as container-lifetime scratch, so `rm -rf var/` is a reasonable thing to do — and the
  seen-set is the opposite of scratch. `BLAST-RADIUS.md` now names the path, and names `rm -rf var/`
  as safe so nobody widens it by analogy.
- [2026-08-07 20:40] AGREED: WAL and a 5-second busy timeout. `--watch` alongside a manual
  `scout doctor` is the spec's own target usage, and the default journal mode failed instantly with
  "database is locked" instead of waiting.

### Round 11 — 35 findings, four P0, and a process failure that was mine

**The process failure first, because it invalidates the round's score.** The tree was NOT frozen
while the panel ran: I was editing `Store.php` as the reviewers read it. One reviewer caught this,
discarded its first pass and re-ran everything in a pinned worktree. `CLAUDE.md` § "Certification
ladder" already says *"freeze first, because a round run on a moving tree cannot count toward the
two-clean requirement"* — I ran the panel and then kept working. Every subsequent round is frozen
at a commit before the panel is spawned.

- [2026-08-07 23:30] AGREED: **which run is "the last one" cannot be answered without a clock, and
  that is what `$nowIso` is for.** By TIMESTAMP, one forward-skewed row sorts last forever and hides
  every later run — permanently. By INSERTION, a run committed late but stamped earlier wins, so one
  success logged after three failures erased a `BROKEN` verdict — `--watch` alongside a manual
  `doctor` makes two writers routine, so this is designed behaviour, not an edge case. With a clock,
  a row stamped after `now` is provably wrong and is dropped, and the greatest remaining timestamp is
  genuinely the most recent. Without one we fall back to insertion order, because its failure lasts
  one run and the other's lasts forever. `CLAUDE.md` now records that the CLI MUST pass `$nowIso`.
- [2026-08-07 23:30] AGREED: the changes-only history and the price delta have **two different
  baselines**, and collapsing them has now caused a defect in each direction. The delta is measured
  against what we currently believe; the history against the chronological neighbour. Using the
  chronological one for both swallowed a real drop (round 10); using the stored one for both put a
  duplicate 900 into a history that cannot be cleaned up (round 11).
- [2026-08-07 23:30] AGREED: a database whose `schema_meta` exists but carries no version row is
  UPGRADED, not stamped. That is the state a crash between the DDL and the version INSERT leaves —
  two separate autocommit statements — and stamping it current meant the first sighting threw a raw
  `no such column`. Verbatim the failure `SCHEMA_VERSION` exists to prevent.
- [2026-08-07 23:30] AGREED: an undateable `last_seen_at` is treated as epoch 0 rather than refusing
  to open the database. One hand-edited or merged row otherwise bricks the store permanently, with
  no repair path, on the data set that cannot be rebuilt.
- [2026-08-07 23:30] AGREED: `''` is missing, not present. `COALESCE(:url, url)` only guards `null`,
  so a DOM attribute miss erased the link — and the stale-fill branch could not repair it because it
  treated `''` as present too.
- [2026-08-07 23:30] AGREED: `Redact` names carry affixes. `_` is a word character, so
  `\bpassword\b` cannot match inside `IMAP_PASSWORD` — **five of the six credentials in the
  `.env.example` this project committed defeated the masker**, while the class docblock claimed the
  IDFM key was covered. Also: single-quoted values matched nothing at all (`var_export()` emits
  exactly that shape), the bare-Telegram-token pattern was dead because the `bot` prefix exists only
  in the API path, unpadded SASL base64 had no pattern, and the verb pattern ate `PASS command
  issued in wrong state`, `Pass Navigo` and `pass 2 sur 3` because "contains a non-letter" counts an
  accent. It is now case-sensitive and requires a digit or a symbol.
- [2026-08-07 23:30] AGREED: `journal_mode` is read back rather than assumed — it is a QUERY pragma
  and SQLite does not raise when it refuses, so a network mount silently stays in rollback mode.
  `Store::journalMode()` exposes what actually took effect, and `scout doctor` prints it.
- [2026-08-07 23:30] AGREED: the WAL sidecars (`*-wal`, `*-shm`) are gitignored for `.sqlite` and
  `.sqlite3`, not only `.db`. They exist between a write and a clean close — i.e. after a crash or a
  reclaimed container, which is exactly when someone runs `git add .` to salvage work.
