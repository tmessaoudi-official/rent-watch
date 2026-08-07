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
| Config loading (`config/criteria.json`, `config/sources.json`) | **done** — `src/php/Config/`, ruled JSON 2026-08-07 (Q22) |
| `ConfigTest::testEveryCorpusSourceAgreesWithConfig()` — the `mixed_tenure` drift guard | **done**, `tests/php/Config/ConfigTest.php` |
| `Source` adapter contract (`src/php/Adapters/Source.php`) | **done** — `fetch()` throws, never returns `[]` on failure |
| `Payload` + `ListingMapper` — dotted paths, number/boolean parsing, field flattening | **done** |
| `FixtureSource` — offline, shares the mapper with every network adapter | **done**, and it is what `scout replay` will use |
| `criteria` (score + hard disqualifiers) | **done**, `src/php/Core/CriteriaEngine.php` + `Verdict.php` |
| `HttpJsonSource` — the first NETWORK adapter | **blocked on an input**, not a decision: no endpoint in this repo has been verified, and hard rule 1 forbids writing one from memory. Needs a DevTools cURL capture |
| Notification formatter + one channel | not started |
| `scout` CLI (`doctor`, `dump <source>`, `run --once`) | not started |

## Settled — the config file format

**JSON**, ruled 2026-08-07 (Q22). `config/criteria.json` + `config/sources.json`, parsed by
`ext-json`. This container has no `ext-yaml` and Composer cannot install a parser (the egress policy
returns 403 on `codeload.github.com`), so `.yaml` files would have sat unread.

The option that was rejected is worth keeping written down: a hand-rolled YAML-subset parser would
have matched the spec exactly and read best of the three, and it was refused because `sources.*`
carries `mixed_tenure` — the flag that arms §1's fail-closed rule. A subset parser that mis-reads one
boolean disarms the project's one non-negotiable guarantee, silently, and that is not a thing to
trust to code written in an afternoon. `ext-json` is the language's own parser, so there is nothing
between the file and that boolean.

What the ruling cost, and how each cost is paid:

- **JSON has no comments.** A free-standing note uses `_comment` / `_why` / `_source` /
  `_verified_at`; a note about one key uses `_<key>`, and the loader accepts it **only while `<key>`
  is present**. Every other unrecognised key is a hard error, so the convention cannot swallow a
  typo — and renaming `mixed_tenure` to `_mixed_tenure` produces two loud errors rather than silence.
- **Every `config/*.yaml` reference in the tree had to move**, including four inside `.claude/`
  reviewer charters and twelve inside the tenure-guard's own test.
- **The tripwire had to learn JSON.** It fired on the `_comment` note every mixed-tenure source is
  now required to carry, and it stayed *silent* on `"tenure_classifier": false` — a JSON key carries
  a closing quote between the name and the colon, which pattern 5 did not allow for. Both fixed with
  matching test cases.

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

### Round 12 — 27 findings, four P0, and two of my own round-11 repairs reverted

The tree WAS frozen this time. Two reviewers still contaminated their own first runs by probing the
live working tree, caught it themselves, and re-ran in pinned worktrees — the reviewer charters
should require that, and it is the one action left over from this round.

- [2026-08-08 04:10] AGREED: **recency is the log's own insertion order, full stop.** The clock-aware
  filter added last round was worse than what it replaced: it only disqualifies rows stamped after
  `now`, so a row skewed by an hour is fully credible once that hour has passed — and when the CLOCK
  is the thing that is wrong (a CLI building `$nowIso` with `gmdate()` while `recordRun()` uses
  `date('c')` on a Paris host is a two-hour skew and an ordinary bug), it DISCARDED three real
  failures and reported `OK` with `totalRuns` counting only the survivors. The three candidates fail
  for different lengths of time — insertion order for one run, timestamp+clock for the duration of
  the skew, timestamp alone forever — and bounded-by-one-run wins. The clock now touches exactly one
  verdict: `STALE`.
- [2026-08-08 04:10] AGREED: the one-run window where a late-committed run silences an alert is an
  ACCEPTED, TESTED cost, not a defect to fix. `testALateCommittedRunDoesNotEraseABrokenVerdict` pins
  the bound rather than the absence.
- [2026-08-08 04:10] AGREED: the `Redact` verb rule is STRUCTURAL — a credential trace ends after its
  arguments, prose keeps going. Both character-based attempts failed in opposite directions: a
  negative lookahead ate `PASS command issued in wrong state`, and "must contain a digit or a symbol"
  LEAKED every all-letter password. A Google app password is sixteen lowercase letters and a
  dedicated Gmail mailbox is what `.env.example` provisions, so that leak was on the primary
  ingestion path. Every fixture used `alertes-immo@example.net`, which carries an `@` — the fixtures
  made one mailbox shape look universal.
- [2026-08-08 04:10] AGREED: the name affix matches a SEPARATED component, never a substring. The
  first version turned every secret name into a substring match and ate this project's own
  vocabulary — `passage:` (PRIM), `passed:`, `tokens:`, `bypass:`, `signatures:`, `signal=`,
  `keyword=`, `design=` — all of which survived before the affix existed.
- [2026-08-08 04:10] AGREED: no `u`-flagged patterns in `Redact`, deliberately. A `u` pattern returns
  null on Latin-1 bytes and the fail-closed guard then masks the WHOLE message — and a Windows-1252
  French page is the likeliest encoding accident in this domain. The guard's own comment had the
  trigger backwards, and the `u` flag had been added on its strength.
- [2026-08-08 04:10] AGREED: `record()` uses `BEGIN IMMEDIATE`, not PDO's deferred
  `beginTransaction()`. **`busy_timeout` did nothing before this**: SQLite deliberately skips the
  busy handler when a deferred transaction upgrades to a write lock, because retrying there can only
  deadlock. The constant claimed a behaviour that did not happen until the test written to
  demonstrate it went red — which is the anti-bandaid gate working exactly as intended.
- [2026-08-08 04:10] AGREED: `$span` is `max − min` over the whole log. `last − first` over rows in
  insertion order went negative on a forward-skewed FIRST run — the run most likely to be skewed,
  since it is the one after a boot — and a negative span disabled the wrong-field-map detector for
  the life of the database.
- [2026-08-08 04:10] AGREED: fractional seconds are padded AND truncated to six digits. `{1,6}`
  refused widths 7–9, which is .NET's `o` format and Go's RFC3339Nano at full precision — the
  producer the code comment itself named.
- [2026-08-08 04:10] AGREED: `tests/sabotage-check.sh` repeats every failing LABEL in its summary,
  and warns when the tree is dirty. Two reviewers independently saw it report undetected sabotages
  once and neither could say which, because both had piped it through `tail`. A gate whose verdict
  cannot be reconstructed from its own last ten lines is a gate nobody can act on.

### Round 13 — 23 findings, two P0, both of them round-12 repairs overshooting

The tree was frozen and stayed frozen. Two reviewers still contaminated their own first runs, this
time by following the worktree recipe I had written one commit earlier — which was wrong.

- [2026-08-08 09:20] AGREED: a name component boundary is a DELIMITER **or a case transition**.
  Requiring `_`/`-`/`.` alone missed camelCase entirely — `clientSecret`, `accessToken`, `botToken`,
  `refreshToken`, `userPassword` — which is the dominant JSON key convention and the literal spelling
  of every OAuth field. An OAuth 401 body reached `source_runs.error` and the notification in
  cleartext. `apiKey` survived only by accident, because `apikey` is a whole entry in the name list.
  The camel assertions need `(?-i:…)` or the case-insensitive flag collapses them to nothing.
- [2026-08-08 09:20] AGREED: `LOGIN` keys off the IMAP **tag**, `PASS` off a stoplist, and neither
  off the end of the line. Anchoring at EOL leaked whenever the adapter WRAPPED the library error
  with its own context — which is the natural way to satisfy hard rule 3 — so `… > A01 LOGIN user
  pass (tentative 1/3)` masked the mailbox and left the password beside it. Note the stoplist's
  DIRECTION: an unlisted word is masked, so a missing entry costs a masked diagnostic word and never
  a leaked credential. The earlier lookahead had it the other way round.
- [2026-08-08 09:20] AGREED: base64 credential blobs need three shapes, because the obvious single
  rule excluded the commonest secret here. `base64_encode()` of a 16-character Google app password is
  22–24 characters and may contain no digit at all, so a rule demanding 24 AND a digit missed exactly
  the secret the plaintext rule leaks — using the very discriminator that leak was caused by.
- [2026-08-08 09:20] AGREED: the Latin-1 `&nbsp;` byte is stripped by the byte fallback. `\xA0` is
  not valid UTF-8, so the `/u` trim returns null, and a plain `trim()` left it standing — an id of
  one such byte looked non-empty and collapsed every listing in the run onto `:id:%A0`. The exact
  over-merge that method's own docblock describes itself as preventing.
- [2026-08-08 09:20] AGREED: **the counting window is bounded on the OLD side only.** I asserted in
  two comments that a late-committed run "self-corrects on the next run" and never tested it. Under
  repetition it does not: two writers, one stamping from a lagging `Date:` header, gave eleven
  consecutive real failures reported `OK` indefinitely, because the upper bound read
  `failedRunsInWindow` as 0 of 11. A failure is a failure whatever its clock says. The upper bound
  stays on the MEAN, where a future-stamped row would otherwise inflate every later verdict.
- [2026-08-08 09:20] AGREED: the worktree recipe uses `cp -a`, never `ln -s`. Composer's PSR-4 map
  resolves relative to its own location, so a symlinked `vendor/` points at the PRISTINE `src/` and
  every sabotage silently reports as undetected — one reviewer got `0 detected, 144 undetected` and
  nearly reported it as a finding. `sabotage-check.sh`'s own comment claimed `vendor/` was symlinked
  when the code copies it, which is what invited the mistake.
- [2026-08-08 09:20] AGREED: the worktree rule lives in `CLAUDE.md` § "Certification ladder", not
  only in the agent charters. The charters are exactly the surface that is absent in the
  self-graded fallback the ladder itself names.

### Round 14 — 25 findings, and two of them were false claims in my own commit message

The correction first, because it is the worst of the four P0s. `202c744`'s message said *"the
`exec('ROLLBACK')` justification is corrected"* and *"the store test contract gains the three
categories it was missing"*. Neither was true: the comment was byte-identical across both commits
(the edit lived in a script that aborted before writing), and two categories were added, not three.
A changelog that overstates is worse than one that omits, because the next session stops checking.

- [2026-08-08 14:05] AGREED: **all three credential verbs key off ONE stoplist**, and none off a
  whitelist. An IMAP-tag whitelist for `LOGIN` failed OPEN — `[A-Za-z]{0,4}[0-9]{1,5}` misses a
  six-digit tag, a longer prefix, a `.`/`*` tag and an untagged trace, every one of which put a
  cleartext password into a push notification. `PASS` failed CLOSED on the same commit, so one class
  held two rules failing in opposite directions, which its own docblock forbids.
- [2026-08-08 14:05] AGREED: the stoplist's boundary is `(?![A-Za-z0-9_])`, not `\b`. Without a `u`
  flag, `\b` cannot assert after a multi-byte character, so `refusé` was a dead entry — and being
  the only accented one, nothing else exposed it.
- [2026-08-08 14:05] AGREED: **the counting window's upper edge is `now`, and only a clock knows it.**
  Three attempts: bounded by the last-inserted stamp hid eleven real failures behind a lagging
  writer; unbounded above let twenty future-stamped successes dilute a flaky source back to `OK` AND
  let ten future-stamped failures alert permanently through ninety healthy days, with a detail line
  claiming ten failures "en 7 jours" when the window held none. Bounded by `$now` is correct in both
  directions, because a row stamped after the current time has not happened yet.
- [2026-08-08 14:05] AGREED: the whole-line base64 rule needs a multiple of four AND both letter
  cases. Without them it ate `AUTHENTICATIONFAILED`, `tests/fixtures/tenure`, a bare SHA-256 line and
  `conventionnement` — §1 classifier vocabulary — while a `[:>] ` prefix allowance is what covers the
  `CLIENT -> SERVER: <blob>` shape an unpadded secret actually arrives in.
- [2026-08-08 14:05] AGREED: the name affix is BOUNDED at 40 characters. An unbounded greedy star is
  quadratic in the length of an unbroken `[A-Za-z0-9_.-]` run (448 KB took 18 s), and anchoring it to
  a token start made it worse, not better. No realistic payload produces such a run, but
  `Redact::text()` sits on the synchronous poll path.
- [2026-08-08 14:05] AGREED: the four `=== false` guards after `query()` are REMOVED. Under
  `ERRMODE_EXCEPTION` they are unreachable, and each fabricated a benign default for a condition that
  cannot occur. The `$row === false` checks after `fetch()` stay — an empty result set is ordinary.
- [2026-08-08 14:05] AGREED: `seen-set` is a named test category. "A listing is new exactly once" and
  "notified is not seen" are the store's two most basic guarantees and had no category for three
  rounds, while the contract said a behaviour without one is a behaviour nobody decided to guarantee.
- [2026-08-08 14:05] AGREED (carried from `2f119d7`, missing from this log until now): the ntfy
  `topic` sits in the strict name list rather than the ambiguous one, so `{"topic":"…"}` from an HTTP
  client is masked and not only `NTFY_TOPIC=`.
- [2026-08-07 00:00] AGREED: **every open question in `docs/OPEN-QUESTIONS.md` is closed by applying
  its own documented default** — *"let's answer all the questions then continue non stop till you
  finish everything"*. 21 resolved in one pass, each entry recording what was applied and the one
  line that reverses it. The four items that remain blocked are **inputs, not decisions**: the
  DevTools cURL captures, IMAP credentials, one real portal alert email, and the `plafonds` figures.
- [2026-08-07 00:00] AGREED (Q22, was the one BLOCKING question): **config is JSON.**
  `config/criteria.json` + `config/sources.json`, `ext-json`, `sources` keyed by name so a duplicate
  is unwritable. `_`-prefixed keys are ignored; any other unknown key is a hard validation error.
  `spec/PROJECT_BRIEF.md` §9 and `CLAUDE.md`'s architecture table amended in the same change, and
  every `.claude/**` reference renamed with it (the `yq` recipes in `/cross-check` became `jq`).
- [2026-08-07 00:00] AGREED (F3): postcode prefix **`92` removed** — every commune in F2 is 78 or 95,
  so it could never admit anything the commune filter also admitted.
- [2026-08-07 00:00] AGREED (Q5): `max_floor` and `require_elevator` **do not exist as config keys**.
  Floor and lift are score components only, so the prototype's silent drop cannot come back via config.
- [2026-08-07 00:00] AGREED (S6): the high-floor penalty needs the lift **explicitly absent**, not
  merely unmentioned. `null` is not `false` (hard rule 9), and that is why S6 is its own component
  rather than the negation of S5.
- [2026-08-07 00:00] AGREED (F9): tenure terms are **not** added to `exclude_patterns`. A
  user-editable regex duplicating §1 would be a second, weaker copy of the one guarantee that must
  not be config-overridable.
- [2026-08-07 19:40] AGREED (adapter contract): `Source::fetch()` **throws and never returns `[]`**
  on failure — hard rule 3 given something to be satisfied BY. `SourceError` masks its message at
  CONSTRUCTION rather than at display, because `Store::recordRun()` persists that text and
  `Store::health()` interpolates it into a user-facing detail; masking late means masking twice and
  forgetting one. The original is deliberately not kept as a property — anything reachable gets
  logged eventually.
- [2026-08-07 19:40] AGREED: an item with **no stable id fails the run**; it is neither skipped nor
  given a synthetic id. A content hash or an array index changes whenever the ad is edited or the
  result order shifts, so the listing is "new" on every run and notifies forever — silently breaking
  the store's "new exactly once" guarantee.
- [2026-08-07 19:40] AGREED (`item_count`, per Q30): the adapter returns everything it PARSED, before
  criteria. Counting matches would make source health a measure of the rental market rather than of
  the adapter.
- [2026-08-07 19:40] FOUND BY A TEST, not by reading — **a money bug**. `Payload::number()` first
  used "the rightmost separator is the decimal point", which reads the ordinary French `1.450 €` as
  1.450 and yields **1**: a 1450 € flat recorded as 1 €, passing every ceiling with maximum headroom.
  The rule is now "the last separator is a decimal point unless exactly three digits follow it".
- [2026-08-07 19:40] AGREED: `config/` **joins the sabotage harness's copy list**. The config tests
  read the committed files through a repo-root constant, so without it every one of them ERRORS in
  the scratch copy — and an errored suite is a red suite, which the harness cannot tell apart from a
  caught sabotage. Every sabotage would have reported `ok` while proving nothing.
- [2026-08-07 19:40] FOUND BY SABOTAGE (3 holes in the new tests, all real):
  (1) the `mixed_tenure` explicit guard was **decorative** — deleting it falls through to
  `requireBool()`, whose generic "required key is missing" also matched the assertion, so the test
  passed while the guard was gone. The test now asserts the guidance text only the guard produces.
  (2) the **title-only exclusion scope was unfalsifiable** while every title pattern was `^`-anchored:
  a `^` with no `m` flag cannot match inside an appended description either way, so widening the
  scope was a semantic no-op. Two unanchored patterns were added — phrases unambiguous in a title and
  ordinary in a description — which gives the scope something real to protect, plus a fixture pair
  that differs only in which field carries the phrase.
  (3) the accented-pattern sabotage never applied at all, so it had been reporting nothing for its
  whole life. Fixed to disable the check rather than reword its message.
