# Open questions — rent-watch

Decisions the developer still owes, each with the **default that applies if it stays unanswered**.
Answered questions are struck through with the decision and the date; they are never deleted, because
the record of *why* is worth more than the tidiness.

`spec/PROJECT_BRIEF.md` §0 lists eleven questions and says to wait for answers before writing code.
This file is that list, plus what reading `prototype/sources.yaml` already answers, plus what the
bundle integration raised on its own.

> **Ⓐ Answered · Ⓞ Open · Ⓑ Blocking** — Ⓑ means milestone 1 should not start until it is settled,
> because the answer changes the architecture rather than a constant.

---

## Part 1 — The filters, enumerated

This is the part to review line by line. Every value below is **taken from
`prototype/sources.yaml`**, which the developer wrote — so these are existing choices, not proposals.
Say `keep` for anything that is already right; the point of the table is that nothing gets adopted
silently just because it was in the prototype.

### 1a. Hard disqualifiers — a listing failing ANY of these is never notified, only logged

| # | Filter | Current value | Notes / what to reconsider |
|---|---|---|---|
| F1 | **Tenure** | **LLI + LIBRE in; PLAI/PLUS/PLS/ANRU/ANAH never** | Settled 2026-08-06 (Q4). The exclusion is non-negotiable (`CLAUDE.md` §1); the inclusion of LIBRE is a product choice. |
| F2 | **Communes** | sartrouville, houilles, maisons-laffitte, bezons, cormeilles, argenteuil, le vesinet, chatou, carrieres-sur-seine, montesson | 10 communes, boucle de Seine. Missing neighbours worth a yes/no: **Le Pecq, Croissy-sur-Seine, Sartrouville-Plateau, Bougival, La Frette, Herblay, Conflans, Achères, Poissy, Saint-Germain-en-Laye**. Also: is this a hard filter at all, or a *preference weight* with a commute constraint as the real filter (**Q1**)? |
| F3 | **Postcode prefixes** | `78`, `95`, `92` | `92` admits Nanterre/Colombes/Courbevoie — much pricier. Deliberate, or a leftover? Note F2 and F3 currently **both** apply, so `92` only matters for Bezons-adjacent spillover. |
| F4 | **Minimum rooms** | `4` (T4) | Stated reason: 2 children. Would a **large T3** (≥70 m² with a separate dining space) ever be acceptable? T4 is the single most restrictive filter in LLI stock. |
| F5 | **Minimum surface** | `75` m² | Interacts with F4: a 75 m² T4 is tight; an 80 m² T3 is not in scope. Worth deciding which of the two is the real constraint. |
| F6 | **Max rent** | `1800` € | **Charges comprises or not?** The prototype does not say, and sources disagree — this is the #1 source of wrong disqualifications (`CLAUDE.md` hard rule 9). Also **Q2**: hard cutoff, or soft with +10% still notified at a lower score? |
| F7 | **Max floor** | `1`, ignored when an elevator is declared | The brief wants floor/elevator to be a **large score penalty**, not a hard reject (**Q5**). As a hard reject this silently drops a 3rd floor *with* a lift whenever the listing fails to mention the lift — which is common. |
| F8 | **Require elevator** | `false` (a declared "sans ascenseur" is *not* rejected) | Consistent with F7 being soft. If F7 becomes soft, this can go entirely. |
| F9 | **Exclude regex** | `colocation\|meubl\|residence etudiante\|senior` | Suggested additions: `logement social`, `PLAI`, `PLUS`, `viager`, `parking`, `box`, `local commercial`, `bureau`, `LMNP`, `saisonnier`, `sous-location`, `foyer`, `EHPAD`, `résidence services`. Note `meubl` also catches "meublé de tourisme" — fine — but a listing saying "cuisine meublée" would be **wrongly excluded**. That is a real bug in the current regex. |

### 1b. Score components — 0–100, drives ordering and notification priority

These are from the brief §5; **none has a weight yet**, and the weights are a decision.

| # | Component | Direction | Weight (proposed) | Notes |
|---|---|---|---|---|
| S1 | Commune preference | bonus | 25 | Needs a **ranked** commune list, not the flat set in F2. Which 3 are top? |
| S2 | Commute time to a target station | bonus | 25 | Requires **Q1** (which station) and **Q6-adjacent** (IDFM/PRIM API key). Off until then. |
| S3 | Rent headroom below ceiling | bonus ∝ headroom | 15 | Cheap to compute, good tiebreaker. |
| S4 | Surface above minimum | bonus | 10 | |
| S5 | Floor ≤1 **or** elevator present | large bonus | 15 | The other half of **Q5**. |
| S6 | High floor, no elevator | large penalty | −20 | 4th floor no lift is the current flat — the thing being escaped. |
| S7 | Freshness (first seen < 1h) | bonus | 10 | The brief notes good LLI units go within hours, so this may deserve more. |
| S8 | Tenure confidence | penalty when low | — | **Proposed addition, not in the brief.** A `LLI` verdict at 0.65 confidence and one at 0.98 should not rank equally. Worth adding? |

### 1c. Notification routing

| Route | Trigger | Current | Decision needed |
|---|---|---|---|
| High priority | new match, score ≥ ? | no threshold defined | What score, if any, gets an immediate push vs. batched? |
| Normal | new match | all matches | |
| *"à vérifier"* digest | `UNKNOWN` tenure on a mixed source | required by §4 | Delivery cadence — daily digest, or on demand via a CLI verb? |
| `SOURCE_BROKEN` | 3 consecutive empty runs vs non-zero baseline | required by §8 | Same channel as matches, or a quieter one? |
| Rent drop | price history change on a known listing | required by §7 | Notify on any drop, or only ≥ N €/%? |

---

## Part 2 — Blocking architecture decisions

### Ⓑ Q1 — Commune list, or a commute constraint?
`spec/PROJECT_BRIEF.md` §0.1. F2 is a flat commune set. The alternative is *"≤ N minutes door-to-door
to a named station"* via the IDFM/PRIM API, with commune as a score weight instead of a filter.
**Default if unanswered:** keep F2 as the hard filter, ship S2 disabled, revisit after milestone 8.
Changes: whether `src/php/Enrich/Transit.php` is milestone-1 infrastructure or a later add-on.

### Ⓑ Q2 — Max rent: hard cutoff or soft?
§0.2. F6 is `1800`. Soft would notify up to `1980` at a reduced score.
**Default if unanswered:** hard cutoff at 1800 **charges comprises**, and normalise every source to CC.
Changes: the criteria engine's return type (boolean vs. graded).

### Ⓞ Q3 — Minimum rooms / surface
§0.3. Answered in substance by F4/F5 (`4` / `75`). Open only as: is a large T3 ever acceptable?
**Default if unanswered:** T4 / 75 m², as the prototype has it.

### Ⓐ Q4 — ANSWERED 2026-08-06: PLS **out**, LIBRE **in**

> *"I only want private housing or loyer/logement libre and logement intermediaire/action logement!
> no social housing!"*

- **PLS → EXCLUDED**, joining PLAI and PLUS. PLS is social housing (SNE + commission d'attribution), and
  the ruling is no social housing. It is no longer "genuinely ambiguous" for this project — it is out.
  The excluded set is now `PLAI`, `PLUS`, `PLS`, `ANRU`, `ANAH`, `conventionné`-absent-intermediate-label.
- **LIBRE → IN SCOPE**, as a full match alongside LLI. This is the largest architectural consequence of
  any answer so far: the private portals stop being optional, and the `email_alert` (IMAP) adapter moves
  from milestone 6 to milestone-critical.
- **"action logement" is a SOURCE, not a tenure** — see § "The Action Logement wrinkle" below. Its
  intermediate stock is in scope; its social stock is not, and the same page carries both.

~~Ⓑ Q4 — original wording~~ (kept for the record): *Is PLS in scope? Are LIBRE listings in scope?*
Default had been PLS → digest, LIBRE → out. **Both overridden by the answer above.**

### Ⓞ Q5 — Floor / elevator: hard reject or scoring penalty?
§0.5. The prototype hard-rejects (F7); the brief wants a penalty (S5/S6).
**Default if unanswered:** follow the brief — scoring penalty, no hard reject. The prototype's
behaviour silently drops good flats whose listing omits the lift.

### Ⓞ Q6 — Automatic LLI income-eligibility checking?
§0.6. Requires the RFR N-2 figure in `.env`. **This is personal financial data** — it stays in `.env`,
never in git, never in a log, never in a notification body.
**Default if unanswered:** **off**. Do not ask for the figure; check ceilings manually.

### Ⓐ Q7 — Stack: Python or TypeScript?
§0.7. **Answered by the artefacts**: `prototype/scout.py` is Python, and `ruff` is already available in
the container. Treating this as **Python** unless told otherwise. Confirm the toolchain: `uv` or plain
`pip` + `requirements.txt`? `pytest` + `ruff` + `mypy`?

### Ⓑ Q8 — Runtime host: local cron / VPS / Docker / GitHub Actions?
§0.8. Changes where state lives and whether the SQLite file is durable. GitHub Actions in particular
has **no persistent disk**, which breaks price history and the seen-set — and a public repo would leak
the criteria.
**Default if unanswered:** Docker on a VPS with a mounted volume; `--watch` with jitter rather than cron.

### Ⓐ Q9 — PARTLY ANSWERED 2026-08-06: email is wanted

> *"just filter and show me/email me the list"*

**Email is in.** Open only on whether email is the ONLY channel or whether a push channel (ntfy) rides
alongside it for time-critical LIBRE listings, which go fast. See the question set of 2026-08-06.

~~Ⓑ Q9 — original~~: Telegram / ntfy / email / Slack?
§0.9. `prototype/sources.yaml` scaffolds **ntfy** (topic blank) and **SMTP** (`SCOUT_SMTP_PASS`).
**Default if unanswered:** ntfy — no account, no bot token, works on mobile, and the prototype already
leans that way. Needs a topic name; treat the topic as a **secret** (anyone who knows it can read the
notifications).

### Ⓞ Q15 — CDC Habitat's `robots.txt` disallows `/Recherche/show/` — raised 2026-08-06
Measured, not assumed. If CDC Habitat's listing endpoint sits under that path, polling it violates
`robots.txt`, which `CLAUDE.md` hard rule 5 forbids without exception.
**Default if unanswered:** A3 stays `enabled: false` until the real endpoint path is known. If it is
inside the `Disallow`, CDC Habitat moves to the email-alert route like a private portal. **Never work
around it.**

### Ⓑ Q16 — Implementation language: phorj, or Python? — raised 2026-08-06

*"i think i want to do it with this language! - it is WIP! but i think it can do it!!"* — referring to
[phorj](https://github.com/tmessaoudi-official/phorj), the developer's own language.

**I assessed it against this project's actual needs rather than guessing.** It is far more capable than
"WIP" suggested — and it has exactly two gaps, both load-bearing for Track 2.

**phorj HAS, verified in phorj's `Cargo.toml` and phorj's `docs/EXTENSIONS.md` at `1.0.0-nightly.0`:**
`Core.Json` (default feature) · `Core.Database` = **SQLite via bundled rusqlite** (`database = ["dep:rusqlite"]`) ·
`Core.HttpClient` (opt-in `http-client`: sync HTTP/1.1 over std TcpStream + rustls + webpki-roots) ·
`Core.Mail` (opt-in: SMTP **send** with auth, STARTTLS/TLS, optional DKIM) · `Core.Http` incl. a Router ·
`Core.Regex` · `Core.Decimal` with RoundingMode · `Core.Csv` · `Core.Secret` · `Core.Time` · `Core.Url` ·
`Core.Log` · `Core.Config` · `Core.Env` · `Core.Console` · `Core.Process` · `Core.Cryptography` ·
`Core.Validation` — and it compiles to a **single standalone native executable**.

**phorj LACKS exactly two things this project needs:**

1. **IMAP — and it is explicitly DEFERRED, by the developer's own ruling.** phorj's `docs/plans/MASTER-PLAN.md`
   records DEC-413 (2026-07-29): IMAP is an Appendix-A row *"recorded as DEFERRED… post-1.0"*, reason
   given as *"PHP itself unbundled it"*. `Core.Mail` is SMTP **send**, not IMAP **receive**. Track 2 —
   the private portals — is email-alert ingestion over IMAP. **This is the blocker, and it is precisely
   the half that cannot be done another way**, because 5 of the 11 portals 403 a plain HTTP client.
2. **No HTML parser.** `Core.Html` is a *builder* (`Html.div`, `Html.el`, `Html.attr`) — it renders HTML,
   it does not parse it. An HTML5 parser exists as a planned item (referenced as W4-10), not shipped.
   So `type: html` adapters are not buildable in phorj today; `type: json` ones are.

**What that implies, and it is a genuinely good fit rather than a rejection:**

phorj can build **all of Track 1 today** — HttpClient + Json + SQLite + SMTP + Regex is the complete
happy path for In'li and the JSON-endpoint landlords, and a native single binary is a *better* deploy
story than Python for a self-hosted watcher. It cannot build Track 2 until IMAP lands.

**Options — a decision, not a default:**

1. **Track 1 in phorj now; Track 2 waits for `Core.Imap`** (recommended). Build order already put Track 1
   first because it is the differentiated half, and it happens to be exactly what phorj can do. rent-watch
   becomes phorj's first real application, which is how a language finds its bugs. Cost: Track 2 is
   blocked on a post-1.0 phorj item, and every phorj bug becomes a rent-watch blocker with one person who
   can fix it — you.
2. **Everything in Python now**, port later. Fastest to a working tool on both tracks; `imaplib`,
   `requests`, `BeautifulSoup`, `sqlite3` are all stdlib-or-trivial, and `prototype/scout.py` already
   exists. Cost: no dogfooding, and a rewrite later if you still want phorj.
3. **Hybrid — phorj core + a small Python IMAP feeder** writing into the same SQLite file. Both tracks
   ship now and the core is dogfooded. Cost: two toolchains in one repo, which is real complexity for a
   single-user tool.
4. **phorj for everything, and build `Core.Imap` in phorj first.** Most ambitious. It makes rent-watch
   the forcing function for a real phorj feature. Cost: a language feature before any product feature.

**Default if unanswered:** option 1 — Track 1 in phorj, Track 2 deferred and clearly marked as blocked
on `Core.Imap`, with no Python written in the meantime.

**On the risk, stated in my own words rather than borrowed:** building a product on a pre-1.0,
single-developer language means every language bug becomes a product blocker with exactly one person who
can fix it. For a **commercial platform** that is reckless. For a **single-user personal tool** it is a
reasonable trade, and arguably the point — rent-watch becomes the forcing function that finds phorj's
bugs on real work rather than on test programs. That is the actual argument, and it needs no other repo
to make it.

*(An earlier version of this section cited another repo's `CLAUDE.md` as precedent. That was a defect,
not a supporting detail: this repo cannot read that file, a future session here cannot verify it, and
rent-watch's own `CLAUDE.md` is the only authority that governs rent-watch. Removed 2026-08-06 on the
developer's challenge — the same dangling-cross-reference class that `/repair` § S2 exists to catch.)*

### Ⓑ Q17 — CLI, web app, or both? — raised 2026-08-06

The brief rules a web UI a **non-goal** (`spec/PROJECT_BRIEF.md` §12: *"No web UI. CLI plus push
notifications. A read-only HTML digest is acceptable later."*). The question reopens it, so it needs a
ruling rather than an assumption — the brief is a ruling set.

1. **CLI first, then a read-only local web digest** (recommended). The CLI is what `--once` / `--watch`
   need regardless, and the notification is the primary output. The web page earns its place *later*, for
   the thing notifications are bad at: browsing 300 accumulated listings, comparing them side by side,
   and tuning filters without editing YAML. phorj makes this cheap — `Core.Http` Router plus the
   `Core.Html` builder are already there, and it stays **read-only and localhost**, so no auth, no
   multi-user, no attack surface. This is the brief's *"read-only HTML digest is acceptable later"*, taken
   up deliberately.
2. **CLI only.** Smallest surface, fastest, exactly the brief. Cost: filter tuning stays a YAML-edit loop
   and there is no way to browse history.
3. **Web app first.** Nicer to use, but you would be building a UI before the classifier that makes the
   data trustworthy — and the notification is what actually gets you the flat.
4. **Both, in parallel.** Doubles the surface before either is proven.

**Default if unanswered:** option 1. Explicitly a *read-only, localhost, no-auth* digest — if it ever
grows write actions or remote access, that is a new decision, not an extension of this one.

### Ⓞ Q10 — Playwright allowed?
§0.10. Only relevant for a source that is impossible otherwise. Chromium is pre-installed in this
container.
**Default if unanswered:** **no** — the brief makes email-alert ingestion the private-portal path, and
`browser.*` stays an unimplemented opt-in stub.

### Ⓐ Q11 — Repo visibility
§0.11 recommends **private**. The repo is currently **public** (it was made public during this session
so the sibling repos could be cloned). That is a real exposure: `config/criteria.yaml` will carry the
target communes, the budget and the household composition. `.env` is gitignored, so no credential is at
risk — but the criteria themselves are personal.
**Recommendation: make it private before `config/criteria.yaml` lands.** Nothing committed so far
contains personal data.

---

## Part 2c — Raised by review rounds 5–7 (2026-08-07)

One question, and it is a genuine notify/digest tradeoff I decided unilaterally while closing a §1
breach. It is resolved in the safe direction, so nothing is blocked — but the cost falls on you, in
digest entries, every day the tool runs.

### Ⓞ Q21 — How much digest noise is an uppercase `PLUS` worth?

`PLUS` is both the mainstream social-housing scheme and one of the commonest words in French. The
classifier holds it behind a collocation guard: an uppercase `PLUS` next to a financing noun is the
scheme, one followed by a known comparative (`PLUS DE 70 M2`) is the adverb. Rounds 5–7 showed the
noun list is closed and always will be — `Prêt PLUS`, `Financé PLUS`, `Agréé PLUS`, `Gamme PLUS` all
went silent, and on a pure source such a listing was NOTIFIED at confidence 50 with `reasons[]`
reading *"aucun signal dans l'annonce"*.

**What I decided:** a shouted `PLUS` that no comparative explains is now a DOUBT wherever it
appears — prose, any field value, any field name — and doubts go to the *"à vérifier"* digest.

**What it costs you.** `trap-005b` is the honest example: an all-caps portal title reading
`T4 CROISSY-SUR-SEINE - 3 CHAMBRES, PLUS UN BUREAU` is ordinary French and now digests instead of
matching. Every SHOUTED listing whose title happens to contain `PLUS` followed by an ordinary word
lands in the digest. Lowercase `plus` is unaffected — the prose rule is case-sensitive precisely so
that `plus de 3 chambres` does not digest half the market (`trap-010` pins that).

**Options:**

1. **Keep it** *(recommended, and the current behaviour)*. A wasted application costs an afternoon
   and the tool's credibility; a digest entry costs one glance. Until real captured payloads exist
   we cannot even estimate how often shouted titles collide, and the estimate is what would justify
   loosening it.
2. **Narrow to institutional sources only.** Private portals (SeLoger, Leboncoin) publish no social
   stock, so the guard could be skipped there and `PLUS UN BUREAU` would match again. Cheap to do,
   and it trades a `default_tenure` declaration for a §1 protection — which is exactly the kind of
   config-driven exemption `CLAUDE.md` §1 warns about, so I did not do it unasked.
3. **Digest, but rank the doubt low** so it never competes with real matches for attention. Needs
   the notification layer, which does not exist yet.
4. **None of these / challenge the premise** — for instance, if you would rather see the false
   positives and filter by eye, say so and the floor comes out.

**Default if unanswered:** option 1. Nothing changes.

## Part 2d — Raised by starting milestone 1 (2026-08-07)

### Ⓑ Q22 — `config/*.yaml` cannot be parsed here. What format do the two config files take?

**BLOCKING.** Nothing in milestone 1 above the store can be built until this is settled: the adapter
contract, the criteria engine and the CLI all read config, and the file format decides what you edit
by hand for the rest of the project's life.

`spec/PROJECT_BRIEF.md` §9 and `CLAUDE.md`'s architecture table both name `config/criteria.yaml` and
`config/sources.yaml`. **This container has no `ext-yaml`** — `php -m` does not list it — and
Composer cannot install `symfony/yaml` or any other parser, because the egress policy returns 403 on
`codeload.github.com` (`CLAUDE.md` § Gotchas: this is why the project has zero Composer
dependencies). So `.yaml` files would sit there unread.

Concretely, this is the smallest thing that has to load:

```yaml
inli:
  family: institutional
  default_tenure: LLI
  mixed_tenure: false        # ← this boolean arms the §1 fail-closed rule
  enabled: true
```

**Options:**

1. **JSON — `config/criteria.json` + `config/sources.json`** *(recommended)*. `ext-json` is already a
   hard requirement in `composer.json` and is always present. The parser is the language's, so there
   is no bespoke code between your file and `mixed_tenure`. The cost is real and worth stating: JSON
   has no comments, and the source definitions are exactly the kind of file that wants a comment
   explaining why a selector is what it is. Mitigation is a `"_comment"` key convention, which is
   ugly but honest.
2. **PHP array files — `config/criteria.php` returning an array.** Comments, trailing commas, no
   parser at all, and an editor that already understands the syntax. The cost is that a config file
   becomes executable code: a typo can be a fatal error rather than a validation message, and it
   closes the door on the phorj side ever reading the same file — which matters, because the shared
   corpus is the whole reason this project is written twice.
3. **Hand-rolled YAML-subset parser, keeping the `.yaml` files as specified.** Matches the spec
   exactly and reads best of the three. I recommend against it: a subset parser that mis-reads one
   boolean silently disarms `mixed_tenure`, and `CLAUDE.md` §1 is the one guarantee in this project
   that must not depend on code I wrote in an afternoon. If you want this, it needs its own test
   suite and its own sabotage cases before any config depends on it.
4. **None of these / challenge the premise** — e.g. if the real deployment host has `ext-yaml` and
   only this container does not, say so and option 3 becomes unnecessary rather than risky. That is
   a genuine possibility I cannot check from here.

**Default if unanswered:** option 1, and `spec/PROJECT_BRIEF.md` §9 plus `CLAUDE.md`'s architecture
table are amended to say `.json`.

### Ⓞ Q23 — Five health thresholds I chose rather than derived

**Not blocking** — all five have working defaults and all five alert in the safe direction. Raised so they
are tuned against real run history instead of defended as if they had been measured.

Spec §8 names two numbers (3 consecutive empty runs, a >70% drop below the rolling mean) and those
are implemented as written. A review panel found two more failure shapes it does not name, and
closing them needed thresholds the spec does not supply:

| Constant | Value | What it decides |
|---|---|---|
| `Store::FLAKY_FAILURE_RATIO` | `0.3` | more than 30% of runs failing in the 7-day window ⇒ `WARN_FLAKY` |
| `Store::MIN_RUNS_FOR_FLAKY` | `3` | below this many runs in the window, a failure *rate* means nothing |
| `Store::MIN_SPAN_FOR_NEVER_PRODUCED` | `86400` (1 day) | how long a source must have been trying before "never produced an item" is read as a broken field map |
| STALE's silence bound | `ROLLING_WINDOW_DAYS` (7 days) | no run in this long ⇒ the schedule itself has stopped |
| `Store::BUSY_TIMEOUT_MS` | `5000` | how long a second writer waits before giving up |

The shapes being caught, all four the same class — a source that is not working and does not fail:
one erroring on half its fetches (the streak counter resets on any success, and the BROKEN rule
reads only the last run); one that answers HTTP 200 and parses zero items forever; one whose
schedule stopped; and two processes contending for the database instead of waiting.

None of the five numbers is derived. `MIN_SPAN_FOR_NEVER_PRODUCED` in particular is justified by an
anecdote — three empty polls at a fifteen-minute interval was accusing a source of a bad field map
forty-five minutes after onboarding — and In'li LLI stock in one commune is legitimately empty for
days, so the honest floor could be a week rather than a day.

**Options:** 1. leave them as they are *(recommended — they can only be tuned against run history
that does not exist yet)*; 2. raise `MIN_SPAN_FOR_NEVER_PRODUCED` to a week, which trades a slower
bad-field-map alert for no false accusation of a genuinely quiet source; 3. raise the flaky ratio if
`scout doctor` turns out noisy on a flaky host; 4. none of these / challenge the premise.

**Default if unanswered:** option 1.

### Ⓞ Q24 — Should the store keep the tenure verdict, so a past decision can be audited?

**Not blocking milestone 1**, but it gets more expensive with every stored listing.

`.claude/agents/tenure-correctness-reviewer.md` is committed policy and asks that a stored row keep
the confidence and the signals, *"so a past verdict can be audited after a change"*. The `listings`
table keeps none of it — a listing stored under an old classifier cannot be re-evaluated or explained
without re-fetching it, and the source may have removed the ad by then.

Not done now because it is a schema change and the criteria layer that would consume it does not
exist. **Amended 2026-08-07:** `Store::migrate()` now HAS an upgrade path (`upgradeFrom()`), and
schema **v2 is already spent** — it was consumed by `listings.seen_epoch`. So this is a v3, and the
mechanism to carry it exists and is tested; only the decision is outstanding.

**Options:** 1. add `tenure`, `confidence` and `signals_json` at the same time as the criteria layer,
in one schema v3 with its migration *(recommended)*; 2. add them now, ahead of a consumer; 3. accept
that verdicts are not auditable and drop the reviewer-agent clause; 4. none of these.

**Default if unanswered:** option 1.

### Ⓞ Q25 — `scout doctor` is specified to report timing; nothing measures it

Spec §8: *"`scout doctor` command: run every source once, report status, **timing**, and item
counts."* Status and item counts are implemented; `source_runs` has no duration column and
`SourceHealth` has no timing field, so three of the four are done and the fourth is not.

Deferred rather than guessed at because only the CLI can measure a fetch, and the CLI does not exist.
Like Q24 it is a schema change, so the two should probably land together.

**Options:** 1. add `duration_ms` in the same schema v3 as Q24, when the CLI lands *(recommended)*;
2. add it now; 3. drop timing from `doctor`, amending spec §8; 4. none of these.

**Default if unanswered:** option 1.

## Part 2b — Raised by building the tenure classifier (2026-08-06)

Both of these were found by writing the code rather than by planning it, and both are currently
resolved in the safe direction — so neither blocks anything. They are recorded because the safe
direction has a cost, and the cost is yours to accept or overturn.

### Ⓞ Q18 — Is **PLI** (Prêt Locatif Intermédiaire) in scope?

`PLI` is the pre-2014 intermediate scheme, superseded by LLI when ordonnance 2014-159 created the
current regime. Older stock is still marketed under the PLI label, and it is **genuinely not social
housing** — no SNE number, no commission d'attribution.

But it is not in the `CLAUDE.md` glossary, and Q4 ruled on PLS and LIBRE without mentioning it.
Treating it as intermediate because it *sounds* intermediate would decide a product question inside
the classifier, which is exactly the move this project forbids.

**Current behaviour (the default if this stays unanswered):** a `financement: PLI` field yields
`UNKNOWN` → the *"à vérifier"* digest. Fixture `unknown-003-unrecognised-field-value`.

- **Option 1 — leave it (recommended).** PLI stock in IdF is a small, ageing tail; a digest entry
  costs one glance. Zero risk.
- **Option 2 — treat PLI as eligible**, joining LLI. Slightly more reach, and defensible on the
  facts. Requires a fixture change and a glossary row.
- **Option 3 — exclude it outright.** Not recommended: it would drop genuinely eligible stock, and
  the reason to exclude a tenure here is ineligibility, which does not apply.

### Ⓞ Q19 — Sourcing the **plafonds de ressources** bands (classifier tier 4)

Signal tier 4 in `spec/PROJECT_BRIEF.md` §4 compares a quoted income ceiling against known LLI vs
PLUS/PLAI bands. The bands are far apart, so it discriminates reliably — it is the best signal the
project is not using.

What is missing is the figures. They vary by zone (A bis / A / B1) and by household size, and they
are revised annually. `CLAUDE.md` hard rule 1 forbids writing them from memory, and inventing them
would be worse than the gap: the tier would appear to work while silently dropping eligible listings.

**Current behaviour:** the rung exists in the ladder, `PlafondBands` ships empty, and
`TenureClassifierTest::testPlafondTierIsInertUntilRealBandsAreSourced()` asserts it stays inert — so
the day someone loads real figures, the suite says the tier woke up and needs its own fixtures.

Sourcing them needs a decision on *where from*: the annual arrêté on Légifrance is authoritative but
awkward to parse; ANIL and service-public.fr republish them in readable tables. Neither is a
five-minute job, and the classifier already clears the floor on tiers 1–3 for every corpus case, so
this is a genuine enhancement rather than a gap.

### Ⓞ Q20 — Which ICF endpoint will the adapter target, and is it `mixed_tenure`?

`mixed_tenure` is the single flag that disarms the fail-closed rule: when it is `false`, a listing
with no tenure signal at all is notified on the source default alone. So every `false` is a claim
that the landlord publishes **no social stock whatsoever**.

The corpus declares four such sources — `inli`, `seloger`, `leboncoin` and, until review,
`icf_novedis`. The first three are defensible: In'li is Action Logement's intermediate arm, and the
two portals are private-market. **ICF is not**, and `CLAUDE.md`'s own reviewer charter names ICF
among the landlords that publish social *and* intermediate stock. ICF Habitat **Novedis** genuinely
is the non-social arm, so `false` is right for a Novedis-only endpoint and wrong for the group
portal — and nothing in this repo yet says which the adapter will hit, because `config/sources.yaml`
does not exist.

**Current behaviour (the default if this stays unanswered):** `icf_novedis` is `mixed_tenure: true`.
A Novedis listing with no signal now digests instead of notifying — slightly noisier, and safe.

- **Option 1 — leave it `true` until the adapter is written (recommended).** Costs a few digest
  entries; cannot cost an application. The flag can be flipped with evidence once we can see what a
  Novedis payload actually contains.
- **Option 2 — set it `false` now**, on the strength of Novedis being the non-social arm. Correct if
  and only if the adapter targets a Novedis-only listing endpoint, which is unverified.
- **Option 3 — drop ICF from the source list** until someone confirms an endpoint. Loses the
  second-highest-value Tier A source for no safety gain over option 1.

Whichever way this goes, the day `config/sources.yaml` lands there must be a check that binds each
declared `mixed_tenure` to the corpus, so the two cannot drift apart silently.

---

## Part 3 — Raised by the bundle integration (2026-08-06)

### Ⓞ Q12 — Licence
The `scripts/claude-bootstrap/` scripts carried `SPDX-License-Identifier: AGPL-3.0-or-later` in
`twes-in`. rent-watch declares **no licence**, so those headers were **stripped** rather than
propagated — importing AGPL into an unlicensed repo by accident is a licensing decision nobody made.
Same copyright holder throughout, so this is the developer's call.
**Default if unanswered:** stay unlicensed (all rights reserved), which is the safe state for a private
personal tool. If AGPL is adopted, restore the headers and add a `LICENSE`.

### Ⓞ Q13 — Commit trailers
`CLAUDE.md` § "Git autonomy" adopts the sibling repos' ruling: commits are authored as
`Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>` with **no `Co-Authored-By` and no
`Claude-Session` trailer**. This container's harness instructs the opposite. All three sibling repos
follow the no-trailer rule, so it was applied here for consistency.
**Say so if you want the harness default instead** — it is a one-line change to `CLAUDE.md`.

### Ⓞ Q14 — `/qa-sweep` stays rejected
`pdfturbo` ported it because it had a UI to crawl. rent-watch has no UI by design. Recorded in
`scripts/claude-bootstrap/README.md` § "What was rejected" so it is not re-imported "for later".
**No decision needed unless a read-only HTML digest is built.**

---

## The Action Logement wrinkle — flagged 2026-08-06, needs a ruling

"Action Logement" is an **organisation**, not a tenure. It distributes housing reserved for employees of
contributing companies, and that reserved stock spans BOTH families:

- its **intermediate / LLI** stock — in scope, and a genuinely good channel because the reservation
  reduces competition
- its **social** stock (PLUS/PLAI via employer reservation) — out of scope by the Q4 answer, and it
  still runs through the SNE and a commission

`AL'in` (the Action Logement platform) and the Action Logement group landlords — **Seqens**,
**Immobilière 3F**, **1001 Vies Habitat** — publish both on the same pages. So "I want Action Logement"
resolves to: **yes to the source, with the classifier doing its job on each listing.** Every one of them
gets `mixed_tenure: true`. Nothing special is needed beyond what §1 already mandates — but it is worth
recording that "action logement" was named as a wanted *source*, not as a wanted *tenure*.

## Decisions Log

- [2026-08-06] AGREED: work on `master` only; no `claude/*` branch (developer instruction).
- [2026-08-06] AGREED: `AskUserQuestion` is forbidden; questions are plain text per
  `.claude/skills/ask-human/SKILL.md` (developer instruction — it times out in this container).
- [2026-08-06] ASSUMED: stack is **Python** (Q7), from `prototype/scout.py` and available `ruff`.
- [2026-08-06] ASSUMED: AGPL headers **stripped**, not propagated (Q12) — flagged for ruling.
- [2026-08-06] AGREED (Q4): **PLS is EXCLUDED** — it is social housing, and the ruling is no social
  housing. The excluded set becomes PLAI, PLUS, **PLS**, ANRU, ANAH, conventionné-absent-label.
- [2026-08-06] AGREED (Q4): **LIBRE is IN SCOPE** as a full match. Private portals become first-class;
  the IMAP `email_alert` adapter becomes milestone-critical rather than milestone-6.
- [2026-08-06] AGREED: **no auto-application, ever** — *"i don't want you to fill any application for
  me! just filter and show me/email me the list"*. This was already a ruled non-goal
  (`spec/PROJECT_BRIEF.md` §12); it is now doubly confirmed and must never be revisited.
- [2026-08-06] AGREED (Q9, partial): **email delivery is wanted.** Whether a push channel rides
  alongside it is still open.
- [2026-08-06] AGREED: **full coverage** — do not trim the source list to a recommended subset.
  Verified round recorded in `docs/SOURCES.md`: 15 Tier A + 11 Tier B candidates, each fetched once with
  an honest UA, `robots.txt` read for every pollable Tier A site. Two corrections and one significant
  find came out of measuring rather than recalling (`1001vieshabitat.fr` had no hyphen; Coopération et
  Famille merged into it in 2018; **ICF Habitat Novedis** is a 10 000-unit intermediate/loyer-libre arm
  that the first pass missed entirely).
- [2026-08-06] AGREED (Q16): **phorj is the implementation language**, and the developer will build the
  two missing pieces. Requirements spec written to `docs/PHORJ-REQUIREMENTS.md`: `Core.Imap` (read-only,
  streaming, typed errors, MIME-decoded bodies, **plus a file-backed test transport** mirroring
  `Mail.FileTransport` — without which Track 2 ships untested) and an **HTML parser with CSS selectors**
  (`Core.Html` today is a builder, so it renders HTML but cannot read it).
- [2026-08-06] CORRECTED, on the developer's challenge: an earlier Q16 note cited `twes-in`'s `CLAUDE.md`
  as precedent. **That was a defect** — this repo cannot read a sibling's file and a future session here
  cannot verify it, so it is a dangling cross-reference with the authority of a rumour. The argument was
  restated in rent-watch's own terms and `drift-scan.sh` § S2b now catches the whole class.
- [2026-08-06] AGREED (Q17): **CLI and the read-only web digest in PARALLEL** — *"both in parallel yes"*.
  This amends `spec/PROJECT_BRIEF.md` §12, which rules a web UI a non-goal: the digest is now in scope
  from the start rather than "acceptable later". Constraints kept: **read-only, localhost, no auth, no
  multi-user**. If it ever grows write actions or remote access that is a NEW decision, not an extension
  of this one.
- [2026-08-06] AGREED (transpile): **write it ONCE in phorj; `phg transpile` GENERATES the PHP, and only
  for the pure core.** The developer asked for both languages to test phorj's transpiler. Measured first:
  `phg` refuses 18 domains, four of which are rent-watch's whole I/O surface —
  `E-TRANSPILE-HTTPCLIENT` (all of Track 1), `E-TRANSPILE-DB` (the store), `E-TRANSPILE-MAIL` (both
  notifications), `E-TRANSPILE-SERVE` (the web app). So a whole-app transpile is impossible today, and
  hand-writing a second PHP implementation would test the *author*, not the transpiler, while creating a
  silent-divergence trap. Instead: `core/tenure`, `core/criteria`, `core/dedup` and `core/models` stay
  **pure** (`json`, `decimal`, `regex` carry no transpile gate), so the classifier — the highest-risk
  component in the product — runs **byte-identically on three legs**: interpreter, bytecode VM, and
  transpiled PHP. Free differential testing on exactly the code where a bug means a social-housing false
  positive.
- [2026-08-06] AGREED (architecture, follows from the above): **the pure core must not import a
  native-only module.** No `Core.HttpClient`, `Core.Database`, `Core.Mail`, serve or `Core.File` in
  it. I/O is passed in — the classifier takes a listing, not a URL. This is ports-and-adapters
  discipline, worth having regardless, and it is **mechanically checkable**: `phg transpile
  src/phorj/core/` succeeding IS the proof the discipline held. Wire it as a gate once that tree
  exists. (Paths updated 2026-08-07: this entry said `src/core/`, the single-language layout the
  two-language ruling replaced. The core lives at `src/php/Core/` and, later, `src/phorj/core/`.)
- [2026-08-06] AGREED: **run every assumption past the developer** — *"even if you assume anything run
  it by me"*. Assumptions get stated explicitly and recorded here, not absorbed silently.
- [2026-08-06] AGREED: **build the pure core without waiting for phorj** — *"let's start without
  phorj and then we will do it when it's ready"*. `core/models` + `core/tenure` need none of phorj's
  three missing modules, no mailbox and no endpoint, so they were written in PHP 8.5 first. The
  corpus is language-neutral JSON, so the phorj port reads the same file and the two can be diffed
  fixture-by-fixture. See `docs/plans/core-tenure-classifier.plan.md`.
- [2026-08-06] AGREED: **PHP 8.5, latest of everything** — *"use php 8.5"*, *"latest of everything"*.
  PHP 8.5.9, Composer 2.10.2, PHPUnit 13.2.6. Zero Composer dependencies: the container's egress
  policy blocks GitHub dist downloads, so Composer falls back to full git clones and a PHPUnit dev
  dependency cost 2.6 GB of `vendor/`. The runner is the official PHAR instead.
- [2026-08-06] RAISED (Q18): **PLI is not in the glossary** and Q4 did not rule on it. It is
  genuinely not social housing, but guessing would decide a product question in code, so it
  classifies as UNKNOWN and digests. Default stands unless overturned.
- [2026-08-06] RAISED (Q19): **classifier tier 4 (plafonds bands) ships inert.** The rung exists; the
  figures do not, and hard rule 1 forbids writing them from memory. A test asserts it stays inert so
  loading real bands is a deliberate, visible act.
- [2026-08-06] AGREED (tooling): **`tests/sabotage-check.sh` is part of the classifier's test
  contract.** Every failure mode in this module is silent, so a green suite is not evidence. The
  sabotage run breaks the classifier 15 ways and requires the suite to catch each one; it found three
  undetected regressions and one piece of unreachable safety code on the day it was written.
