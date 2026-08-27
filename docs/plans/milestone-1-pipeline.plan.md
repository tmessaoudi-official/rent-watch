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
| Cross-portal dedup (spec §7: price history per *logical* listing) | **done** — schema v4's `group_key`; `Store::assignGroup()` + `Store::groupPriceHistory()`. A singleton reports its OWN history rather than the empty set SQL gives you for `group_key = NULL` |
| Tenure verdict persistence, `doctor` timing | **done** in schema v3 — Q24, Q25 |
| Config loading (`config/criteria.json`, `config/sources.json`) | **done** — `src/php/Config/`, ruled JSON 2026-08-07 (Q22) |
| `ConfigTest::testEveryCorpusSourceAgreesWithConfig()` — the `mixed_tenure` drift guard | **done**, `tests/php/Config/ConfigTest.php` |
| `Source` adapter contract (`src/php/Adapters/Source.php`) | **done** — `fetch()` throws, never returns `[]` on failure |
| `Payload` + `ListingMapper` — dotted paths, number/boolean parsing, field flattening | **done** |
| `FixtureSource` — offline, shares the mapper with every network adapter | **done**, and it is what `scout replay` will use |
| `criteria` (score + hard disqualifiers) | **done**, `src/php/Core/CriteriaEngine.php` + `Verdict.php` |
| `HttpJsonSource` — the first NETWORK adapter | **done**, and no cURL capture was ever needed: every live source turned out to be server-rendered. It now also carries `embedded_json_selector` for A12 Logirep, whose payload is JSON inside a `<script>` tag |
| `HtmlSource` + `Html/Selector` — the adapter three of the four live sources use | **done**, on PHP 8.5's own `Dom\HTMLDocument`. ~300 lines, not the ~1 000 estimated: no selector engine had to be written |
| `dedup` — cross-portal clustering | **done**, `src/php/Core/Dedup.php` |
| Notification formatter + channels (console, ntfy, email) | **done**, `src/php/Core/Notify/` |
| `scout` CLI (`doctor`, `dump`, `run --once/--seed`, `digest`, `reclassify`, `test-notify`) | **done**, `bin/scout` + `src/php/Cli/` |
| Schema v3 — tenure verdict, `duration_ms`, `source_alerts` | **done** |
| The run loop (fetch → classify → criteria → dedup → store → notify) | **done**, `src/php/Cli/Pipeline.php` |
| `scout run --watch` | **done** — `Core/Pacer` holds the Q37 cadence, `Adapters/PacedSource` applies it, `Cli/WatchLoop` survives a pass that throws |
| Q27 liveness — heartbeat + `state/last-refusal.txt` | **done**, `src/php/Core/Heartbeat.php`. The beat counts the sources the run WATCHES, not the ones the config enables |
| Live sources | **four**: In'li, CDC Habitat, Cityloger, Logirep. `fixture_demo` is the fifth `enabled` entry and is not a landlord |

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

## Remaining work — estimate (2026-08-22)

> **Supersedes the 2026-08-19 estimate below**, which is kept unedited for the record. It was wrong
> in the row that mattered — it called the product `~0% usable` because there were `zero live
> sources`, and named the `html` adapter *"the largest unblocked item, ~1 000 lines"*. Four sources
> are live and that adapter cost ~300 lines, because PHP 8.5 ships `Dom\HTMLDocument` and
> `querySelectorAll` and no selector engine had to be written. The `~0%` verdict happens to still
> hold — for a completely different reason, which is the point of re-measuring rather than editing
> a number.

**Every figure below was produced by running the thing on 2026-08-22, not recalled.** 14 075 lines
under `src/php/`, 14 428 under `tests/`, 71 classes, 31 PHPUnit suites. The suite's own closing line
is the only authoritative tally and it reads `OK (1589 tests, 6657 assertions)`. 325 sabotage cases;
the last full ledger was **325 detected, 0 undetected** against `fd8f3b5`. Four live landlord
sources (In'li, CDC Habitat, Cityloger, Logirep — `fixture_demo` is the fifth `enabled` entry in
`config/sources.json` and is not a landlord).

| Axis | Complete | Why it reads that way |
|---|---|---|
| **Code written** (excl. `src/phorj/`) | **~90%** | Everything but `Enrich/` and the tier-4 `plafonds` data exists and is tested. The 2026-08-19 gaps — the `html` adapter, the network adapters, cross-portal price history — are all closed |
| **Code written** (incl. `src/phorj/`) | **~70%** | The port still re-writes ~2 500 lines of pure core plus a differential harness, and is **on indefinite hold** (2026-08-19), so it does not belong on the critical path |
| **Product delivering value to the developer** | **still ~0%** | And no longer for the 2026-08-19 reason. `notify.channels` is `["console"]` and there is no `.env`, so nothing reaches a phone; and today's live yield across all four sources is **0 matches**, because their current stock does not intersect the ten communes in `criteria.json` |

**The bottleneck moved, and that is the single most useful thing on this page.** On 2026-08-19 it
was *"no verified endpoint"*. That is solved: four sources are live, and Track 1 of
`docs/SOURCES.md` is measured out — every remaining catalogue row is either dead, authenticated or
publishes nothing in Île-de-France. The bottleneck is now **delivery**: a pipeline that classifies
and scores correctly, with nowhere to send the result and nothing currently matching. Adding a
fifth institutional source of the same kind does not move it.

> **RE-MEASURED 2026-08-23 — the third axis row above and two of the items below are now false, and
> the way they went false is the point of re-measuring rather than editing.** Row 3 read
> *"Product delivering value to the developer — still ~0%"* and gave TWO reasons: nothing reaches a
> phone, and the live yield is 0. Both stopped being true within about twenty-four hours of being
> written, and neither was fixed by the work the table pointed at.
>
> - **Delivery is live and PROVEN in production.** `config/criteria.local.json` (gitignored,
>   mounted read-only into the container) sets `channels: ["console", "ntfy", "email"]`, and the
>   deployed watcher's own startup heartbeat and *à vérifier* digest were retrieved back off the
>   ntfy topic at `2026-08-22T20:15:08Z` and `20:16:17Z` [Verified 2026-08-23: `curl -s
>   "$NTFY_SERVER/$NTFY_TOPIC/json?poll=1&since=24h"`]. That is the whole chain — container →
>   channel → device — and it is the first evidence of it that is not a `test-notify` invocation.
> - **The yield is 83, not 0** [Verified 2026-08-23: the running container reports `478 annonce(s)
>   analysées · 83 correspondance(s), 11 à vérifier, 380 écartée(s), 4 doublon(s)` every pass].
>   That came from the Q1/Q2/Q3 criteria widening, not from a new source. The row's own remedy —
>   *"either widen the commune list or land the email route"* — was taken, and the table was never
>   updated to say so.
>
> **So the bottleneck moved a second time, and it is no longer an engineering item at all.** The
> pipeline classifies, scores, dedups, paces itself and pushes to a phone; 478 listings are seeded
> as seen, so what is now missing is a genuinely NEW listing to arrive. Nothing on this page makes
> that happen faster. What remains is quality of the notification (the phase-2 hydration item) and
> data the project does not yet hold (`plafonds`, an alert mailbox, an IDFM key).
>
> One measured oddity, not a defect: consecutive passes over an unchanged payload alternate between
> `11 à vérifier / 380 écartées` and `9 / 382` on a constant total, i.e. exactly two listings flip
> verdict. It fails closed both ways — neither state is a match — so it is logged here rather than
> chased. Diagnose with `scout run --once -v --source=cityloger` on a throwaway `SCOUT_DB` if
> it outlives the current stock.

### Remaining items

| Item | Effort | Blocked on | Milestone |
|---|---|---|---|
| ~~**A push channel actually configured** (ntfy or SMTP)~~ **DONE 2026-08-22** | was **S** | — ntfy is configured in the gitignored `criteria.local.json` and the deployed container's own heartbeat was read back off the topic on 2026-08-23. It was indeed the highest-leverage item on the page | 6 |
| First real `email_alert` portal | **M** — the adapter exists; a parser has to be shaped to a real message | IMAP credentials + one real alert email | 6 |
| ~~A source whose stock overlaps the criteria~~ **DONE 2026-08-22** | was **?** | — resolved by the first of the two remedies it named: Q1/Q2/Q3 widened the criteria to all of Île-de-France at `min_rooms: 3` / `≥50 m²` / `≤1200 € CC`, and the live yield went 0 → **83 of 478** | 5/7 |
| `PlafondBands` tier-4 figures | **S** — ~150 lines of data + tests. A11 Toit et Joie's `/Plafonds-de-ressources` carries the PLAI/PLUS/PLS half for IdF but states **no year**, so it is a pointer, not a figure | the `plafonds` tables per zone/household | 2 |
| `Enrich/transit` + `Enrich/geo` | **M** — not started | IDFM/PRIM API key | 8 |
| ~~Retire `icf_novedis` + `seqens` from `config/sources.json`~~ **DONE 2026-08-23** | was **S** | — both blocks removed; `docs/SOURCES.md` keeps the A2/A5 measurements and the corpus keeps its own labels. `ConfigTest::testTheMeasuredDeadEndsAreNotShippedAsPlaceholders()` stops either name returning as a `REMPLACER` placeholder, and Q20 in `docs/OPEN-QUESTIONS.md` records that this ended as its own option 3 for a reason option 3 never gave | — |
| Real corpus texts replacing the 114 synthetic | **M** — 6 captured so far; append, never renumber | more live sources | 2 |
| Classifier performance | **S** | ~~nothing~~ **DONE 2026-08-23** — letterless fast path, ~36 → ~25 ms/listing on the real Logirep payload. The `~155 ms` this row used to quote did not reproduce; see the Decisions Log | — |
| `src/phorj/` port of the pure core | **L** | three phorj builds; **on indefinite hold** | — |
| Final MAXIMAL certification round | **M** — 3 lenses, two consecutive clean rounds, frozen commit | the above landing | — |

### Wall-clock, in sessions of the size worked on 2026-08-19

- **Unblocked right now: ~1 session** — retiring the two dead source rows, the classifier-perf work,
  and the corpus appends that do not need new captures.
- **The one move that changes the product: ~30 minutes**, once a push credential exists. Nothing
  else on this list converts the third row above from `~0%` to anything.
- **Blocked on inputs: ~2–3 sessions**, and they still unblock cheaply.
- **phorj: on hold**, deliberately excluded from the total.

**Total ≈ 4–5 sessions of engineering** — down from 8–9 — but the calendar is still set by the
inputs, and the inputs are now *a notification credential* and *an alert mailbox*, not endpoint
captures.

## Remaining work — estimate (2026-08-19, SUPERSEDED — see above)

Written down because "how much is left" is the question a compact destroys first, and because the
answer here is not one number: the code is nearly finished and the product delivers nothing, and
both of those are true at once.

**Built so far:** 11 722 lines under `src/php/`, 9 548 under `tests/`, 66 classes, 19 PHPUnit
suites, 1 327 tests / 4 732 assertions, 278 sabotage cases. Spec milestones 1 (core skeleton),
2 (classifier) and 4 (health + `doctor`) are complete; the network adapters, the notification
channels, Q37 pacing and CI were all built ahead of their milestone.

### Three different percentages, because they disagree

| Axis | Complete | Why it reads that way |
|---|---|---|
| **Code written** (excl. `src/phorj/`) | **~80%** | Everything but the `html` adapter, `Enrich/`, and cross-portal price history exists and is tested |
| **Code written** (incl. `src/phorj/`) | **~65%** | The phorj port re-writes ~2 500 lines of pure core plus a differential harness |
| **Product usable by the developer** | **~0%** | Zero live sources. Every `config/sources.json` entry is `enabled: false` with a `REMPLACER` URL (15 occurrences). It notifies about nothing real |

That last row is the one that matters, and it is **one input away**, not one milestone away.

### Remaining items

| Item | Effort | Blocked on | Milestone |
|---|---|---|---|
| `html` adapter + CSS-selector parser | **L** — ~1 000 lines + tests, and no Composer means writing the selector engine | nothing — **the largest unblocked item** | 3/5 |
| First real `json` source (In'li) | **M** — mostly config, but the first of a type shakes out the adapter | DevTools cURL capture (hard rule 1) | 3 |
| First real `email_alert` portal | **M** — same shakedown, plus a parser shaped to a real message | IMAP credentials + one real alert email | 6 |
| `PlafondBands` tier-4 figures | **S** — the class ships deliberately empty; ~150 lines of data + tests | the `plafonds de ressources` tables per zone/household | 2 |
| Remaining 4 institutional sources | **S each** — config-only once the first of each type works | the captures above | 5/7 |
| `Enrich/transit` + `Enrich/geo` | **M** — not started; door-to-door commute, commune → INSEE | IDFM/PRIM API key | 8 |
| Cross-portal price history per *logical* listing | **M** — the store keys per source today; clustering is a separate failure profile | nothing | 8 |
| Real corpus texts replacing 114/114 synthetic | **M** — append, never renumber | the captures above | 2 |
| `src/phorj/` port of the pure core | **L** — the point of the two-language tree | three phorj builds (`docs/PHORJ-REQUIREMENTS.md`) | — |
| Final MAXIMAL certification round | **M** — 3 lenses, two consecutive clean rounds, frozen commit | the above landing | — |

### Wall-clock, in sessions of the size worked on 2026-08-19

- **Unblocked right now: ~1.5 sessions.** The `html` adapter is ~1 session; cross-portal price
  history ~half.
- **Blocked on inputs: ~3–4 sessions**, and they unblock cheaply — the first real source is ~2 h
  after its capture arrives, each subsequent one of the same type ~30–60 min.
- **phorj port: ~2–3 sessions**, independent of everything else.
- **Final certification: ~1 session.**

**Total ≈ 8–9 sessions, ~25–35 working hours** — but the calendar is set by how long the four
inputs take to arrive, not by the hours. Supplying the first cURL capture moves the "product
usable" row from 0% to something real in a single afternoon; nothing else on this list does that.

## Decisions Log

- [2026-08-23 23:05] DONE, and three of the numbers it was planned from did not survive contact.
  The **letterless fast path is implemented** in `TenureClassifier::excludedVocabularyIn()` and in
  `proceduralSurfaces()`: a value with no letter cannot match any vocabulary literal, so it is
  skipped. Measured A/B/A on the **real frozen Logirep payload** through the real adapter
  (`tests/fixtures/logirep/search.html`, 113 listings, 31 fields on row 0, 11 of them letterless —
  exactly the structure the 02:05 entry predicted):

  | | ms/listing |
  |---|---|
  | guard on | 24.8 / 27.4 / 22.9 |
  | guard off | 36.2 |

  **~30%, and the three corrections are worth more than the number.**

  1. **The ~155 ms/listing figure does not reproduce.** The same payload, the same machine, the same
     class measures **~36 ms/listing** unoptimised today. The 02:05 measurement was taken with a
     synthetic field count rather than the real record, and it overstated the problem by 4×.
  2. **The predicted "~35% win" was right by accident.** A synthetic bench built to favour the
     change — 11 deliberately letterless string fields — measured **11%**, and the corpus suite
     measured **slightly NEGATIVE**, because corpus cases are prose with almost no fields and the
     guard costs one regex each. Only the real payload gives ~30%. Two benches, two wrong answers,
     in opposite directions.
  3. **The fixture-suite wall clock cannot see it at all** (7.8 / 7.5 / 7.3 s, with the *off* run
     beating an *on* run). Those suites are dominated by DOM parsing. A change measured through the
     wrong harness reads as noise, and would have been abandoned as worthless.

  **What makes it safe is one invariant and one placement**, and both are now sabotage cases rather
  than review comments. Every literal in `LABELS`, `AMBIGUOUS_LABELS` and `PROCEDURAL` contains a
  letter — asserted by reflection over the tables themselves, so a literal added tomorrow is
  covered. And the test runs **after** decoding: `&#80;&#76;&#65;&#73;` is letterless and decodes to
  `PLAI`, so the raw-string placement silently skips the one value §1 exists to catch. The two
  placements are indistinguishable in review, and the first version of the test that was supposed to
  tell them apart **passed against the mis-placed guard** — it asserted the outcome was not `MATCH`,
  which on a mixed source with no default is true whether or not anything was seen. Asserting on the
  REASON is what caught it; the mis-placed guard produces an empty reason list.

  **What was NOT done, deliberately:** narrowing what goes into `fields`. Nothing is excluded from
  the scan by name, kind or origin — only values in which no match is possible, which is why the
  reflection test is the thing keeping "possible" honest.


- [2026-08-22 02:05] MEASURED, not fixed: **`TenureClassifier::classify()` costs ~15 ms of fixed
  overhead plus ~6 ms per mapped FIELD**, so a Logirep listing with 31 fields takes ~155 ms and the
  113-row payload takes ~17 s. Measured on this machine, PHP 8.5.9:

  | fields | ms/classify |
  |---|---|
  | 0 | 22.7 |
  | 5 | 30.7 |
  | 10 | 49.3 |
  | 20 | 111.7 |
  | 31 | 214.7 |

  **It is pre-existing, and newly EXPOSED rather than newly caused.** A `json` source maps the whole
  raw record into `fields`, and Logirep's records carry 31 keys; the html sources map far fewer. It
  is not one pathological regex — `Text::fold`/`foldPreserveCase` are ~17 µs and a single `preg` is
  ~1 µs, and `vocabularyKeys()` is already memoised. It is inherently O(fields × vocabulary):
  ~31 fields × ~67 literals × several passes ≈ 6 000 regex operations per listing.

  **Not fixed here, deliberately.** The one provably-safe cut — *a value containing no letter cannot
  match any vocabulary literal*, verified by reflection to hold for all three tables (0 literals
  without letters) — skips only 11 of Logirep's 31 fields, a ~35% win for a change to the
  §1-critical file. That is a poor trade to make at the end of an unrelated build, and it deserves
  its own gate and its own sabotage round. **What must NOT be done instead is narrowing what goes
  into `fields`**: scanning every field is how an unmapped financing code gets caught, and
  `SurfaceMatrixTest` exists because "a correct rule applied to a subset of the surfaces it belongs
  on" was a P0 eight times.

  **Why it is worth a plan entry rather than a shrug:** `tests/sabotage-check.sh` runs the WHOLE
  suite once per case, ~315 times, so every second added to the suite is ~5 minutes on the ledger.
  Merging the two full-payload §1 tests in `LogirepFixtureTest` into one walk already took the class
  from 22 s to 9 s for identical coverage. The remaining exposure is real but bounded, and a real
  `--watch` pass spends ~17 s of CPU classifying this one source.

- [2026-08-22 01:10] CORRECTED, and it reverses part of the 00:40 ruling: **A12's rent is mapped
  `charges_included: false` and NOTHING is hydrated.** The 00:40 decision to hydrate detail pages
  for charges rested on three premises, all measured false the same night:
  1. **"8 rows in the 78/95 departments the criteria filter on" → actually ONE row passes.**
     `Criteria::matchesCommune()` requires the commune NAME to be in `communes` *and* the postcode
     prefix to match; the department prefix alone is not the filter. Of 113 rows, 19 are
     Île-de-France and exactly **1** — a 382 m² commercial unit in BEZONS — passes the gate. So
     A12's live yield today is **0 matches, the same as Cityloger**, not "would yield on day one".
  2. **Charges are not a detail-page FIELD.** They appear as free prose inside the description
     (*"Loyer hors charges : 1 278,82 € / Provision pour charges : 383,85 € / Loyer charges
     comprises : 1 662,67 €"*), present on the one dwelling measured and absent on the commerce —
     evidence of n=1. The gap is **30%**, not the ~15% previously recorded.
  3. **`detail_map` is refused by the loader on any non-`html` source** (`ConfigLoader:433`, so it
     cannot sit unread while looking like configuration), and Logirep's payload is JSON in a script
     tag. There was no "Cityloger machinery to reuse" without a cross-adapter refactor.
  What settled it is that `CriteriaEngine` was **already designed for this exact source shape** —
  its own comment reads *"rent (F6), charges comprises, and never on an HC-only figure"*. With
  `charges_included: false` the value lands in `rentHc`, the `max_rent_cc` disqualifier never fires
  on it (it is guarded on `$rentCc !== null`), and the score line prints *"… € HORS CHARGES — total
  réel inconnu, plafond non vérifiable"*. Honest, zero refactor, zero extra requests. **The cost,
  stated:** for this source the rent ceiling is never checkable. **The reversing line:** if a
  Polylogis DWELLING ever passes the commune gate, revisit — and gather more than one sample of the
  charges prose before regexing it.
- [2026-08-22 01:10] NOTED: **the full sabotage ledger was stopped at 117/~315 cases (0 undetected)
  and re-run once at the end**, rather than twice. It copies `src`, `tests` AND `config` per case,
  so it cannot run while a source is being built; at ~0.5 min/case it had ~100 minutes left. The
  partial is kept at `var/claude/sabotage-ledger-20260822-partial.log`.

- [2026-08-22 00:40] AGREED: **A12 Logirep/Polylogis is onboarded as source #4, now.** It is the
  best unbuilt source measured: one endpoint covering four Polylogis landlords, 113 leasing ads, 19
  of them Île-de-France and 8 in the 78/95 departments `criteria.json` filters on — so unlike
  Cityloger, whose live yield is 0, this one would plausibly yield on day one.
- [2026-08-22 00:40] AGREED: **its `h.c.` rent is resolved by hydrating the DETAIL page, gated on
  `Criteria::matchesCommune()`** — the Cityloger pattern, unchanged. The card quotes rent *hors
  charges* and only the detail page carries charges, so reading the card alone under-reports every
  rent by ~15% and would compare the wrong number against `max_rent_cc`, which is hard rule 9's
  exact failure. Gating on commune keeps the cost at ~8 detail fetches out of 113. The rejected
  alternative was a fixed ~15% uplift: it invents a figure the source never stated, and hard rule 9
  exists against precisely that.
- [2026-08-22 00:40] AGREED: **every Logirep listing resolves `UNKNOWN` and goes to the *à vérifier*
  digest, and that stays.** Neither the card nor the detail page states tenure, so §1's fail-closed
  default applies and no classifier change is made to accommodate the source. The flooding worry
  resolves itself: the commune gate means ~8 listings reach the digest, not 113. Revisit only if a
  second tenure-silent source is added — the reversing line is a per-source digest cap.

- [2026-08-22 00:24] NOTED (latent, deliberately not built): **a detail page is judged against the
  SEARCH origin's `robots.txt`.** `Robots::pathOf()` strips the host, and `HtmlSource` holds one
  `Robots` for the source, so a `detail_map` whose cards linked to a DIFFERENT host would have those
  URLs checked against the wrong site's file. True and harmless today — every `detail_map` in
  `config/sources.json` is same-host, and Cityloger's detail pages sit under its own domain — and it
  becomes wrong the day a source's cards link out. Not fixed here: a per-URL resolver is real work
  for a case no source exhibits, and building it now would be speculation. Recorded so that
  `/add-source` trips over it rather than discovering it in production.

- [2026-08-21 23:58] AGREED: **`robots.txt` is enforced at RUNTIME, and the CLI grew an
  `HttpClient` seam so that it can be.** `Robots` was fully implemented and both network adapters
  consulted it for the index, for every paginated page and for each detail page — but every check
  was guarded by `$this->robots !== null`, and the only two production construction sites
  (`Scout::buildSource()`) passed `null`. Robots was therefore enforced in tests, by injection, and
  never once on a real poll: `scout doctor --source=inli` fetched four pages of a live landlord's
  site without reading its `robots.txt`, while `HttpJsonSource`'s own docblock claimed *"it checks
  robots.txt before fetching (hard rule 5), and fails CLOSED when it cannot"*. The defect was
  invisible to the whole suite because a `null` robots does not mean *"check later"* — it means
  *"never check"*, silently.
- [2026-08-21 23:58] AGREED: **the status-code table for an unreadable `robots.txt` is
  2xx → parse · 404/410 → ALLOW · everything else, including 403 and 5xx → FAIL CLOSED.** The 404
  row is the one that needed arguing, because `Robots::unavailable()` disallows everything and the
  class docblock makes that a deliberate posture. The distinction that resolves it: a 404 is not
  *"we could not read it"* — it is *we successfully established that no file exists*, which is the
  ordinary no-restrictions case on the open web, and reading it as a disallow would have silently
  disabled every host that never wrote one. This is exactly RFC 9309's own split, and exactly the
  reading recorded in `docs/SOURCES.md` on 2026-08-21: §2.3.1.3 *unavailable* (4xx) → a crawler MAY
  access; §2.3.1.4 *unreachable* (5xx) → a crawler MUST assume complete disallow. The project stays
  STRICTER than the standard on `401`/`403`, deliberately: a site answering 403 to `robots.txt` is
  refusing this client, and hard rule 5 takes that at face value.
- [2026-08-21 23:58] AGREED: **redirects need no row of their own, and that is a property of
  `CurlHttpClient` rather than a gap.** It already follows up to three redirects and refuses to
  leave the original host, so an apex→www or http→https redirect on `/robots.txt` is followed
  transparently. A robots file redirecting to a DIFFERENT host arrives as a transport failure and
  fails closed — correct rather than incidental, since `robots.txt` speaks for one origin and a file
  served by another host has no authority over this one. Worth recording because the row looked
  missing: `toitetjoie.com` had just been measured 301-ing to its real host, so a redirecting
  robots is not hypothetical here.
- [2026-08-21 23:58] AGREED: **the once-per-host cache lives in `Scout::sources()` as a local, NOT
  in `RobotsResolver`.** The first cut put it in the resolver and
  `TenureCorpusTest::testEveryCoreValueObjectIsImmutable()` caught it: every class under `src/php/`
  must be `readonly` unless it implements `MutableByDesign`, whose bar is explicitly *"its mutation
  must BE the mechanism, not an optimisation"*. A memoisation table does not clear that bar. Taking
  the exemption anyway is how such a rule stops meaning anything — so the resolver was made
  stateless and the cache became a local whose lifetime is one build of the source list. The
  guarantee is unchanged and still asserted end to end
  (`ScoutRobotsTest::testRobotsIsFetchedOncePerHostAndNotOncePerPage`); only its home moved.
- [2026-08-21 23:58] AGREED: **a `scout run --watch` process holds the robots verdict it read at
  startup, and that is a KNOWN bounded limitation rather than an oversight.** Sources are built once
  per process and the loop re-polls them, so a watcher running longer than a day is outside RFC 9309
  §2.4's 24-hour caching norm. Closing it means handing the adapters a resolver instead of a
  `Robots`, which changes the `Source` construction contract — deliberately not done in this change,
  and recorded here so the next reader finds a decision rather than a bug.
- [2026-08-21 23:58] AGREED: **an unreadable `robots.txt` must not be reported as a rule that
  disallows.** `Robots::refusal()` now says *"illisible (HTTP 500 sur …) — posture fail-closed"* for
  the unavailable case and keeps the exact `robots.txt disallows <path>` wording — asserted by
  several suites — for the rule case. Reporting a 500 as a disallow sends the reader hunting through
  a robots file for a line that is not there, when the fault is a broken server or an expired
  certificate.

- [2026-08-20 18:41] AGREED: **ICF Habitat Novedis (A2) is not a pollable source and is dropped from
  the build queue.** It was ranked second in `docs/SOURCES.md` on PORTFOLIO value — 10 000 non-social
  units aimed at incomes above the social ceilings — and that ranking survived because a `200` was
  read as a feed. Measured three levels deep on 2026-08-20 (`/patrimoine/icf-novedis` →
  `/patrimoine/filiale/icf-novedis/78-yvelines` → `/patrimoine/localites/icf-novedis/78500-sartrouville`):
  every page lists *résidences*, not vacancies — zero rents, zero surfaces, zero occurrences of
  `disponib`. Remaining routes are an email alert or the portals. Reversed by finding a Novedis
  vacancy feed anywhere; `novedispm.fr` is the third-party management arm and was deliberately not
  crawled.
- [2026-08-20 18:41] AGREED: **CDC Habitat (A3) is source #2 and is `enabled: true`.** Its
  `robots.txt` is stricter than the catalogue recorded — `/Recherche/show/`, `/Recherche/search` and
  seven search query parameters by name — so the parameterised search is refused outright. What the
  site advertises in its OWN `sitemap.xml` is the lowercase, query-free `/recherche/location/<region>`
  tree, and robots path matching is case-sensitive. Polling that, and only that, is within hard rule
  5. Reversed the moment CDC's `robots.txt` covers it, which is why that file is frozen beside the
  payload and asserted per page by test rather than trusted from memory.
- [2026-08-20 18:41] AGREED: **a source paginates by query parameter or by path, never both**, and a
  `page_path` must contain `{page}`. Refused at load AND at fetch, because whichever mechanism the
  adapter ignores fails silently — a walk that refetches page one until the bound trips, or one that
  ends on a duplicate page and reports a short result set.
- [2026-08-20 18:41] AGREED: **the walk stops at the count the site declares about itself**, rather
  than probing one page past the end. The probe assumed an out-of-range page comes back empty; CDC's
  answers `301`, and this adapter refuses a non-2xx deliberately — a redirect landing back on page
  one ends a walk exactly like a genuine last page. This is not new trust in the declared total: it
  was already the assertion the walk exists to make. Where a source declares no total, the empty-page
  probe still applies.
- [2026-08-20 18:41] AGREED: **`fields['_text']` is PROSE and belongs in `RawListing::text()`**, not
  in the structured-field scan. The field path matches financing acronyms case-insensitively, on the
  stated grounds that a field value is an identifier rather than prose; fed a card's prose it read
  the French adverb in *"implanté au plus près"* as the excluded acronym `PLUS` and vetoed the
  listing's own tier-1 badge. `plus` is one of the commonest words in the language, so this was not
  a CDC quirk. The change is a RE-ROUTE, never a skip — the counterweight that excluded vocabulary
  in card text is still SEEN is pinned by test, on the reason string rather than on the outcome,
  because "did not match" is also what silence looks like.
- [2026-08-20 23:05] AGREED: **a sabotage case is defined ABOVE the tally, and the ledger ends on an
  explicit `exit`** — both pinned by structural checks in `tests/test-ci-workflow.sh`. Appending to
  the end of `sabotage-check.sh` is the obvious way to add a case and it silently broke the gate
  twice over: the tally counted 295 of 303, and because there is no `set -e`, the trailing calls ran
  past the exit expression and left their own `0` as the script's status, so a FAIL anywhere exited
  0 and the nightly job could not go red. Position is not a convention here; it is the mechanism.
- [2026-08-21 01:39] AGREED: **a source may fetch a listing's own detail page, behind a gate, via
  `detail_map`** — and a `detail_map` with no gate REFUSES rather than defaulting. Cityloger's search
  card carries no tenure at all, so on a mixed source every listing resolved UNKNOWN and went to the
  digest forever. Both defaults are wrong and one is silent: hydrating everything is a per-listing
  crawl of somebody else's site, hydrating nothing is a source that looks healthy and can never
  match. The gate is the run's own `Criteria::matchesCommune()`, injected by the CLI, because it is
  the only filter whose inputs the CARD already carries in full — gating on rent or surface would
  reject on a field the detail page might have been the one to supply (hard rule 8).
- [2026-08-21 01:39] AGREED: **a detail map's selectors address the LISTING, never the page**, and
  the adapter enforces it structurally by not adding `_text` on the detail path. Measured on the
  frozen Antony payload: its own `.description` classifies LLI 0.90, the whole page classifies
  UNKNOWN 0.00, because "Commission d'attribution" and "demande de logement social" are furniture
  present on social and intermediate listings alike. Third instance of one failure class — after
  `au plus près` and the `_text` field scan — and the reason the corpus now carries
  "Logement intermédiaire géré par un **bailleur social**" as a captured case.
- [2026-08-21 01:39] AGREED: **`{page}` may appear in `url` itself**, mutually exclusive with
  `page_param` and `page_path`, validated at load. For a site whose page number sits mid-path. The
  rejected alternative — `url` = the site root, so page one is the homepage widget whose ten cards
  are identical today — fails silently the day that widget becomes "featured" rather than ranks 1-10.
- [2026-08-21 10:52] AGREED: **A10 Batigère, A7 1001 Vies and A8 Antin are dropped; source #4 ends
  in a finding rather than a source, and that is the honest terminus.** A10 was the catalogue's
  starred best-remaining candidate: its offers subdomain is Liferay with a third-party search widget
  whose bundle names its backend, `api.app.quadral-eservices.fr/api`. Two independent stops, either
  alone sufficient — that host's `robots.txt` answers **500**, and RFC 9309 §2.3.1.4 says an
  unreachable robots means a crawler MUST assume complete disallow; and `GET /api/offers/offers`
  answers **401**, so the only way in would be replaying the widget's credential, which hard rule 5
  refuses. A7's WordPress REST API enumerates only core post types — no listings type, no
  client-rendered search behind it — and its tenant route links out to
  `demande-logement-social.gouv.fr`, which is out of scope entirely (§1). A8's single recorded
  lettings route, `/louer-acheter`, answers **404**. What remains in Track 1 is A4 AL'in
  (authenticated — an INPUT, alongside the IMAP credentials and the `plafonds` figures) and the
  Tier B email-alert route. Enabling A15 instead, which is predominantly social stock, to have a
  fourth source would be strictly worse than having three.
- [2026-08-21 10:52] AGREED: **an unreadable `robots.txt` has TWO verdicts, and a row that blurs them
  overclaims.** 5xx is *unreachable* → MUST assume complete disallow (the standard is stricter than
  this repo's own posture). 4xx is *unavailable* → a crawler MAY access, so the 403 on
  `offres.batigere.fr` is blocked by this repo's stricter posture, not by RFC 9309. Both are recorded
  with their status codes and dated, because a 500 can be transient and the row should invite a
  re-check rather than close the source forever.
- [2026-08-21 10:52] AGREED: **when a search is client-rendered, follow the widget to its API host
  before concluding anything.** Zero `€`/`m²` on a 197 KB page means rendered elsewhere, not absent.
  Three greps settle it: third-party `script src` hosts on the page → absolute URLs and quoted paths
  in the widget bundle → the API host's own robots plus one unauthenticated probe. On WordPress the
  faster form is `wp-json/wp/v2/types`, which enumerates custom post types and so answers the
  JS-rendered-search objection that a sitemap scan cannot.
- [2026-08-21 01:39] AGREED: **rank a source by a verified FEED, never by portfolio size.** A2, A5
  and A6 were each ranked on how much stock the landlord owns and each publishes no vacancies at
  all; Seqens and 3F both dead-end at `al-in.fr`, which makes A4 AL'in the only route to the Action
  Logement ESH stock rather than one source among many. The cheap pre-check now recorded in
  `docs/SOURCES.md`: on WordPress read `sitemap_index.xml` for a listings post type; on any site
  scan the index page for `€`, `m²` and `disponib`.
- [2026-08-20 23:05] AGREED: **a gate's exit status is part of its contract and gets its own test.**
  The ledger had a correct baseline gate, a correct per-case verdict, a correct summary and a loud
  failure list — and still could not fail. Every existing check was about what the gate *says*;
  nothing asserted what it *returns*, which is the only part CI reads.

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
- [2026-08-07 17:05] AGREED: adapter error text is masked by `Scout\Core\Redact` at the store,
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
  seen-set is the opposite of scratch. The stateful-data list (then `BLAST-RADIUS.md`, since
  2026-08-18 CLAUDE.md § "Credentials & stateful data" — the bootstrap-era file left with it) names
  the path, and names `rm -rf var/` as safe so nobody widens it by analogy.
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
- [2026-08-07 20:30] AGREED (dedup): merge only on POSITIVE evidence, never on the absence of a
  difference. Two listings that agree on nothing because they state nothing are not duplicates —
  `MIN_CORROBORATING_FACTS = 2` is what enforces it, and it is the single most load-bearing constant
  in the module. With 1, agreeing on room count alone merges every T4 in a commune, and a T4 filter
  means every candidate is a T4: the whole result set collapses into one notification and the rest
  are silent losses.
- [2026-08-07 20:30] AGREED (dedup, from `docs/SOURCES.md`): **tracks do not merge.** A flat on In'li
  AND on SeLoger is two findings, because the application route differs — applying directly is a
  different act from applying through an agency, with different competition. Collapsing them hides
  the better route behind the worse one.
- [2026-08-07 20:30] AGREED (notify): a `Channel::send()` **throws on failure and never returns
  silently**. A channel that swallows a delivery error is the notification-layer form of
  `except Exception: return []` — the run reports success, the listing is marked notified, and the
  flat is gone. `Notifier::delivered()` is what the caller asks before marking anything notified.
- [2026-08-07 20:30] AGREED (notify, Q28 made concrete): `Notifier` attempts EVERY usable channel and
  collects the failures rather than stopping at the first. A partial outage must not cost the
  delivery that would have succeeded.
- [2026-08-07 20:30] AGREED (secrets): `Redact::text()` gained an optional `$literals` argument, and
  it exists for one secret whose shape defeats every name-based rule — the ntfy topic travels as a
  URL PATH SEGMENT, so there is no `topic=` to anchor on. The existing pattern covers the default
  `ntfy.*` host and leaks the moment the server is self-hosted under another name, which
  `.env.example` exists to permit. `NtfyChannel` passes its topic to every `ChannelError` it raises.
  A test asserts the leak still exists without the literal, so the argument cannot be quietly dropped.
- [2026-08-07 20:30] AGREED (notify): the headline leads with commune, size and rent — NOT the
  source's own title, which is written to sell and buries the facts. A notification is read on a lock
  screen, and an hors-charges rent is FLAGGED rather than shown as if comparable to the budget.
- [2026-08-07 20:30] FOUND BY A TEST: the `NotifyTest` listing helper used `??`, which swallows an
  EXPLICIT null override — so `['rentCc' => null]` silently kept the default and the hors-charges
  test asserted against a listing that had a CC rent after all. A helper that quietly ignores what a
  test asked for makes the test prove something other than what it says.
- [2026-08-07 21:30] AGREED (schema v3): `listings.tenure` / `confidence_bp` / `signals_json` (Q24),
  `source_runs.duration_ms` (Q25) and a `source_alerts` table (Q29). The last one is the reason v3
  could not wait: the alert cooldown has NOWHERE durable to live otherwise, and in process memory a
  crash-looping container re-alerts on every restart while a manual `doctor` shares no state with a
  running `--watch`. It cannot be derived from `source_runs` either — that table records RUNS, not
  ALERTS, and cannot tell "was broken" from "was told about". The migration does NOT backfill
  `tenure`: `UNKNOWN` would be indistinguishable from a real UNKNOWN verdict, and `NULL` is the truth.
- [2026-08-07 21:30] FOUND BY RUNNING IT: `--seed` recorded sightings but left `notified_at` NULL, so
  the very next run notified all six matches — the flood simply moved one run later, which is exactly
  what Q36 exists to prevent. `--seed` now means "already seen AND already told about".
- [2026-08-07 21:30] FOUND BY RUNNING IT: the digest re-emitted its whole contents every pass. Q34
  requires entries be marked emitted only after DELIVERY; without that it becomes the alert fatigue
  it was designed to avoid, and a digest the developer has learned to skip costs the fail-closed rule
  its only landing zone. `notified_at` is reused rather than duplicated — being carried in a
  delivered digest IS being told about the listing.
- [2026-08-07 21:30] **THE SABOTAGE RUN EARNED ITS KEEP AGAIN, harder than before.** `ScoutTest`
  asserts on the CLI's real stdout, which is the right evidence for the CLI — and 15 sabotages left
  it green. Eleven were genuine holes: a failed fetch that went unrecorded, `item_count` counting
  matches instead of parsed items, an ignored alert cooldown, a cooldown keyed on the source alone,
  no recovery notice, a verdict never persisted, an unmeasured duration, a removed scraping gate, a
  silently dropped channel name, a freshness bonus given to everything forever, and a match marked
  notified with no channel confirming. `tests/php/Cli/PipelineRunTest.php` exists because of that
  round, and a temp-root harness was added to `ScoutTest` because four guards were unreachable from
  the committed config at all — a guard no test can reach is a guard someone will delete.
- [2026-08-07 21:30] RETIRED, with the reason recorded rather than the case quietly deleted: the
  "doctor stops passing the clock" sabotage could never go red. `doctor` records its own successful
  run immediately before asking for health, so the clock and the newest stamp always agree and no
  verdict can differ — the argument is defensive there, not load-bearing. An earlier version of the
  test's docblock CLAIMED it proved doctor passes the clock; it did not, and the claim is corrected
  in place rather than removed.
- [2026-08-07 21:30] AGREED: `buildChannel()`'s unknown-channel refusal was restructured from a
  `default => throw` arm into a separate guard, purely so a sabotage can remove it in ONE line and
  see the suite go red. As a multi-line throw-arm it could only be broken into a PHP parse error,
  which proves nothing about the guarantee.

- [2026-08-07 23:15] CORRECTED, on the developer's challenge — *"Can't you use a fake smtp with fake
  mailer and then i will just use .env to change the real credentials ??? and i don't understand
  about the rest why it's blocked ??"*. They were right and I was wrong on three of the four
  "blocked" items. I had conflated **credentials** with **message shape**, and **an endpoint** with
  **an adapter**. What hard rule 1 actually forbids is writing a URL into `config/sources.json` and
  enabling it; it says nothing about the transport. So:
  `HttpJsonSource`, `EmailAlertSource`, `ImapMailbox`, `SmtpTransport` all exist now, fully tested
  offline against fakes, and `.env` swaps the real thing in. Only the URL VALUE still waits on a
  DevTools capture, and only the `plafonds` figures are genuinely unobtainable here.
- [2026-08-07 23:15] AGREED (the seam): each network boundary is an interface with a file-backed
  fake — `HttpClient`/`FakeHttpClient`, `Mailbox`/`FileMailbox`, `MailTransport`/`FileTransport`.
  That is the same trade `FixtureSource` already made, and it is what makes `spec` §11's "no network
  in CI" compatible with having network adapters at all. `docs/PHORJ-REQUIREMENTS.md` asks phorj for
  exactly this shape on its side.
- [2026-08-07 23:15] AGREED (hard rule 5, enforced in the transport rather than per adapter): one
  honest User-Agent, no cookie jar, no proxy, no cross-host redirect, and `robots.txt` consulted
  BEFORE the fetch. `Robots` **fails closed** — an unreadable file disallows everything, which is the
  opposite of the usual convention and deliberate: a false "disallowed" costs one source staying off
  until someone looks; a false "allowed" costs polling a site that asked us not to.
- [2026-08-07 23:15] FOUND BY RUNNING IT — **ISO-8859-1 must be read as CP1252.** French alert mail
  declares Latin-1 and is almost always CP1252; the two differ exactly in 0x80–0x9F, where `€` lives.
  Under strict Latin-1 the euro sign became an invisible control character, and every rent pattern
  requires a currency marker — so the rent came out NULL on an alert that stated it plainly, and a
  listing with no rent is not disqualified (hard rule 9). It would have been notified as "loyer non
  communiqué" while the alert said 1 450 €.
- [2026-08-07 23:15] FOUND BY RUNNING `test-notify` — the shipped default sender `rent-watch@localhost`
  fails PHP's `FILTER_VALIDATE_EMAIL` (no dot in the domain), so the email channel **silently
  disabled itself** out of the box. The sender is now checked loosely and the recipient strictly: a
  typo in the recipient means mail goes nowhere, but a legal dotless local sender must not disable
  the channel.
- [2026-08-07 23:15] AGREED (`EmailAlertSource` is deliberately conservative): no real portal alert
  has been seen, so extraction is generic and the class is built to be SHAPED by a real message
  rather than to guess one. The cost is stated: a listing it cannot read confidently gets no tenure
  signal and DIGESTS on a mixed source. It must never follow the links to scrape the page — that is
  the route hard rule 4 gates, and doing it from the email path would bypass the gate entirely.
- [2026-08-07 23:40] SABOTAGE RESULT for the network adapters, recorded rather than left unverified
  (the previous commit said it was still running and did not claim a result): **230 detected, 6
  undetected.** Two of the six were MY OWN broken sed expressions and not test gaps — a multi-line
  `\n` pattern that sed cannot match, and an over-escaped regex — and the tests they were meant to
  drive do exist and do assert the guarantee. Both expressions are fixed; the fix is NOT yet
  re-verified, because a full run takes ~20 minutes and the session was ending.
  **The remaining FOUR are genuine test gaps, and they are the first work of the next session:**
  1. **the honest User-Agent** (hard rule 5) — nothing asserts the string, so replacing it with a
     browser disguise leaves the suite green. This is the rule the project is most explicit about.
  2. **the rent plausibility band** in `EmailAlertSource` — the fixture does not contain a bare
     five-digit number that could be misread as a rent, so removing the 200–20000 guard changes
     nothing. Needs an alert fixture carrying a reference number or a bare postcode.
  3. **SMTP continuing without STARTTLS** when the server does not advertise it — this is the one
     that sends a credential in the clear, and it currently has no test at all. It needs a scripted
     fake SMTP server on a loopback socket, which is real work and worth it.
  4. **SMTP masking the base64 form of the password** — `NetworkAdaptersTest` constructs a
     `ChannelError` directly with the literals, so it proves `Redact` works and proves nothing about
     `SmtpTransport::secrets()` actually passing them. Same shape as the `mixed_tenure` guard that
     was decorative: the test asserts the mechanism, not the wiring.
- [2026-08-12 — session start] THE FOUR TEST GAPS ARE CLOSED, and the closing found a fifth. What
  was built, all verified by a targeted sabotage mini-run going 7/7 red before the full run:
  1. **honest User-Agent** — `testTheUserAgentIdentifiesHonestlyRatherThanDisguising` pins the
     shape: `rent-watch/` leads, a contact route is present, and no browser family token
     (mozilla/chrome/chromium/safari/firefox/gecko/applewebkit/edg//opera) appears at all.
  2. **rent plausibility band** — `testAnOutOfBandFigureIsNotReadAsARent` drives a self-contained
     temp mailbox (NOT the shared fixture dir, whose listing counts other tests assert) through the
     real email path: an alert whose only currency-marked figures are 150 EUR (agency fee) and
     245 000 EUR (sale price) must yield a null rent, and its five-digit `95240` must land in
     `postcode`, never in rent.
  3. **SMTP without STARTTLS** — `SmtpTransportWireTest` + `scripted-smtp-server.php` (a one-
     connection scripted server the test forks; every socket wait bounded at 5 s; the OS picks the
     port). `testAServerNotOfferingStarttlsGetsNoCredentialAtAll` proves the refusal happens BEFORE
     any credential leaves the process — by asserting on the wire transcript, not on the exception.
  4. **`secrets()` wiring** — `testARejectedLoginDoesNotLeakTheCredentialTheServerEchoes`: the fake
     server echoes the base64 password back in its 535 (the `{LAST}` placeholder), exactly what a
     real server does, so the test passes only if `SmtpTransport::secrets()` actually hands both
     forms to `ChannelError`. The transcript additionally proves the credential really crossed the
     wire, so the mask assertion cannot be vacuous.
  Plus `testADeliveryDotStuffsTheBodyOnTheWire` (nothing exercised `send()`'s happy path or RFC
  5321 dot-stuffing as transmitted) and a matching new sabotage case — 237 cases at d9f4263 (an
  earlier draft of this entry said 238; the extra grep hit was the function definition, a round-1
  panel finding).
- [2026-08-12] FOUND BY THE MINI-RUN, the fifth gap: "block tags stop becoming newlines" STAYED
  GREEN after its sed was fixed — the sed degrades `</p>` but the test only exercised `</li>`. A
  correct rule tested on a subset of its surfaces, the recurring P0 shape. The test now iterates
  EVERY member of the tag class (p, div, li, tr, td, h1–h6, and the three `<br>` spellings) and
  asserts a real newline separates the communes, not merely non-collapse (a space would have
  passed the old assertion).
- [2026-08-12] Mini-run harness note for future targeted runs: a script extracted from
  `tests/sabotage-check.sh` into scratch must pin `repo=` — the original computes it from
  `BASH_SOURCE`, so the copy resolves to the scratch dir and every case FAILs with "could not
  build the scratch copy".
- [2026-08-12] FULL SABOTAGE RUN at d9f4263: **237 cases, 237 detected, 0 undetected, exit 0** —
  the ledger's first fully-clean run. The previous run's six undetected are all red.
- [2026-08-12] ROUND-1 PANEL VERDICTS on d9f4263 (MAXIMAL, three lenses, pinned worktrees): SEVEN
  findings, counter reset. What each was and what closed it:
  1. (P1, resilience) The User-Agent test pinned the CONSTANT; a disguise at the wiring point
     (`CURLOPT_USERAGENT => 'Mozilla/5.0…'`, constant left honest) left all 1269 tests green.
     Closed by `testTheHonestUserAgentIsWhatActuallyCrossesTheWire` — a scripted one-request HTTP
     responder (`tests/php/Adapters/scripted-http-server.php`) receives a REAL libcurl request on
     loopback and the test asserts on the request head it recorded. Feasible here because
     `no_proxy` covers 127.0.0.1 and lowercase `http_proxy` is unset. Plus a wiring-bypass
     sabotage case.
  2. (P1, resilience) `"headers": {"User-Agent": …}` in config silently overrides
     `CURLOPT_USERAGENT` in cURL — a hard-rule-5 bypass needing no code change. Closed at BOTH
     ends: `ConfigLoader` refuses the key at load time (case-insensitive) and `CurlHttpClient`
     refuses it again at the funnel, because config is not the only path a header arrives by.
     One test and one sabotage case per guard.
  3. (P1, correctness) The band test's two figures sat in ONE message and `rentIn()` takes only
     the first match — so the 200 floor was exercised by NOTHING; deleting it left the full suite
     green. Closed by splitting into two messages (fee-only → floor; sale-price-only → ceiling)
     and TWO new sabotage cases, floor-only and ceiling-only removal.
  4. (P2, resilience) The scripted SMTP server read exactly one line per reply, so "no credential
     crossed the wire" was proven only for the FIRST client line — a blind post-EHLO credential
     write escaped the transcript. Closed: the server now DRAINS all remaining client input before
     writing the transcript; the reviewer's exact probe re-run afterwards goes red on the leaked
     base64 line.
  5. (P2, both lenses independently) The plan entry said "238 cases"; the ledger had 237 — the
     238th grep hit was the `run_sabotage()` function definition. Corrected in place.
  6. (P3, correctness) `</br>` is in the implementation's block-tag alternation but was not in the
     test's tag class. Closed: added to the br loop, plus a sabotage case removing `br` from the
     alternation (which only the `</br>` case can catch — the second alternation still handles
     `<br>`/`<br/>`).
  7. (found by the new wire test, not the panel) `curl_close()` is deprecated in PHP 8.5 and a
     no-op since 8.0 — latent in `CurlHttpClient` because NOTHING had ever executed the real curl
     path under the suite until the wire test did. Removed per global Rule 9.
  Ledger now 243 cases; all 12 new/affected cases verified individually red; suite 1272 tests /
  4584 assertions green. Full 243-case run + panel round 2 follow on the fix commit.
- [2026-08-12] FULL SABOTAGE RUN at ed62878: **243 cases, 243 detected, 0 undetected, exit 0.**
- [2026-08-12] ROUND-2 PANEL VERDICTS on ed62878: round-1 fixes all confirmed genuinely closed
  (each reviewer re-ran its own round-1 probe and watched it go red), but FOUR new findings,
  counter reset again:
  1. (P1 resilience / P3 correctness, found independently by both) Both User-Agent guards compared
     the header NAME for equality — and libcurl derives the name from the text before the first
     colon in a CURLOPT_HTTPHEADER entry, so a KEY of `user-agent:` cleared both guards and put a
     Mozilla UA on the wire (demonstrated with a loopback transcript). Closed by refusing any
     header name that is not an RFC 7230 token, at load time AND at the funnel — a colon, space or
     control character can never appear in a token, so every spelling of the smuggle dies at once.
     A line break in a header VALUE (the same defect class, other side) is refused too. Four tests,
     four sabotage cases.
  2. (P2 completeness) The `curl_close()` removal was not carried to its only other caller,
     `NtfyChannel` — the full-set-coverage rule violated inside a fix commit. Removed there too,
     and the path is no longer un-driven: `NtfyChannelWireTest` sends a real notification through
     real libcurl to the scripted responder and asserts the topic-in-path, the Title header, the
     honest User-Agent and the body link on the wire; `failOnDeprecation` then guards the path.
  3. (P2 completeness) `/add-source` step 1 told the operator to keep the capture's "minimum
     headers that make it work" — which for a UA-gated endpoint includes the browser User-Agent
     the loader now refuses. The step now says to drop it and why (committed separately, fad2452).
  4. Two of the four new CRLF sabotage seds were no-ops on first write (over-escaped `\\\\r` —
     matches two backslashes, the source has one); the harness's no-op guard caught both, fixed
     and re-verified red. The scripted HTTP responder also learnt to read a POST body
     (Content-Length-bounded) so closing early cannot make a client report a broken transfer.
  Ledger now 247; all 8 guard cases verified individually red; suite 1277 tests / 4601 assertions
  green. Full 247-case run + panel round 3 follow on this commit.
- [2026-08-12] ROUND-3 PANEL VERDICTS on f4770be: round-2 colon-smuggle fix confirmed dead at both
  layers against every variant tried, but THREE findings (two distinct bugs), counter still reset:
  1. (P1, resilience + completeness independently) `NtfyChannel` sanitised the landlord-controlled
     `Title:` header via `headerSafe()` but concatenated the equally landlord-controlled `Click:`
     url RAW — and that channel calls libcurl directly, so `CurlHttpClient`'s round-2 funnel guard
     never sees it. Both reviewers demonstrated a source-supplied url with embedded CRLF smuggling
     `X-Smuggled`/`Attach` as real headers on the POST to the user's ntfy server (SSRF / control-
     header forgery). Closed: `Click:` now goes through `headerSafe()` too; `NtfyChannelWireTest`
     grew a CRLF-url case asserting no injected header LINE reaches the header block (the collapsed
     string surviving inline in the Click VALUE is harmless — the body echoes the url anyway); plus
     a sabotage case.
  2. (P1, correctness) The token guard's `$` anchor matched before a single trailing newline, so a
     header NAME of `"user-agent\n"` passed the class AND dodged the equality guard, putting a raw
     LF into the request headers. Closed with the `D` modifier on both copies of
     HEADER_NAME_TOKEN; trailing-newline tests at both layers and a sabotage case per file pin it.
  3. The prior full sabotage run (b6wc3pnuy) was VOID — launched against f4770be, then this
     session edited src/tests while it was still copying per case, so 12 later cases hit parse
     errors from half-applied edits. The charter's "author does not edit while a round runs"
     applies to the full run too, not just the reviewer worktrees. Re-run cleanly post-commit.
  Ledger now 250; all 6 round-3 cases verified individually red; suite 1280 tests / 4630 assertions
  green. Clean full 250-case run + panel round 4 follow on this commit — with NO concurrent edits.
- [2026-08-12] FULL SABOTAGE RUN at b8c2ee1 (isolated in a git worktree, immune to concurrent
  edits — the fix for the void f4770be run): 250 cases, all detected. [recorded when it completed]
- [2026-08-12] ROUND-4 PANEL VERDICTS on b8c2ee1: correctness CLEAN and resilience CLEAN (both
  re-attacked the round-3 fixes across every CR/LF variant with loopback transcripts, and
  resilience's class audit found the round-3 finding had no live siblings — SMTP subject/from are
  headerSafe'd at their sole caller, the body dot-stuffed, IMAP args env-only). Completeness found
  TWO, counter resets:
  1. (P1) `EmailChannel::headerSafe` on the Subject (landlord-controlled title) and From is present
     and correct, but had ZERO test and ZERO sabotage case — proven silently removable, the exact
     structural twin of the round-3 ntfy Click finding. Closed:
     `NotifyTest::testEmailSubjectAndFromCannotSmuggleAHeaderFromListingText` drives a CRLF title
     through EmailChannel + FileTransport and asserts no injected header LINE (no Bcc, exactly one
     Subject) in the .eml header block; plus a sabotage case. (The From turned out doubly-guarded —
     check()'s `\s` already rejects a CRLF sender — so the test injects via the title, the surface
     guarded only by headerSafe.)
  2. (P2) `ImapMailbox::quote()` escaped `\` and `"` but not CR/LF. These are operator-controlled
     `.env` values (user/password/folder), not attacker input, so defense-in-depth — but an IMAP
     quoted-string cannot contain CR/LF at all (RFC 3501). Closed: quote() now throws on CR/LF;
     pinned by a reflection unit test (quote() is private and its only live path needs a TLS server,
     disproportionate for a P2) plus a sabotage case.
  Ledger now 252; all 3 new cases individually red; suite 1282 tests / 4640 assertions green.
  Round 4 is NOT clean → the two-consecutive-clean counter resets. Round 5 is the cap (5): if it is
  clean it is only the FIRST consecutive clean, so the gate's two-clean bar cannot be met within the
  cap — at that point the ladder requires asking the developer rather than silently proceeding.
- [2026-08-12] FULL SABOTAGE RUN at 5236049 (isolated worktree): 252 cases, all detected.
  [recorded when it completed]
- [2026-08-12] ROUND-5 PANEL VERDICTS on 5236049 (the cap round): correctness CLEAN; resilience and
  completeness EACH found one more surface of the same injection class — the class was still not
  fully enumerated. Both fixed:
  1. (P1, completeness) The ntfy `Title:` headerSafe guard — the ORIGINAL guard whose presence made
     the round-3 Click finding visible — had itself never had a test or sabotage case. Removing it
     left the suite green. Closed: `NtfyChannelWireTest` now injects a CRLF payload through the
     TITLE as well as the url and asserts no `Actions:`/`X-Smuggled:`/`Attach:` header LINE; plus a
     sabotage case on the Title headerSafe.
  2. (P2, resilience) `SmtpTransport` built `MAIL FROM`/`RCPT TO`/DATA headers with no CR/LF
     discipline of its own (caller-reliance, which the round-4 message overstated as "covered"), and
     `EmailChannel::check()`'s sender regex `~^[^@\s]+@[^@\s]+$~` LACKED the `D` modifier — the same
     trailing-newline hole fixed for the header token in round 3, in a different regex, so a
     `SMTP_FROM` ending in `\n` reached `MAIL FROM:<…>` as a bare LF. Closed: `SmtpTransport` refuses
     CR/LF in recipient/sender/subject/headers before connecting (symmetric with `ImapMailbox::quote`),
     the sender regex gained `D`, and both are pinned by a test + sabotage case each.
  The injection class is now enumerated and closed at EVERY builder from external/operator input:
  HTTP name/value/UA, ntfy Title+Click, email Subject/From, SMTP envelope+headers, IMAP quote — each
  with discipline + test + sabotage case. Ledger now 255; all 4 new/affected cases individually red;
  suite 1284 tests / 4656 assertions green.
- [2026-08-12] CERTIFICATION CAP REACHED. Five MAXIMAL rounds run; each found real findings (a
  genuine escalating chain: config UA override → colon-name smuggle → trailing-newline anchor + ntfy
  Click → EmailChannel/IMAP coverage twins → ntfy Title + SMTP/EmailChannel-D). The two-consecutive-
  clean bar was never met, so per the ladder + CLAUDE.md the 5-round cap escalates to the developer
  rather than proceeding silently. State at escalation: all findings from all five rounds fixed and
  committed; attacker-reachable surfaces have been clean since round 3; the last two rounds' findings
  were coverage-of-correct-guards and operator-input defense-in-depth, not live attacker-reachable
  bugs. Decision pending: accept current state as certified, or authorise further rounds.
- [2026-08-12] CAP DECISION (developer): run ONE more confirming round (round 6) on the round-5
  fixes, then decide. Rationale: 0cd782f's fixes had only targeted-sabotage verification, not a full
  adversarial panel; a single bounded round gives fresh-eyes review of exactly that gap. Not a
  commitment to full two-consecutive-clean convergence — reassess after round 6.
- [2026-08-12] ROUND-6 (confirming) VERDICTS on eabd4cf: correctness CLEAN; completeness CLEAN with
  the explicit convergence signal ("the enumeration is complete; no real uncovered injection surface
  remains"); resilience two P3s, both defense-in-depth/accuracy on unreachable paths. Isolated
  255-case sabotage run: 255/255. Developer chose to fix both P3s and accept (no round 7).
  1. (P3-1) `SmtpTransport::assertNoCrlf` echoed the raw header NAME (with its CRLF) in the error
     message, contradicting its own comment. Not reachable (EmailChannel uses constant header
     names), name is not a secret — a code-vs-comment inaccuracy / theoretical log-injection.
  2. (P3-2) `SendmailTransport` (the default, sink = mail()) and `FileTransport` built header lines
     with no CR/LF discipline of their own, relying on the EmailChannel funnel — so "closed at every
     builder" was caller-dependent for them.
  Both closed by extracting the guard into `Core/Notify/Headers::assertNoCrlf` (final readonly,
  shared), wired into Smtp/Sendmail/File at each boundary. The header NAME is now checked with a
  fixed label before being reused as the label for its own value, so a CRLF-bearing name is never
  echoed (P3-1). Coverage: `testEveryMailTransportSelfProtectsAgainstACrlfHeader` (Sendmail + File
  wiring), the SMTP test gained a header-name case asserting the error is newline-free, and four
  sabotage cases — the shared guard plus per-transport wiring for Smtp/Sendmail/File. The
  header/protocol-injection class is now literally closed at every builder, not caller-dependent.
  Ledger 258; all 6 new/affected cases individually red; suite 1285 tests / 4666 assertions green.
- [2026-08-12] CERTIFICATION CLOSED (developer-accepted). Six MAXIMAL rounds; the reachable
  injection class is closed at every builder from external/operator input, each with discipline +
  test + sabotage case, and rounds 5-6 drove the remaining items from P1 coverage gaps down to P3
  accuracy/defense-in-depth, all now fixed. Correctness and completeness were clean in round 6 with
  an explicit convergence signal. Accepted rather than pursuing a formal seventh round for the P3
  fixes, on the developer's decision.
- [2026-08-12] CI added (`.github/workflows/ci.yml`), on the developer's request. Two jobs:
  `test` (fast, every push/PR/dispatch) runs `composer dump-autoload --dev`, fetches the pinned
  PHPUnit PHAR, then the suite + `test-ci-workflow.sh` + `test-tenure-guard.sh` +
  `test-fetch-phpunit.sh` + drift-scan + shell `bash -n` + the two bootstrap self-tests (the latter
  SUPERSEDED — removed with the bootstrap in a8cfae7; ci.yml no longer runs them); `sabotage`
  (schedule nightly 03:00 UTC + workflow_dispatch, NOT per-push — it re-runs the whole suite once
  per seeded break, ~13 min — SUPERSEDED, that figure was never measured; see the 2026-08-19 entry)
  runs the 258-case ledger. Toolchain honoured: PHP 8.5 via
  shivammathur/setup-php, `--dev` autoloader (without it the corpus suite errors), vendor/ and the
  PHAR both regenerated/fetched (gitignored). fetch-phpunit is CI-safe — SHA-256 is the always-run
  gate and `unverifiable` is non-fatal on a match, and on a runner with keyserver access CI gets
  full PGP verification. Added `tests/test-ci-workflow.sh` (grep-based structural self-test, matching
  the repo's every-executable-has-a-self-test culture) so a step silently dropped from the workflow
  fails a test rather than passing unnoticed. Every workflow command dry-run green locally before
  commit; CLAUDE.md's "still no CI" line and the two file-layout listings updated.
- [2026-08-19 14:30] NIGHTLY LEDGER BLACKOUT — root cause found, and it was NOT the flag alone.
  The `sabotage` job had failed **7/7** since it was added (2026-08-13 → 2026-08-19) and had never
  once reached case 1. Nothing surfaced it: it is nightly-only and notifies nobody. Because the
  ledger is what proves the suite HAS TEETH, "1285 tests green" was an unbacked claim for six days.
  The full causal chain, each link verified rather than reasoned:
    1. `sabotage-check.sh` passed `--do-not-cache-result`, which disables the result cache;
       `phpunit.xml` sets `executionOrder="defects"` (needs it) + `failOnWarning="true"`. PHPUnit
       raises a runner warning and exits 1 on a suite with ZERO failing tests.
    2. That contradiction landed in 6e9778d — but was INERT on the runner the repo pinned.
       MEASURED: PHPUnit 13.2.6 (old pin 292ccbd5…, confirmed by download+hash) exits **0** with the
       flag; 13.3.1 (current pin) exits **1**. So it was not "latent then fatal" by itself.
    3. `phpunit-13.phar` is a MOVING tag, and `fetch-phpunit.sh` INSTALLED OFF-PIN whenever the PGP
       signature verified ("pin is stale but the signature is valid", then `mv` and exit 0). CI
       therefore silently ran 13.3.x while the repo pinned 13.2.6.
    => THE FIRST CAUSE is an unreviewed toolchain upgrade reaching CI with no commit, no review, and
       its only signal a log line in the job nobody watches. A signature answers "is this really from
       Sebastian Bergmann?", never "is this the runner this repo was tested against?".
  AGREED, and each is a ruling rather than a tidy-up:
  - REMOVE the flag from both call sites rather than dropping `executionOrder="defects"` from
    phpunit.xml — smaller blast radius, and cache isolation never needed a flag: `$work/repo` is
    rm -rf'd and rebuilt per case. Verified PHPUnit creates `var/phpunit` itself in a bare sandbox.
  - PIN THE EXACT VERSION (`VERSION=13.3.1`, immutable URL) so a moving tag cannot rotate under CI.
    The versioned URL hashes identically to the pin it replaced, so the switch moved no bytes.
  - A SHA MISMATCH NOW FAILS IN CI (`${CI:-}` guard). The install-and-warn path stays for a
    developer's machine, where it is a convenience; in CI a stale pin must be a red build.
  - SPLIT THE CONCURRENCY GROUP BY `github.event_name`. VERIFIED against the Actions API that every
    scheduled run reports `head_branch=master`, identical to every push — so with workflow-level
    `cancel-in-progress: true` the next push cancelled the running nightly. A SECOND, INDEPENDENT
    reason the ledger never completed; fixing the flag alone would not have been enough.
  - A RED NIGHTLY MUST REACH A HUMAN — `if: failure()` opens/updates a dated GitHub issue via the
    built-in token (no new secret, safe in a public repo). Hard rule 2: an alert computed and never
    sent is worse than none, and this one was computed into the void 7 nights running.
  - `timeout-minutes` on both jobs (15 / 90) instead of GitHub's silent 360-minute default.
  - PIN THE CLASS BY EXECUTING the gate's own command in `test-ci-workflow.sh`, not by name-denylist:
    a denylist of one would not have caught this flag before it was known.
  TIMING, corrected with measurement — the "~13 min" in ci.yml and here was never measured against
  anything. A GitHub runner does the full 1285-test suite in **4 s** (run 32219789532, step
  05:32:27→05:32:31), so the 258-case ledger is ~20-30 min there. A ZTS DEBUG build (the dev machine)
  is ~31 s/case ≈ 2 h 10 m. The two differ by ~7x; do NOT read a local timing as the CI cost. No
  completed CI run has ever been timed, because there has never been one.

- [2026-08-19 16:50] FULL LEDGER, POST-FIX: **258/258 detected, 0 undetected** (exit 0). Run against
  the frozen tree of `210f853` on this machine; log snapshot at `var/claude/ledger-210f853.log`.
  This is the first COMPLETE ledger since 2026-08-13 — the seven nightly runs in between never
  reached case 1. It is also the evidence the fix actually restored the check rather than merely
  silencing its symptom: the baseline gate now passes AND every one of the 258 seeded breaks is
  still caught, so no sabotage case was quietly disarmed while the gate was being repaired. Measured
  wall-clock on the dev machine's ZTS DEBUG build, consistent with the ~31 s/case figure above.
- [2026-08-19 16:55] AGREED: **certification collapses to ONE MAXIMAL round at the milestone
  boundary** instead of a panel per commit. Round 1 (17 findings) is closed; round 2 was launched
  against frozen `21ba5b1` and all three lenses died on a session limit. Rather than re-spend the
  budget certifying a CI fix, the developer ruled that TDD is the gate for the rest of this session
  — failing test first, executed output as evidence — and the panel runs once, MAXIMAL, against a
  frozen commit when milestone 1 closes. This is CLAUDE.md's own rule ("milestone boundaries always
  get MAXIMAL, against a frozen commit"), not an exemption from it. What it costs, stated plainly:
  the three CI commits (`210f853`, `e700051`, `21ba5b1`) carry ONE clean panel round, not two, so
  they are NOT MAXIMAL-certified until that boundary round covers them.
- [2026-08-19 17:10] AGREED: `--watch` paces by HOST via a new `Source::host(): ?string`, not by
  source. Q37's wording is "requests to distinct hosts" / "the same host", and two sources on one
  landlord's domain paced independently would each open their own 60 s window — precisely the ban
  risk the ruling exists to prevent. `null` means the source makes no outbound web request and is
  therefore unpaced (`FixtureSource`, `EmailAlertSource` — IMAP is one mailbox, not a scrape target).
- [2026-08-19 17:10] AGREED: `--watch` stops on SIGINT/SIGTERM via a pcntl handler that sets a flag
  and lets the CURRENT PASS FINISH, then exits 0. A signal landing between a notification send and
  the seen-set write would re-notify everything on the next start, which is the same user-visible
  damage as deleting the database. Bounded `--max-passes` was rejected as not actually a watcher.
- [2026-08-19 17:45] `scout run --watch` IMPLEMENTED (commit 7bf77b1), closing the last unwritten
  piece of milestone 1 that was not blocked on an external input. Three design rulings, each argued
  in the file it applies to rather than here, so they cannot drift from the code:
    - **`Core/Pacer` owns the cadence, with clock, sleeper and RNG all injected.** A pacer that
      called `time()` and `sleep()` could not be tested at all — asserting a 15-minute interval
      would take 15 minutes, so in practice nobody would, and Q37 would ship unverified. `PacerTest`
      asserts the whole ruling in microseconds. `hrtime()` not `microtime()`, so an NTP step cannot
      make an elapsed interval look negative and collapse a gap to zero.
    - **`Adapters/PacedSource` is a decorator, not pacing inside `Pipeline`.** `Pipeline` also
      serves `--once`, which has no cadence; a timing-aware pipeline would need a "do not actually
      pace" mode, and a safety control with an off switch is the kind that ends up off.
    - **`Source::host(): ?string` added to the contract.** Q37 is worded in hosts. Pacing per source
      would give two adapters on one landlord's domain a private 60 s window each — the burst the
      ruling exists to prevent. There is deliberately no default implementation: a new adapter must
      answer honestly, because `null` silently opts a source out of the entire ruling.
  The property `Cli/WatchLoop` exists for is NOT the cadence but surviving a bad pass. A watcher
  that exits on the first `SourceError` is hard rule 2's silent failure with every source inside it,
  and *more* invisible, because no process is left to report anything. `Exception` is reported and
  survived; `Error` propagates, because a `TypeError` is a bug here, not a flaky landlord.
- [2026-08-19 17:45] AGREED: stopping is deliberate, never immediate. SIGINT/SIGTERM set a flag and
  the pass in flight finishes. A signal landing between a notification send and the seen-set write
  would re-notify every listing already sent on the next start — the same user-visible damage as
  deleting the database, arriving as a burst of duplicates for flats already seen. The inter-pass
  wait is up to 20 minutes and is therefore served in 1 s slices: `docker stop` and systemd send
  SIGTERM, wait, then SIGKILL, so a loop checking its flag only after sleeping would be killed
  uncleanly every time. Without `ext-pcntl` the loop still runs and SAYS SO on startup, rather than
  letting the operator discover it from a duplicate-notification storm after the first `docker stop`.
- [2026-08-19 17:45] AGREED: `tests/sabotage-check.sh` gains a `SABOTAGE_FILTER` regex over labels,
  so a newly-added case can be proven red on its own instead of behind a two-hour full run — which
  is the practice already used on 2026-08-12, just without a mechanism. It prints a loud
  `PARTIAL RUN … NOT a ledger result` line whenever it is set, because a filtered run mistaken for a
  full one would be the ledger lying about its own coverage: the same defect class as the baseline
  gate that reddened itself for six days. CI never sets it.
- [2026-08-19 19:40] AGREED: the 20 Q37 pacing sabotage cases are verified red (`SABOTAGE_FILTER`
  mini-run, 20/20 detected, 0 undetected). The first run of them was 15/19 — the case count grew by
  one afterwards, see below — and every one of the four failures was worth the run:
    - **Two were no-op `sed` expressions** — a BRE does not honour `{2}`, and `\\\\` matches TWO
      literal backslashes where the PHP source has one. Both are the failure the harness's own
      `cmp -s` no-op guard exists to catch; without that guard they would have printed `ok` while
      testing nothing at all.
    - **Two were REAL holes in the tests, not in the harness.** `PacedSource::wrapAll()` — the
      documented, only-intended way to build these — had no test whatsoever, so handing each source
      a private pacer went undetected: every source paces itself perfectly while the machine as a
      whole fires one unthrottled request per source. And the pacer recording its INTENDED wake time
      instead of re-reading the clock was invisible because every fake sleeper delivered exactly
      what it was asked for; `WatchLoop::interruptibleSleeper` genuinely returns early on a signal,
      so the two come apart in production and not only under sabotage.
    - A fifth was found on the re-run: a hostless source claiming the distinct-host slot is
      unobservable when every request is issued at the same instant, because it overwrites the slot
      with the value already in it. Both existing tests did exactly that. The bug needs wall-clock
      time to pass — which, in a pass that reads a mailbox, it does.
  Net: 4 new tests, and one sabotage label corrected — "the decorator waits AFTER the request" was
  in fact deleting the pacing call outright, so it now has its own case and the reordering one
  actually reorders. 278 cases total — count them with `grep -c '^run_sabotage '` and note the
  TRAILING SPACE: without it the pattern also matches the `run_sabotage() {` definition and reports
  one case that does not exist, which is a phantom for anyone reconciling this figure against a
  ledger run's own total.

- [2026-08-19 20:05] AGREED: the remaining-work estimate is persisted in this file (§ "Remaining
  work — estimate") rather than answered in chat, because it is exactly the kind of answer a compact
  destroys. Three percentages are recorded rather than one, because they disagree honestly: ~80% of
  the code is written, ~65% counting the phorj port, and ~0% of the product is usable, since every
  source in `config/sources.json` is disabled behind a `REMPLACER` URL. Re-derive the figures rather
  than trusting them once a real source lands.
- [2026-08-19 22:40] AGREED: the Q36 flood guard reads whether anything has ever been RECORDED
  (`Store::isSeenSetEmpty()`), not whether `Store::open()` created the file. The old fact was
  destroyed by any command that merely opened the database, and `scout doctor` opens it — so typing
  the one command a new machine invites you to type let the next `scout run --once` notify the whole
  back catalogue (92 listings on In'li). `Store::wasCreated()` is deleted rather than kept alongside:
  emptiness is a strict superset, and two guards reading two facts is how one of them rots unnoticed.
- [2026-08-19 22:40] AGREED: Q36's second half — a mount marker file in `SCOUT_DB`'s directory —
  is WITHDRAWN rather than implemented, because it cannot fire. Inside the volume it disappears with
  the database it was meant to outlive; outside it, it lives in the image layer and resets on the
  container recreation where the failure happens. The empty seen-set covers the same case.
- [2026-08-19 22:55] AGREED: `scout run --watch` honours `SCOUT_MAX_PASSES`, and
  `tests/sabotage-check.sh` runs every case under `timeout` (default 300 s, `SABOTAGE_SUITE_TIMEOUT`)
  and counts a suite that never finished as a FAILURE. Found by this change rather than reasoned
  about: sabotaging the Q36 guard let a CLI test that expects a REFUSAL enter the real 15-minute
  watch loop, and the ledger sat on its first case for eleven minutes printing nothing. A gate that
  stalls silently is worse than one that reports a failure. No sabotage case covers the bound itself
  — removing it produces a hang, which the timeout reports as inconclusive, which is the honest
  verdict rather than a detection.

- [2026-08-19 23:02] AGREED: cross-portal price history is keyed on a LOGICAL LISTING, and the
  per-source rows are kept. A `listings.group_key` (schema v4) ties the members of a dedup cluster
  together; `price_history` rows stay attached to the `dedup_key` of the source that observed them
  and derive their group by JOIN, never by a second copy of the column that a later union could
  leave stale. Rejected: one merged timeline per group, because a portal quoting hors charges then
  manufactures a phantom drop and a late merge has to rewrite history recorded under two keys; and
  a read-only join with no stored group, because a flat whose surviving portal changes loses its
  history at exactly the seam worth notifying on.
- [2026-08-19 23:02] AGREED: notification stays keyed on `dedup_key`, NOT on the group. A
  group-scoped `wasNotified()` would permanently and silently suppress the second listing once two
  members stop clustering (a rent moving out of tolerance). `Core/Dedup`'s own asymmetry doctrine
  rules that direction out: under-merge notifies twice, which is visible and self-correcting;
  over-merge hides a flat, which is silent. The group is a HISTORY concept only.
- [2026-08-19 23:02] AGREED: a persisted union is PERMANENT — today an over-merge lasts only while
  both listings are published. Accepted because the blast radius is confined to one presentation
  view: per-source rows, per-source `priceHistory()` and per-source drop detection are untouched, so
  an over-merged group misreports a merged timeline and nothing else.
- [2026-08-19 23:02] AGREED: `listings.group_key` ships IN schema v4 rather than waiting for a v5.
  Grouping needs no geo — `Core/Dedup::cluster()` already computes it from rent/surface tolerance
  plus corroborating facts — so the deferral's only real argument was a dependency that does not
  exist. One migration is written and tested once instead of a v3→v4→v5 chain written twice.
- [2026-08-19 23:02] PROVENANCE, recorded because the record was briefly wrong: the three entries
  above were first written at 22:39 by a session that had posted the question and then stalled,
  stamped `[23:10]` — a time that had not yet happened — and marked AGREED before any answer could
  have arrived. They are re-recorded here at their true time, and they stand because the developer
  ruled this design at 23:02, not because the earlier stamp said so. A Decisions Log entry is a
  record of a ruling, never a session's own recommendation wearing the word AGREED.
- [2026-08-20 04:01] AGREED: a sabotage case whose sed also disables the seam a test relies on is
  REWRITTEN to be surgical, never left to hang and never counted. Applied to
  `the loop stops mid-pass instead of finishing the pass in flight`, which blanked the whole
  post-pass condition and took `SCOUT_MAX_PASSES` out with it. A `timeout` verdict is
  inconclusive, and an inconclusive case in the gate that certifies §1 must not be allowed to
  persist as a permanent FAIL line.
- [2026-08-20 04:01] AGREED: when a ledger case goes stale because the symbol it pins has been
  renamed, it is REMOVED if a case written against the current code already covers the guarantee,
  and re-pointed only if none does. Applied to the old `wasCreated()` Q36 case, whose three
  `isSeenSetEmpty()` successors cover both halves of the ruling.

- [2026-08-22 09:40] AGREED: the Q27 beat's health figure counts the sources the RUN WATCHES, and
  DISCLOSES the scope whenever a `--source` narrows it. Found by running the real container, not by
  review: `--watch --source=fixture_demo` against a five-source config reported *"1/5 source(s) en
  bon état"*, four faults that did not exist, in the one channel whose value is that it can be
  believed — and it would have worsened, since an unpolled source goes `STALE` and the beat would
  then alarm daily about sources nobody asked it to watch. Counting fewer is only half: silently
  reporting `1/1` lets a deployment with a forgotten flag look flawless while landlords go
  unwatched, so both counts travel with the figure. The banner already states the count and is a log
  line read once; the beat is what reaches the phone.
- [2026-08-22 09:40] AGREED: a guarantee reached only through a closure needs a test that REACHES
  that call site, and for the in-loop beat the way in is an unwritable marker. The fixed clock makes
  the day-two beat unreachable by construction — the startup beat writes the marker at `NOW` and
  every later check asks `isDue(NOW, NOW)` — which is what makes *"exactly one beat"* assertable and
  also meant no test ever executed the loop's own call. The new argument was not in the closure's
  `use` list, so the first genuinely due beat would have thrown a `TypeError` and killed the watcher
  24 hours into an unattended run. `beat()` writes the marker with `@file_put_contents` so a full
  volume cannot crash a liveness signal, and `lastHeartbeat()` reads `is_file()` — so a DIRECTORY
  where the file goes makes every check due, and two beats is then the correct result per the
  documented one-too-many bias.
- [2026-08-22 09:40] NOTED: a mutation can pass while testing less than it names. `the beat counts
  CONFIGURED sources again` reverted the loop's source but not the denominator, and the first
  version of the test pinned only the denominator — so the case reported UNDETECTED and was RIGHT.
  The fix was to the TEST, not the case: seed unscoped so the excluded source is HEALTHY in the
  store before the scoped run, which turns the mutated output into a nonsensical `2/1`. An
  excluded-but-unhealthy source coincidentally produces the correct `1/1`, and a test that cannot
  tell those apart is not testing the numerator at all.
- [2026-08-22 09:40] AGREED: `.env.example` is checked mechanically against `getenv()` and against
  `compose.yaml`'s `${VAR}` substitutions — drift-scan S8, in CI on every push. Two live defects
  prompted it: `IMAP_PORT` declared twice (env_file takes the LAST occurrence, measured on a scratch
  stack, so editing the key where it belongs silently does nothing), and `RW_UID`/`RW_GID` read by
  compose while its own comment told the operator to set them in a file that never listed them. A
  compose substitution has no `getenv()` to find it by, which is why the check runs in both
  directions. All four sub-checks were proven red by sabotage and restored byte-identical.
- [2026-08-22 09:40] NOTED: the CI ledger has been OBSERVED end to end — run 22, 22 min 09 s, green,
  315 cases, i.e. 4.2 s/case against ~60 s/case here. The local:CI ratio is ~14x, not the ~7x
  recorded before, because the suite gained interpreter-bound sweeps and a ZTS debug build is
  punished hardest there. Two readings this corrected in one session: a local ledger is a ~5 h job
  and belongs in a pinned worktree, and a ledger failing well INSIDE its budget has found something
  rather than timed out.

- [2026-08-22 15:10] AGREED: **a green ledger CLOSES the issue a red one opened.** Hard rule 2 says
  an alert computed and never sent is worse than none; this is its twin — an alert nobody retracts
  becomes furniture, and the next real red lands on a board that already reads RED. Observed:
  issues #1 and #2 stood open for days after the regression they reported had been fixed and
  pushed, and there was no `success()` path in `ci.yml` at all, so they could never have closed by
  themselves. The step closes **every** open `sabotage ledger RED …` issue, not just the current
  day's — the run that finally goes green is exactly the one that should clear the backlog. It uses
  `listForRepo` rather than the `search` call the failure path uses, because the search index is
  eventually consistent and an alert that fails to close is the defect being fixed. Reverses if a
  human wants to keep triaging red nights by hand: delete the step.

- [2026-08-22 15:10] NOTED: **the failure-notice step had no self-test either**, on the one step in
  this workflow that has already failed into the void (red 7/7, 2026-08-13 → 2026-08-19, notifying
  nobody). It could have been deleted the following day with every check in
  `tests/test-ci-workflow.sh` still passing. Both halves are now pinned by step NAME and by the API
  call that does the work — a name alone is defeated by gutting the body. The placement is pinned
  positionally too: in the fast job the retraction would fire on every green push and close a
  legitimately-open issue while the nightly ledger was still red, because the fast job never runs
  the ledger.

- [2026-08-22 15:10] NOTED: the remaining-work estimate is re-measured and **superseded, not
  edited** — the 2026-08-19 table is kept unedited above its replacement. Its `product usable ~0%`
  verdict survives re-measurement and its REASON does not: it was "zero live sources", and it is
  now "four live sources, `notify.channels` is `["console"]` with no `.env`, and today's live yield
  is 0 matches because the four sources' stock does not intersect the ten communes in
  `criteria.json`". Editing the percentage in place would have preserved a true number attached to
  a false explanation, which is the worse of the two errors. The bottleneck is **delivery**, not
  discovery; a fifth institutional source does not move it.

- [2026-08-22 16:20] AGREED: **region mode.** `communes: []` means the name is not checked and
  `postcode_prefixes` is the entire location filter. Developer-ruled after being shown the
  measurement, and it widens Q1 rather than reversing it — location is still a hard filter. The
  measurement: 474 live listings across four sources, **457 rejected on location**, 60 of them
  sitting inside 78/95 in communes nobody had listed (Saint-Germain-en-Laye alone, 13). Writing a
  departement out commune by commune was the only way to express this before, and anything not
  thought of was invisible. `commune_rank` still orders results, so the Boucle de Seine became a
  preference instead of a silent rejection. Reverses by putting the ten names back — they are in
  `criteria.json`'s own `_communes` note.

- [2026-08-22 16:20] AGREED: **`min_rooms: 3`**, reversing Q3's T4. Measured, not preferential: of
  the 13 listings that got past the location filter, **10 were rejected by this filter alone** and
  every one was under the rent ceiling (9 In'li LLI at 1017–1353 € CC, one CDC 82 m² at 1669 €).
  T4-or-larger at ≤1800 € CC did not exist across the four live sources that day. Q3's own open
  question was *"is a large T3 ever acceptable?"*; the live data answered it. `min_surface_m2: 75`
  is unchanged and is what stops this becoming a firehose. **Together the two changes take the live
  yield from 0 to 8 matches.**

- [2026-08-22 16:20] NOTED: **region mode is the first LOOSENING this config has taken, so it is
  guarded from both sides.** The loader REFUSES `communes` and `postcode_prefixes` both being empty
  — the single shape of these two keys that widens to everything, reachable by an ordinary edit, and
  invisible once made because over-matching looks like a busy market rather than a broken filter.
  And an unknown postcode is REFUSED in region mode while it stays forgiven in list mode. That
  inversion is not a hard-rule-9 violation but what hard rule 9 actually says: in list mode the NAME
  has already matched and the postcode merely narrows a decision made on real evidence, so unknown
  takes nothing away; in region mode the postcode is the only evidence there is, and forgiving it
  would admit every listing anywhere that failed to state one.

- [2026-08-22 16:20] NOTED: **region mode caused a regression that no test of region mode would have
  found, and an unrelated suite caught it.** `Criteria::communeLabels` is not only for `reasons[]` —
  it is the vocabulary `EmailAlertSource` scans an alert body with, because a commune is the one
  field an alert reliably carries and Q32 makes missing location a rejection. Built from `communes`
  alone it was empty in region mode, so every listing arriving by email would have lost its commune:
  no S1 score, nothing to name in the notification, a weaker dedup key — while still matching on its
  postcode, so nothing would have looked wrong. Ranked communes now feed the vocabulary too, which
  is a no-op in list mode (a rank is already required to be in `communes`). The fix was verified by
  the ORIGINAL failing test going green **unmodified**, which is the only evidence that separates a
  cause fix from a test edit. An alert for an unranked commune still resolves by postcode prefix; it
  just cannot be named. Recognising arbitrary French place names means geocoding, deliberately out
  of scope.

- [2026-08-22 16:20] AGREED: **channel choice lives in the gitignored `criteria.local.json`, not in
  the committed `criteria.json`.** `console` stays shipped; `ntfy` and `email` are enabled locally.
  Committing `ntfy` would make the shipped config attempt a push in CI, in the test suite and on any
  fresh clone with no `.env` — and the topic is a secret, which has no business in a tracked file.
  The reason this is safe rather than a silent loss: `compose.yaml` mounts `./config` read-only into
  the container, so a local override DOES reach the deployment. Both channels verified end to end —
  the ntfy message was read back off ntfy.sh by polling the topic, and the `.eml` was read from
  `var/outbox`. Email runs `SMTP_TRANSPORT=file` until real SMTP credentials exist, which produces a
  readable message rather than pretending to send.

- [2026-08-22 16:20] NOTED, and **FIXED 2026-08-22 20:40 — see the entry below**: nothing loads `.env`.

  ORIGINAL NOTE, kept because the reasoning in it turned out to be wrong in the way that matters: The code reads `getenv()`,
  and only Compose's `env_file:` populates it — so on the host `.env` is inert and a channel
  configured there is simply disabled. It fails LOUDLY (`⚠ canal ntfy désactivé : NTFY_TOPIC is not
  set`), which is why this is a note and not a defect, but the host and the container behave
  differently for the same file. Workaround on the host: `set -a; . ./.env; set +a`.

- [2026-08-22 16:20] NOTED (latent, not fixed): **In'li's field map has no `title`** — 174/174 live
  listings carry a blank one. Two consequences: `exclude_title_patterns` can never fire on the
  flagship source, so a parking ad would have to be caught by rooms or surface, and hard rule 9 says
  an unknown measurement is not a disqualification. Theoretical today — all 19 card links in the
  frozen payload are `/location-appartement-…` — and the notification headline is synthesised from
  commune, rooms and surface, so it reads correctly. Worth a `title` mapping the day In'li lists
  anything that is not a flat.

- [2026-08-22 19:05] FIXED (a shipped defect, found by running the real pipeline rather than by
  reading it): **`fixture_demo` shipped `enabled: true`, so a real deployment polled a demo source.**
  A full pass reported *"5 source(s), 491 annonce(s) · 14 correspondance(s)"* — and 10 of those
  listings and **6 of those matches are fabricated**, from `tests/fixtures/fixture_demo/search.json`.
  The block's own `_comment` records the fence and why it is spent: it was enabled *because* no real
  endpoint existed and it was the only way `scout run --once` could be demonstrated end to end. Four
  real sources have been live since 2026-08-22; nobody revisited the flag.

  **The severity is narrower than it first looks, and the narrow version is the one to record.** The
  first framing was *"6 fake flats would reach the phone on the first run"*. Traced against the
  documented flow, that is false: `run --once --seed` marks all ten as seen, `--watch` notifies only
  what is NEW, and a frozen payload is never new again — so under the prescribed sequence nothing
  fake ever pushes. What it actually costs is **every number the operator reads**: pass totals,
  `doctor`'s table, `SourceHealth`, and Q27's *"N/M sources en bon état"* beat, permanently, plus a
  real fake-push on any path that loses the seen-set (a replaced volume, a restored backup, a first
  run where `--seed` was skipped). Same class as the *"0 matches because of the commune filter"*
  correction on 2026-08-22: a true observation attached to an overstated cause stops people looking.

- [2026-08-22 19:05] AGREED: **`--source=<name>` FORCE-RUNS a disabled source, and only an explicit
  name does.** Disabling `fixture_demo` broke 10 CLI tests, because `ScoutTest`'s harness appends
  `--source=fixture_demo` to every `run`/`doctor` call and `sources()` dropped disabled definitions
  *before* consulting `--source`. The alternative — rewriting those 10 to build temp roots — was
  rejected: they exercise the run loop against the real repo root on purpose.

  It is a repair, not a convenience. `/add-source` step 5 prescribes *"run `scout doctor` against the
  new block, flip `enabled: true` once it is green"*, **and that order was impossible**: nothing ran
  a disabled source, so the only way to get a run was to enable it first — the edit to committed
  config the flag exists to avoid. `dump` has always resolved a source by name regardless of
  `enabled`, so this makes the verbs agree rather than inventing a rule.

  Three guards, each with its own test and its own sabotage case, because this is a loosening:
  **an ordinary run still skips disabled sources** (the over-correction is deleting the check
  outright); **the force-run is LOUD** — `source X est désactivée dans la config, exécutée parce
  qu'elle est nommée explicitement` — because a `--source` left behind in a deployment would
  otherwise be indistinguishable from a source somebody enabled deliberately; and **hard rule 1
  moved with it**. That last one matters: the loader refuses `enabled: true` beside a `REMPLACER`
  placeholder, and that was the entire guard for as long as a disabled source could never run.
  Force-running brings the placeholder back within reach of a real fetch, so the refusal now lives in
  `Scout::buildSource()` — the single funnel `dump`, `doctor` and `run` all pass through — rather
  than in the caller that happened to need it first. The scraping opt-in gate (hard rule 4) sits
  below the enabled check and is asserted to still fire on a force-run private portal.

- [2026-08-22 19:40] ON HOLD (developer instruction): **real SMTP is deferred; email stays on the
  `file` transport.** Turning it on needs a Gmail app password, which needs 2-step verification —
  minutes of the developer's time, on their account, for a SECOND channel when ntfy is already live
  and verified. Deferred rather than dropped, and the deferral is written into `README.md`
  § "Notifications — turning a channel on" rather than left in a chat log, because a credential
  step that lives only in a conversation is a step nobody can find later. `SMTP_TRANSPORT=file`
  keeps writing complete, readable `.eml` files to `var/outbox`, so the path stays exercised and the
  day the credentials arrive the only change is three `.env` lines. Reversed by filling in
  `SMTP_HOST`/`SMTP_USER`/`SMTP_PASSWORD` and setting `SMTP_TRANSPORT=smtp`.

- [2026-08-22 20:40] FIXED, and the earlier note UNDERSTATED it: **`bin/scout` now parses `.env`
  itself** (`src/php/Config/DotEnv.php`). The 16:20 note called this latent and not a defect,
  because the failure is loud — a channel says it is disabled. That reasoning held only while the
  workaround was assumed to work. It does not.

  `set -a; . ./.env; set +a` is not a parser, it is the SHELL. Observed within a minute of each
  other on a Gmail app password pasted with the spaces Google displays it with
  (`abcd efgh ijkl mnop`): bash read the line as a ONE-COMMAND environment prefix, so the variable
  was never exported and the CLI reported `SMTP_PASSWORD is empty` while the file plainly held one;
  and bash **executed** the remaining words, printing four characters of a live credential through
  `command not found`. A value containing backticks or `$(…)` would have run. So the host and the
  container disagreed about what a config file MEANT — Compose parses `env_file:`, the shell
  executes it — and the printed fragment was a symptom of that, not the defect.

  Four decisions inside the fix, each of which could have gone the other way:

  - **Loaded in `bin/scout`, not in `Scout`.** The PHPUnit suite constructs `Scout` directly against
    the real repo root, so a load inside the class would pull the developer's actual IMAP and SMTP
    credentials into every test run — a suite that mails somebody because it instantiated a CLI. The
    executable is the one place that is never a test subject. The cost is one untested line, which
    `tests/test-dotenv-cli.sh` covers by running the real executable in a throwaway root; removing
    the call was verified to turn that suite red, and the restore verified byte-identical.
  - **The real environment WINS over the file.** `SCOUT_DB=/tmp/throwaway bin/scout run` is how
    a live source is measured without touching the real seen-set, and Compose's `environment:`
    outranks its own `env_file:` the same way. A file that could override the environment would
    silently point a throwaway run at the real database, and the run would look completely normal.
  - **A malformed line is a startup refusal naming the LINE NUMBER and never the line.** This file
    holds the IMAP password, the SMTP password and the ntfy topic; `ConfigError` messages reach a
    terminal and, for `run`, `state/last-refusal.txt`. A parser that echoes what it could not parse
    leaks a credential the day someone fat-fingers one.
  - **Nothing is expanded and nothing is unescaped.** `${HOME}`, `$(id -u)` and `p@ss\word` are
    those exact characters. Turning `\w` into anything would corrupt a credential silently, which is
    this same defect arriving from the other direction.

- [2026-08-22 21:10] MEASURED, and it settles what "add more information to the notification" can
  cost: **what each live source actually puts on its CARD.** Read off the frozen payloads and the
  committed field maps, not inferred:

  | source | matches | title | floor | lift | description |
  |---|---:|---|---|---|---|
  | In'li | 54 | ✗ | ✗ | ✗ | ✗ |
  | CDC Habitat | 33 | ✓ | ✓ | ✓ | ✓ |
  | Cityloger | 0 | ✓ | ✓ | detail page | detail page |
  | Logirep | 5 | ✓ | ✗ | ✗ | ✗ |

  An In'li card's ENTIRE text content is `1 005 € cc 3 pièces · 55.32 m² Longjumeau`. Four facts.
  There is nothing further to map, so no field-map change can produce a floor, a lift, a
  description or a title for the source that supplies two thirds of the matches — only a second
  request can.

- [2026-08-22 21:10] NOTED (blocks the obvious fix, and nobody had noticed): **widening the region
  to all of Île-de-France made the detail-fetch gate nearly vacuous.** `detail_map` hydration is
  gated on `Criteria::matchesCommune()`, which was chosen because it is the only filter whose inputs
  the CARD carries in full (hard rule 8). That was cheap while the filter was ten communes —
  Cityloger hydrates 3 of 51. It is not cheap now: In'li's 174 listings are Île-de-France-wide, so a
  `detail_map` on In'li would issue **~170 requests per pass, every 15 minutes**. That is a crawl,
  not a poll, and hard rule 5 forbids it.

  **The way out is to gate on NOVELTY, not location**: hydrate a listing the first time it is ever
  seen and store the result. Steady state is a handful of new listings per pass. It does not weaken
  hard rule 8 — that rule is about not rejecting on a field enrichment would have filled, and this
  changes WHEN a page is fetched, not WHICH listings are judged on what.

- [2026-08-22 21:10] AGREED (developer choice, `AskUserQuestion`): **richer listing details ship in
  TWO phases.** Phase 1 is what is already extracted and merely never displayed — no new request, no
  new parsing. Phase 2 is novelty-gated hydration, which is what unlocks title, floor, lift,
  description and kitchen type on the sources that put none of it on the card.

  Splitting them is not just delivery cadence: phase 2 changes request VOLUME while phase 1 changes
  only display, and landing both together would mean a source polling harder had two suspects.

- [2026-08-22 21:10] SHIPPED (phase 1): **the notification carries the postcode, the departement,
  the floor and the lift.** The headline is now `82/100 — Sartrouville 78500 · T4 88 m² · 1450 € CC`
  and a first reason line reads `Yvelines (78) · 2e étage · avec ascenseur`. `Core/Department` is
  the lookup — Île-de-France only, because those eight prefixes are what the criteria admit, and an
  unknown postcode returns `null` so the line is omitted rather than guessed.

  Every rule on that line is hard rule 9 at the display layer, and each has its own sabotage case:
  `floor === 0` is **RDC and real** (read as falsy it vanishes, which is the display twin of
  rejecting a listing for not stating a floor); an **unmentioned** lift is not an absent one, so
  `null` says nothing while `false` says *sans ascenseur*; and the postcode is in the headline
  because a commune name alone is ambiguous — three Île-de-France departements have a Neuilly — and
  on In'li, which ships no title, the headline is the ONLY text in the notification.

- [2026-08-22 21:10] MEASURED, on a report of parkings reaching the list: **none of the 92 notified
  rows is a parking.** Every one is a flat. But the report pointed at something real: In'li ships no
  title at all, and `exclude_title_patterns` matches on the TITLE — so that filter is structurally
  dead on the source that produces two thirds of the matches. Nothing slipped through because In'li
  happens to list only flats and every card link reads `/location-appartement-…`; that is luck, not
  a filter working. Phase 2 closes it, because a detail page is where In'li's title lives.

## Formal plan — schema v4, the `group_key` history overlay (2026-08-19 23:02)

Ruled above. Two holes were found while planning that the ruling's own doctrine settles, and one
must-check that changed a decision. All three are recorded here because none is visible in the diff.

**The ruling assumed a fact that was not true.** "The per-source rows are kept" — they are not.
`Cli/Pipeline.php:99` clusters BEFORE recording and the loop that follows iterates survivors only, so
a duplicate member never reaches `Store::record()` and has no row at all. A `group_key` column on top
of that describes only groups of one. Recording every harvested listing is therefore part of the
ruled design, not an enlargement of it — and "every harvested listing" and "every cluster member" are
the same set, so there is no third option.

- **Hole 1 — survivor churn.** `Dedup::cluster()` keeps the FIRST item as survivor and ordering is
  the caller's; `Core/Pacer` shuffles source order every pass. So `group_key = survivor's dedup_key`
  churns between passes, and a member that delists in between keeps a key nobody else carries — the
  exact orphaning the ruling rejected the read-only join for. The key is therefore **sticky**: on
  assign, adopt the first existing non-NULL `group_key` among the members; mint one only when no
  member has one; when members arrive carrying two different keys, MERGE them. Never cleared — a
  persisted union is permanent, which is already ruled.
- **Hole 2 — `--seed` must mark every member.** The seed contract is "everything currently published
  is already seen AND already told about". A duplicate member is currently published. Today it has no
  row so the gap cannot be observed; once it has one, the first pass whose shuffle flips survivorship
  notifies it. `seedOnly` marks all members, not just the survivor.
- **Must-check, and it reversed a choice: members are CLASSIFIED at record time.** `Store::staleVerdicts()`
  (`Store.php:872`) selects `tenure IS NULL`, and its docblock pins that value to one meaning: "stored
  before schema v3, deliberately not backfilled". Recording members unclassified would give NULL a
  second meaning and silently enlarge what `scout reclassify` re-announces. Classifying at record time
  keeps NULL's v3 meaning exact.

**Not implemented, recorded instead:** once members are classified, the store can see that one
portal's text for a flat says PLS while the survivor's says LLI — a §1 signal today's code
structurally cannot observe, because only the survivor is classified. The notify gate consulting only
the survivor's verdict is current behaviour and stays. A member-veto is a §1 STRENGTHENING candidate
and goes to `docs/OPEN-QUESTIONS.md` with its default, per repo convention.

Shape:

- `Core/Dedup::cluster()` gains `members: list<RawListing>` (survivor first). ADDITIVE — `duplicates`
  is untouched because the formatter reads it. The `duplicates` strings are NOT parsed back into keys:
  they are `sourceName:externalId`, which collides for every listing whose `externalId` is empty.
- `Store` schema v4: nullable `listings.group_key` + index, migration step re-runnable like v2/v3,
  NOT backfilled. New `assignGroup()`, `groupKey()`, `groupPriceHistory()`.
- `groupPriceHistory()` branches on a NULL group explicitly: `WHERE group_key = (SELECT group_key …)`
  returns the EMPTY set for a singleton, because NULL never equals NULL. A singleton returns its own
  history, and that is a named test case rather than a comment.
- `Cli/Pipeline`: record + classify every harvested listing once, memoised by dedup key; cluster;
  assign groups; the survivor loop reuses the memoised sighting so nothing is recorded twice.

Tests, in the store's named categories: **persistence** (v3→v4 upgrade, re-runnable, newer refused),
**identity** (survivor-flip continuity across two passes in opposite order; a delisted member keeps
its group; divergent keys merge), **rent events** (singleton `groupPriceHistory` = own history),
**seen-set** (an over-merged group cannot suppress a notification; `--seed` marks every member).
`Store/` changes, so the sabotage ledger runs — `SABOTAGE_FILTER` on the new cases first, full ledger
before done.

### Two behaviour changes v4 makes that are easy to mistake for bugs

Both are deliberate, both are in the visible-and-self-correcting direction, and neither is obvious
from the diff — so they are written here rather than left for a later session to "fix".

- **A malformed listing now aborts the pass instead of being silently absorbed.** `Store::dedupKey()`
  refuses a listing with no id, no URL and no title (an adapter bug — hard rule 3). That was already
  fatal when such a listing was a cluster SURVIVOR; it was invisible when the listing happened to be
  absorbed as a duplicate, because duplicates were never recorded. Now every member is recorded, so
  both cases are loud. That is consistent, and it is the direction hard rule 3 wants — but it does
  mean a pass can now fail on an input that previously passed.
- **A delivered digest marks only the survivor**, so a later pass whose shuffle flips survivorship can
  digest the same flat again. This is the same trade the MATCH path makes, and — corrected 2026-08-24 — it was NOT "already documented" anywhere: `git grep` over the whole tree found that phrase citing only itself, with no comment at `Pipeline.php`'s match-marking site and no test asserting the behaviour either way. Both paths are pinned now (`testADeliveredMatchMarksOnlyTheSurvivorNeverTheWholeCluster` and its digest twin), which is what the citation was standing in for. It must not
  be "fixed" by marking members notified on delivery: that is group-scoped suppression, and an
  over-merge would then hide a real flat permanently and silently. `--seed` marks every member and is
  not an exception to this — seeding is about listings that are currently published, not about groups.

**Full ledger: COUNTED — 294 detected, 2 undetected.** Run against `15c3303` (clean tree), started
2026-08-19 ~23:0x and finished 2026-08-20 01:23; log at `var/claude/ledger-15c3303.log`. Neither of
the two is a hole in schema v4 — all seven v4 cases are `ok` — and neither is a hole in the code at
all. Both are defects in the LEDGER, and both are now fixed:

1. **`run stops refusing on a freshly created database (Q36 …)` — reported `sabotage was a no-op —
   the pattern no longer matches`.** Its sed targeted `$store->wasCreated()`, which commit `08541e2`
   replaced with `$store->isSeenSetEmpty()` the same day. The guarantee is not uncovered: three cases
   written against the current code already cover both halves of the ruling (`the empty-database
   guard stops firing`, `--seed no longer gets past the empty-database guard`, `the seen-set
   emptiness answer comes from the process, not the rows`), and a filtered re-run has them 3/3 red.
   The stale case is therefore **removed**, not re-pointed — two cases for one guarantee is not twice
   the coverage, it is one case nobody notices has gone stale. This is the SECOND case in two days to
   go stale by pinning a symbol that then changed (the first was `SCHEMA_VERSION = 3`), and the
   harness caught both by refusing to count a sabotage that changes nothing.

2. **`the loop stops mid-pass instead of finishing the pass in flight` — reported
   `the suite did not terminate within 300s — inconclusive`.** Root-caused rather than retried. The
   sed blanked the whole post-pass condition to `if (false)`, which removes the `$maxPasses` bound
   along with the `$this->stopping` check — and that bound is the ONLY thing keeping `ScoutTest`'s
   bounded `--watch` tests out of a real `Pacer::betweenPasses()` wait of 600–1200 s. Measured on a
   sabotaged scratch copy: `ScoutTest` alone went from **3.7 s to 88 s** and still passed, and the
   single test `testTheWatchLoopIsBoundedSoABrokenGuardFailsRatherThanHanging` did not finish inside
   120 s. So the case was measuring the harness's own anti-hang seam, not the guarantee. The sed is
   now **surgical** — it degrades only `$this->stopping`, leaving the bound standing — and the suite
   goes red in **16 s** on `WatchLoopTest::testStopDoesNotSleepBeforeExiting` ("exiting must be
   prompt, not after a fifteen-minute nap"), with the 600.0 s sleep printed in the failure diff.

   The general lesson, which applies to every future case: **a sabotage that also disables the seam a
   test relies on is measuring the seam.** `Q37`'s pacing constants are real seconds, so any case that
   can reach `betweenPasses()` in a test must leave `SCOUT_MAX_PASSES` able to do its job.

Case count is now **295** (296 minus the stale Q36 duplicate). The expected next full result is
**295 detected, 0 undetected**; the two fixes above are each verified by a filtered run (1/1 and 3/3
red), which is evidence for those cases and explicitly not a ledger result.

**Confirming ledger: COUNTED — 295 detected, 0 undetected.** Run against the frozen `945b485`,
started 2026-08-20 ~04:0x and finished 05:44 (~1 h 40 m), log at `var/claude/ledger-945b485.log`
[Verified: `grep -c` on the log gives 295 `ok` lines and 0 `undetected)` lines; the run's own closing
line reads `295 sabotage(s) detected, 0 undetected`]. The prediction the previous entry recorded is
therefore confirmed by measurement rather than by inference, and the schema-v4 ledger is now clean
end to end on the current tree — 295/295, no stale case and no timeout.

Worth keeping in view: the obligation was written into this file precisely because the run outlived
the session that launched it, and it was discharged by the session that came after. A predicted count
left in a committed plan is indistinguishable from a measured one to every future reader, which is
the same failure shape as the stale sabotage case above — a record that was true when written and
silently stopped being checked.

**Case count is now 303, not 295** — eight cases were added on 2026-08-20 with CDC Habitat: the
`_text` re-route (twice, from both ends), the config's own `tenure_field`, `RDC` as floor zero, the
mapper's floor wiring, per-page robots, the `page_path`/query-param fallback, and the missing
`{page}` placeholder. All eight were verified individually by a `SABOTAGE_FILTER` run going 8/8 —
and two of them came back UNDETECTED on the first attempt, which is how the two real holes fixed in
`e9079b0` were found: a counterweight asserting "not MATCH" that silence also satisfies, and a field
map with no fixture test behind it.

**Full ledger vs `7461e01`: COUNTED — 303 detected, 0 undetected. Its own closing line said 295, and
its exit status was a lie.** Started 2026-08-20 ~18:5x against that frozen commit, finished 21:08,
logged to `var/claude/ledger-7461e01.log`. Every one of the 303 cases ran and every one was detected
[Verified: `grep -c 'suite went red, as it must'` → 303; ANSI-aware `FAIL` count → 0; `ABORT` → 0.
Six lines matching an `undetected|failure` grep were checked individually and are all case LABELS
containing the word "failure"]. So the substance of the run is sound and it is recorded as the
ledger result for that tree.

**What was not sound is the harness, and it had been broken since the eight CDC cases were added.**
Those cases were appended to the END of `tests/sabotage-check.sh` — below the tally `printf` *and*
below the trailing `[[ $fail -eq 0 ]]` that supplies the script's exit status. Both are POSITIONAL,
and `sabotage-check.sh` runs under `set -uo pipefail` with no `-e`, so:

- the tally counted only the 295 cases above it, while 303 ran — the log shows eight `ok` lines
  printed *after* the closing summary;
- and every `run_sabotage` returns 0, so the last one became the script's exit status. **A FAIL
  anywhere in the ledger — including the 295 counted ones — exited 0.** The nightly job could not go
  red, and the GitHub issue it opens on failure could not be opened. That is the same defect class as
  the baseline gate that reddened itself for six days, inverted: this one could only ever be green.

[Verified 2026-08-20 by probe: a deliberately unmatched pattern appended to the end printed
`FAIL … (sabotage was a no-op)` and the script exited **0**; the same case placed above the tally
after the fix printed `0 sabotage(s) detected, 1 undetected`, named itself under "undetected or
unapplied", and exited **1**.]

**Fixed:** the eight cases moved above the tally, and the trailing expression replaced with an
explicit `if (( fail > 0 )); then exit 1; fi` / `exit 0`, so anything appended below is dead code
rather than live-and-uncounted. Two structural checks in `tests/test-ci-workflow.sh` now pin both —
no `run_sabotage` may appear after the tally line, and the script must end on an explicit `exit`.
They were written first and went red on the unfixed file [Verified: `15 passed, 2 failed`], then
green. A filtered run on a moved case now reports `1 sabotage(s) detected … skipped 302`, where it
previously reported `0 … skipped 295` — that number moving is the proof the case is inside the
count.

**Full ledger vs `e4e3ef0`: COUNTED — 303 detected, 0 undetected, and this time the tally says so.**
Run in a pinned `git worktree` at that commit (not the live tree, so source #3 could be built beside
it without changing what each case tests), finished 2026-08-21 01:3x. Its closing line reads
`303 sabotage(s) detected, 0 undetected` where the same tree previously reported 295 — that number
is the fix, visible in the artefact. Zero FAIL lines, zero ABORT.

**Superseded launch note, kept for the record:** A filtered run is explicitly NOT a ledger
result, and the 303/0 above was produced by a harness whose exit status could not fail — the
per-case lines are trustworthy, the aggregate machinery was not. Re-run against that frozen commit,
logging to `var/claude/ledger-e4e3ef0.log`; ~2 h, so it outlives the session that started it. Nightly CI is now a second, independent path to the same number — and, for the first time since
2026-08-20, one that can actually report failure.

**Case count is now 315, not 303** — twelve added on 2026-08-21 with Cityloger: the detail gate, the
no-gate refusal, a failed detail fetch becoming an unhydrated listing, the detail map taking the card
path (so `_text` becomes the whole page), two merge rules from hard rule 9, detail re-identification,
per-detail-page robots, detail pacing, the `{page}` url template in two directions, and a detail map
redefining `ref`. Three were written first as MULTI-LINE seds and came back `sabotage was a no-op` —
GNU sed matches within a line — so they were rewritten line-scoped, two of them with a
`/private function withDetail/,$` range because the search-page robots check and the page walk's
`usleep` are textually identical to the detail ones. The filtered verification then went **9/12**, and all three
failures were holes in the TESTS rather than defects in the cases — the same thing the CDC cases
found on 2026-08-20. `mergedWith()` had no direct unit test at all: its guarantees were exercised
only through `HtmlSource`, which injects the CARD's ref into the detail listing before merging, so a
merge preferring the detail's identity behaved identically and the suite stayed green. A guarantee
observable only through a caller that neutralises it is assumed, not tested. Fixed in `0309936`
(`tests/php/Core/RawListingMergeTest.php`, plus two ConfigTest cases), and the three re-run
**3/3 detected**.

**Full ledger vs `c1cf190`: COUNTED 2026-08-21 10:31 — 315 detected, 0 undetected.** Exactly the
prediction recorded below before the run finished, which is the only reason the prediction is worth
anything. It ran to completion in the pinned `git worktree` at that commit (so the session's later
work could not change what the cases tested halfway through), logging to
`var/claude/ledger-c1cf190.log`. Four things were checked rather than assumed, because a ledger can
report a number without having measured one: the log opens with `baseline: suite is green` (a red
baseline makes every subsequent RED meaningless and the harness aborts on it); `grep -c 'suite went
red'` returns **315**, matching the tally line rather than merely accompanying it; there is no
`PARTIAL RUN` line, so this is a full ledger and not a `SABOTAGE_FILTER` subset; and no case timed
out — `SABOTAGE_SUITE_TIMEOUT` counts a hang as a FAILURE precisely so a suite that never finished
cannot be read as a detection. This is the first full ledger for this tree produced by a harness
whose **exit status can actually fail** (the positional-tally and positional-exit defects were fixed
in `e4e3ef0`, and `tests/test-ci-workflow.sh` now pins both structurally).

The prediction as recorded before the run, kept unedited: **315 detected, 0 undetected** — 303
carried over from `e4e3ef0` plus the twelve Cityloger cases, each already verified individually.

**Worktree pruned** the same turn: `git worktree remove --force <scratch>/ledger-c1cf190`. It was
registered in `.git/worktrees` pointing into session scratch, so it outlived its session by design
and needed removing by hand once the count was written here.

**Full ledger vs `fd8f3b5`: COUNTED 2026-08-22 — 325 detected, 0 undetected.** Ten cases on from
`c1cf190`'s 315: seven added with the Q27 heartbeat and the Logirep build, three with the heartbeat
scope fix (`the beat counts CONFIGURED sources again`, `the beat stops disclosing that it is
scoped`, `the in-loop beat loses an argument to the closure boundary`). Log:
`var/claude/ledger-fd8f3b5.log`. The same four integrity checks, each re-run rather than assumed:
the log opens with `baseline: suite is green`; `grep -c 'suite went red'` returns **325**, matching
the tally rather than merely accompanying it; there is no `PARTIAL RUN` line, so this is a full
ledger and not a `SABOTAGE_FILTER` subset; and no case timed out. Worktree pruned the same turn.

**This run had to be started twice, and the reason is worth keeping.** The first attempt, pinned at
`f15b7a3`, recorded a case as *"the suite did not terminate within 300s — inconclusive"*. Nothing
was wrong with the tree: five PHPUnit processes of my own were running alongside it, and
`SABOTAGE_SUITE_TIMEOUT` correctly counted the starved case as a FAILURE rather than a detection.
That is the guard behaving exactly as designed — but a contaminated ledger cannot be repaired case
by case, because the contention was not confined to the case that reported it. It was killed, the
worktree removed, a fresh one pinned at `fd8f3b5`, and the machine left quiet. **A ledger run owns
the machine for its duration**; the cost of forgetting that is the whole ~5 h, not one case.

- [2026-08-25 15:40] AGREED (robots posture, amending the 2026-08-21 status table): **a 2xx parses
  only if it LOOKS like a robots file.** A single-page application serves its `index.html` for every
  unmatched path, so `/robots.txt` answers `200 text/html` with an app shell — which parses to zero
  directives and therefore read as *allow everything*. The whole fail-closed posture was defeated by
  a 200. Measured on `al-in.fr` [2026-08-25]: Angular shell, `Content-Type: text/html;charset=UTF-8`,
  HTTP 200; that host is the one remaining route to the Action Logement stock, so it is not a
  hypothetical. Two independent signals, because neither is sufficient alone — a markup **content
  type** (compared on the type alone, before the `;`, so `text/plain; charset=utf-8` still parses),
  and a **first byte** of `<` or `{`, which no robots.txt can begin with and which an app shell and
  a JSON error handler both do. **An absent `Content-Type` still parses** (absence is not evidence,
  and the body sniff covers the markup case) and **an empty body still parses** (a zero-length
  robots.txt is legitimate and means nothing is restricted). It lands on the *unreachable* side
  rather than the *absent* side because a 404 is knowledge — we asked and established no file exists
  — while a 200 of markup establishes nothing. **Stated cost:** a site with genuinely no robots.txt
  behind an SPA or JSON catch-all now fails closed where RFC 9309 §2.3.1.3 would permit access. That
  is recoverable by one person reading the reason, which `Robots::refusal()` surfaces in `doctor`;
  the reverse — polling a site whose real rules we never read — is not. Verified against the four
  live sources first: In'li, CDC Habitat, Cityloger and Logirep all serve `text/plain`, so nothing
  in production changes.
- [2026-08-25 15:55] AGREED: `.claude/hooks/tenure-guard.sh` neutralises the literal `text/plain`
  before its patterns run. `plai` is a substring of `text/plain` and `allow` is a substring of
  `Disallow`, so ANY robots.txt test puts an "inclusion keyword" within eighty characters of a
  "social tenure" with neither word present — `RobotsResolverTest` tripped patterns 1 and 6 on
  nothing else. This is NOT the whole-blob suppression shape round 6 deleted: those skipped a check
  outright when an innocent word appeared anywhere in the file; this removes one IANA media type
  that cannot express a relaxation, and every pattern still runs over everything else. Two
  alternatives were measured and rejected — `\b` word boundaries pass 66/66 but lose the camelCase
  `$allowPlaiListings` form, and `plai([^n]|$)` broke two real detections outright.
  `tests/test-tenure-guard.sh` pins both halves at 70 cases.
