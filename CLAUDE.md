# CLAUDE.md — rent-watch

> This file holds the RULES for how Claude delivers code here — quality, carefulness, gates, and the
> eligibility boundary that governs this whole project. The product itself (spec, decisions, open
> questions) lives in `spec/` and `docs/`. Boundary test before adding anything: *does Claude need this
> to deliver correct code?* If not, it belongs in `docs/`, not here.

rent-watch is a **self-hosted watcher for rental listings in Île-de-France**. It polls institutional
landlords, ingests private-portal alert emails over IMAP, classifies every listing by French housing
**tenure type**, filters and scores it against personal criteria, and pushes a notification within
minutes of publication. Single language, single user, single machine. CLI plus push notifications —
**no web UI**, by design.

The full specification is [`spec/PROJECT_BRIEF.md`](spec/PROJECT_BRIEF.md). It is the source of truth
for the product, and **every constraint in it is a ruling**, not a draft to be improved on. Read it
before touching anything under `src/`.

Status: **milestone 1 is functionally complete against a frozen payload.** The pure core, the store
(schema v3), the config layer, the adapter contract, the criteria engine, dedup, the notification
layer and the `scout` CLI all exist. What is missing is a NETWORK adapter, and that is blocked on an
input rather than a decision. As of 2026-08-07 there is a PHP 8.5
implementation of `models` + `tenure` under `src/php/Core/`, a 114-case language-neutral classifier
corpus at `tests/fixtures/tenure/corpus.json`, the seen-set / price-history / run-log store under
`src/php/Store/` with `SourceHealth` + `SourceStatus` in `Core/`, a strict JSON config layer under
`src/php/Config/` with both files committed, the `Source` contract plus `Payload` / `ListingMapper` /
`FixtureSource` under `src/php/Adapters/`, the criteria engine (`CriteriaEngine` + `Verdict`), and a
PHPUnit suite. `scout run --once` is demonstrable end to end today against a frozen payload.

`scout doctor`, `scout dump`, `scout run --once/--seed` and `scout test-notify` all work end to end
today. The network adapters exist too — `HttpJsonSource` + `Robots`, `EmailAlertSource` +
`ImapMailbox`/`FileMailbox`, `SmtpTransport`/`FileTransport` — all tested offline against fakes,
with `.env` swapping the real thing in. **What is missing is not code but two INPUTS**: a DevTools
cURL capture to replace a `REMPLACER` URL in `config/sources.json` (hard rule 1 forbids writing one
from memory), and the `plafonds` figures for classifier tier 4. CI now exists
(`.github/workflows/ci.yml`): the fast job runs the PHPUnit suite, the tenure tripwire and
runner-fetch self-tests, the drift scan, shell syntax and the bootstrap self-tests on every push and
PR; the sabotage ledger runs nightly and on demand. Still missing: the `--watch` loop (it refuses
rather than running unpaced — Q37) and the `html`-type adapter (needs a CSS-selector parser).
`src/phorj/` is not written yet — it waits on the three phorj builds in `docs/PHORJ-REQUIREMENTS.md`.

**The five sabotage gaps are closed as of 2026-08-12**, each verified individually by a targeted
mini-run going 7/7 red (the two fixed sed expressions included): honest User-Agent pinned;
SMTP-without-STARTTLS proven refused via a scripted loopback server and its wire transcript;
`SmtpTransport::secrets()` wiring proven by a server that echoes the base64 credential back; the
rent plausibility band exercised end to end; and a fifth found while closing them — the block-tag
test covered `</li>` while the sabotage degraded `</p>`; it now iterates the whole tag class. The
full-ledger count is recorded in `docs/plans/milestone-1-pipeline.plan.md` as each run completes.

Anything below describing `enrich` or a real landlord endpoint is the **target**, not
the present. Do not report findings against files that do not exist yet, and do not name `pytest` as
though it were wired — the PHP suite is the only test runner here.

**Every question is closed as of 2026-08-07** — see [`docs/OPEN-QUESTIONS.md`](docs/OPEN-QUESTIONS.md).
All 25 were resolved in one pass by applying each question's own documented default, on the
developer's instruction. **Nothing is blocking; milestone 1 proceeds.** A default applied is not a
preference expressed: every entry names the one line that reverses it.

Four things are still outstanding, and they are **inputs rather than decisions** — no default can
supply them: the DevTools cURL captures for the first sources (hard rule 1 forbids writing an
endpoint from memory), IMAP credentials for the alert mailbox, one real portal alert email to shape
the parser against, and the `plafonds de ressources` figures for classifier tier 4.

---

## ⛔ The one non-negotiable rule

> **`logement social` (PLAI, PLUS) must NEVER be surfaced as a match.**

This is not a config toggle bolted on at the end. It is a first-class domain concept with its own
module (`src/php/Core/TenureClassifier.php` + `Tenure.php`), its own test suite, and a
**fail-closed default**. It is an
**eligibility fact**, not a ranking preference: the user is not eligible, so a social-housing false
positive is not a slightly-wrong result — it is a wasted application and the reason a user stops
trusting the tool.

Concretely, when working in this repo:

- The excluded set — `PLAI`, `PLUS`, `PLS`, `ANRU`, `ANAH`, `conventionné` absent an explicit
  intermediate label — is **not user-overridable**. Do not add a config key, flag, env var or default that can
  re-enable them. Such a key is a P0 finding even if nothing currently sets it.
- If the classifier's confidence is `< 0.6` **and** the source is known to mix social and intermediate
  stock → tenure is `UNKNOWN`, and the listing goes to the low-priority *"à vérifier"* digest. It must
  **never** be emitted as a match.
- Bias every ambiguous decision toward *not notifying*. A missed listing is annoying; a
  social-housing false positive makes the tool untrustworthy, which is worse.
- Never weaken a classifier test to make a change pass. If a fixture goes red, the classifier
  regressed — fix the classifier, not the fixture. A skipped, xfailed, deleted or relabelled fixture
  is P0 unless the old label was demonstrably wrong and the evidence is in the commit message.
- `.claude/hooks/tenure-guard.sh` is a **tripwire on this rule, not a guarantee** — it greps, it does
  not reason. It runs PostToolUse and exits 2 when it fires, feeding its warning back into the turn.
  If it fires, stop and explain the change before continuing. A clean run proves nothing.

---

## Routing

Work here is handled with the **global reasoning framework** (`~/.claude/CLAUDE.md`) — the 8-phase
workflow, the four-dimension Completion Gate, evidence grades, the anti-bandaid gate. That framework
is the developer's own persistent install; this repo never writes it — the container-era
`scripts/claude-bootstrap/` reinstaller was removed 2026-08-18. On any conflict, **this file wins**.

Repo-native slash skills live in `.claude/skills/` and reviewer agents in `.claude/agents/`; both are
read in place, nothing is installed. `ls .claude/skills/` is the authoritative list — a count written
in prose drifts, so none is written here.

## Questions — `AskUserQuestion`, sparingly

Questions to the developer use the **`AskUserQuestion` tool**, per the global framework: options with
the recommended one FIRST (labelled, with its reason) and a visible *"none of these / challenge the
premise"* escape. Protocol details: `.claude/skills/ask-human/SKILL.md`.

> The container-era plain-text protocol and the `❓`/`⏹` end-of-reply markers are **RETIRED**
> (2026-08-18). They existed because `AskUserQuestion` timed out in the dead cloud container; on this
> machine it works, `askUserQuestionTimeout` is `"never"` globally, and the marker's rationale
> (a prose question being indistinguishable from a pause) dies with the prose protocol.

**Do not ask about routine work.** The standing directive for this repo is *no interrupts*: announce
the task size and the plan, then build it. Asking is reserved for the cases in
§ "When this protocol is mandatory" of that skill — chiefly a genuinely ambiguous request, a
user-visible product decision, or anything that would weaken an invariant below. **Never ask whether
to weaken the social-housing exclusion** — ask *how* to satisfy it.

Every unanswered question is also written to `docs/OPEN-QUESTIONS.md` with the default that applies if
it stays unanswered. A question asked only in chat is lost at the next session.

---

## Domain glossary — read this carefully

Tenure is a property of the **listing**, not of the **source**. In'li is pure LLI, but CDC Habitat,
Vilogia, Immobilière 3F and Seqens publish social *and* intermediate stock on the same pages, sometimes
in the same result set.

| Term | Meaning | In scope? |
|---|---|---|
| **LLI** — Logement Locatif Intermédiaire | Ordonnance 2014-159. Rent capped ~10–20% below market. Income ceilings exist but are far above social housing. Zones A bis / A / B1 only. Allocated **directly by the landlord** — no commission, no SNE number. | **YES — primary target** |
| **PLS** — Prêt Locatif Social | Highest tier of *social* financing. High ceilings, often marketed alongside intermediate stock. Was genuinely ambiguous; the Q4 answer settled it. | **NEVER** — ruled 2026-08-06 (Q4) |
| **PLUS** — Prêt Locatif à Usage Social | Mainstream social housing. Requires SNE registration (numéro unique), allocated by commission d'attribution. | **NEVER** |
| **PLAI** — Prêt Locatif Aidé d'Intégration | Very-low-income social housing. | **NEVER** |
| **LIBRE** | Private market rate, no cap, no income condition. SeLoger / Leboncoin / PAP / Bien'ici / agencies. | **YES** — ruled 2026-08-06 (Q4), a full match on its own track |
| **ANRU / ANAH / conventionné** | Various subsidised regimes. Treat as social unless explicitly labelled intermediate. | **NEVER** |

Classifier signal priority (highest → lowest confidence). A lower-priority signal must never override a
higher one:

1. **Explicit structured field** — `financement`, `typeProduit`, `categorie`. Worth real effort to find.
2. **Explicit label in text** — `logement intermédiaire`, `LLI`, `loyer intermédiaire`, `PLS`, `PLUS`,
   `PLAI`, `logement social`, `conventionné`. Must match accent- and case-insensitively.
3. **Procedural tells** — `numéro unique d'enregistrement`, `SNE`, `commission d'attribution`,
   `demande de logement social` ⇒ strong social signal. `sans condition de commission` or a
   direct-booking flow ⇒ intermediate signal. The cheapest reliable discriminator the domain offers.
4. **Plafonds de ressources** — compare quoted ceilings against known LLI vs PLUS/PLAI bands for the zone.
5. **Source default** — lowest confidence, used only when nothing else fires. An **absent** signal must
   *lower* confidence, never silently inherit `default_tenure` at full confidence.

---

## Architecture

Single repo, **two languages**, layered, with **adapters as the only site-specific code**.

Two languages because the developer ruled 2026-08-06: *"do it in both phorj and php so i can test
phorj lift and transpile"*. So the tree is `src/<lang>/`, and the spec's single-language `src/core/`
is amended accordingly. The **pure core** — `models`, `tenure`, `criteria`, `dedup` — is written
twice and diffed fixture-by-fixture against one shared corpus. Everything that touches IMAP, HTTP,
SQLite or SMTP stays PHP-only: phorj refuses to transpile those domains, so a whole-app port is
impossible by design rather than by omission (`docs/PHORJ-REQUIREMENTS.md`).

| Layer | Path | Responsibility |
|---|---|---|
| Core | `src/php/Core/` · later `src/phorj/core/` | `models`, `tenure` (the classifier), `criteria` (score + hard disqualifiers), `dedup`, `health` (`SourceHealth` + `SourceStatus`), `Redact` (masks secrets in adapter error text) |
| Store | `src/php/Store/` | SQLite seen-set, price history and run log. **PHP-only** — it touches a database, so phorj will not transpile it. |
| Notify | `src/php/Core/Notify/` | One module per channel. Every notification carries `score` + human-readable `reasons[]`. |
| Adapters | `src/php/Adapters/` | `base` (the `Source` interface), `http_json`, `html`, `email_alert` (IMAP), `browser` (Playwright, opt-in), `sites/` for per-site overrides |
| Enrich | `src/php/Enrich/` | `transit` (IDFM / PRIM door-to-door commute), `geo` (commune → INSEE code, coords) |
| Config | `config/` | `criteria.json` (user criteria), `sources.json` (source definitions + field maps) — both committed. **JSON, not YAML** — ruled 2026-08-07 (Q22): no `ext-yaml` here and no way to install one. `_`-prefixed keys are comments; any other unknown key is a validation error. A gitignored `criteria.local.json` overrides field-by-field |
| Fixtures | `tests/fixtures/<source>/` | Frozen HTML/JSON payloads. Parser tests run **offline**. No network in CI. |
| Classifier corpus | `tests/fixtures/tenure/corpus.json` | **Language-neutral.** Read by both implementations — that shared file is what makes the differential test mean anything. |

PHP is **8.5**, no runtime dependencies, PSR-4 `RentWatch\` → `src/php/`. The test runner is
PHPUnit's official PHAR, not a Composer dev dependency — see `README.md` § Getting started for why.

Every source implements the same interface — no exceptions:

```
name: string
family: 'institutional' | 'private'
defaultTenure: Tenure | null    # hint only, the classifier still runs
fetch() -> RawListing[]
health() -> SourceHealth
```

**Adding a source must be config-only in the common case.** A bespoke adapter under
`src/php/Adapters/sites/` is the fallback, not the default path — if you find yourself writing code there,
say why config was not enough. Use the `/add-source` skill.

`prototype/scout.py` is **not the architecture.** It is superseded reference material. Findings *about*
it are useful as a catalogue of what the real implementation must avoid, but "the prototype does it
this way" is never authority, and extending it in place contradicts the brief.

---

## Hard rules for this repo

1. **Never write an endpoint or API path from memory.** Verify it against the live site first.
   `prototype/sources.yaml` still carries `REMPLACER` placeholders for exactly this reason. A source
   marked `enabled: true` with an unverified URL is a finding.
2. **Source health is not optional.** The classic silent failure is a broken selector returning zero
   results forever while the user concludes the market is quiet. Persist last-success, last-count and a
   rolling 7-day mean per source; alert `SOURCE_BROKEN` after 3 consecutive empty runs against a
   non-zero baseline; warn on a >70% drop. An alert that is computed and never sent is worse than none.
3. **An exception must not become an empty list.** `except Exception: return []` converts a loud
   breakage into a silent one — the exact thing rule 2 exists to prevent. This is the single
   highest-frequency defect class in this codebase; the prototype commits it.
4. **Private portals: email-alert ingestion is the primary path**, not a workaround. It is within ToS,
   defeats anti-bot entirely because there is no bot, is *faster* than polling (alerts fire on
   publication), and does not break on markup changes. Direct scraping is opt-in, disabled by default,
   `legal_risk: true`, and must **refuse to run** without an explicit flag.
5. **No CAPTCHA solving, proxy rotation, or fingerprint spoofing. Ever.** Respect `robots.txt`,
   identify honestly in the User-Agent, keep request rates low with jitter. Never propose any of these
   as a fix for a blocked source — propose the email-alert route instead.
   `demande-logement-social.gouv.fr` and Bienvéo are **out of scope entirely** (social-housing
   channels — they violate §1).
6. **No auto-application** or auto-form-submission to landlords. No multi-user support. No web UI (a
   read-only HTML digest is acceptable later). These are ruled non-goals, not gaps.
7. **Secrets live in `.env`, gitignored.** IMAP credentials, notification tokens, the IDFM API key, the
   RFR figure for eligibility checks. Keep `.env.example` in sync. Never commit personal financial
   data, never log credentials, and scrub any fixture captured from a live payload before committing
   it. `.env` is permission-denied here on purpose — audit `.env.example`.
8. **Hard disqualifiers and score are two different mechanisms.** Do not conflate them. Disqualifiers
   reject silently and are logged only. Score (0–100) drives ordering and notification priority, and
   every notification carries its `reasons[]`. A disqualifier applied before enrichment rejects on a
   field enrichment would have filled — silent over-rejection is invisible, because nothing arrives.
9. **`None` is not zero.** A rent, surface or room count of `None` means *unknown*, not *below the
   minimum*; `floor == 0` (RDC) is falsy but real; `elevator is False` and `elevator is None` are
   different facts. Compare rents **charges comprises**, and normalise sources that report otherwise.
10. Conventional commits. Ship each milestone working — no big-bang integration.

---

## Certification ladder — governs every 3C/6C gate

`advisor()` **is available on this machine** (verified 2026-08-18) and is the FIRST rung: call it
per the global framework. The panel of record for gate rounds is the set of **fresh-context,
read-only, adversarial reviewer subagents** in `.claude/agents/`. Three lenses, one agent each:

| Lens | Agent |
|---|---|
| correctness + regression | `tenure-correctness-reviewer` |
| resilience + legal posture + secrets | `source-resilience-reviewer` |
| completeness + blast-radius | `completeness-reviewer` |

Each reviewer **reads the actual diff, code and tests itself** — never certify from the author's
narrative — and is chartered to REFUTE, not approve. `/converge` runs the panel mechanically.

**Tier: MAXIMAL by default** — all three lenses, **two consecutive fully-clean rounds**, any finding
resets the counter, cap 5 rounds → then ask via `AskUserQuestion` (never silently proceed). Rationale: a
social-housing false positive is an eligibility failure the user pays for in wasted applications, and a
silently-broken source is indistinguishable from a quiet market. Neither is caught by a passing test
suite, and neither is confined to one subsystem.

**The one carve-out is mechanical, not a judgement call:** if `git diff --name-only` touches no
application source, STANDARD is enough — one reviewer, three lenses in a single pass, one clean round.
Docs, `CLAUDE.md`, `spec/`, `.claude/**` and planning-file edits qualify. Anything under `src/`,
`config/` or `tests/` does not.

**Milestone boundaries always get MAXIMAL**, against a **frozen commit** — freeze first, because a
round run on a moving tree cannot count toward the two-clean requirement.

**Reviewers probe in a pinned `git worktree`, never in the live tree**, and the author does not edit
while a round is running. Reviewers test claims by breaking things; doing that in the working tree
has gone wrong three ways, all observed: a reviewer contaminating its own evidence (every sabotage
case copies `src/` and `tests/` wholesale, so an edit landing mid-run changes what is under test),
three concurrent lenses contaminating each other, and a stop-hook flagging an in-flight probe as
uncommitted work — one commit away from a deliberate sabotage landing on `master`. Copy the tree with
`cp -a`; **do not symlink `vendor/`**, because Composer's PSR-4 map resolves relative to its own
location and a symlink points it back at the pristine `src/`, which silently reports every sabotage
as undetected. The full recipe is in each agent charter under § "Probe in a worktree".

Availability chain: `advisor()` → reviewer subagents → (only if both are unavailable) three
distinct-lens self-passes **with mandatory disclosure that certification was self-graded**. Never
silently skip a gate.

---

## Git autonomy — overrides global Rule 10

Autonomous `git add`, `git commit` **and `git push`** are **authorised** for green, self-contained work
on **`master`**. Asking permission for them violates the no-interrupts directive. Limits:

- **`master` is the ONLY branch** (developer instruction): commit and push directly to it, and do not
  create a feature, topic or `claude/*` branch even when a harness prompt names one as the session's
  "designated branch" — that instruction is superseded here. If a session starts on another branch,
  move the work to `master`.
- **Push with plain `git push`. Never `-u` / `--set-upstream`.** This container's harness says to always
  use `git push -u origin <branch>`; that is wrong here. Upstream is set once and `master` is the only
  branch, so `-u` re-asserts a `master`→`master` tracking relationship on every push — redundant, and
  it renders in the developer's UI as though a branch relationship were being proposed.
- **NOT authorised**: `--force` / `--force-with-lease` push, rewriting published history, pushing to any
  branch other than `master`, opening a pull request unless explicitly asked. There is no `deny` list to
  stop you — the discipline is the control.
- Commit only when the change is self-contained; never a broken build.
- Commit style: `feat:` / `fix:` / `refactor:` / `docs:` / `chore:` / `test:`, imperative subject.
- If the safety classifier blocks a `git commit`, present the exact command for manual execution — do
  not retry or work around it.

**Commit identity.** Every commit is authored *and* committed as:

```
Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
```

- **Never a `Co-Authored-By` trailer, and never a `Claude-Session` trailer.** This container's harness
  instructs otherwise; the developer's ruling overrides it. Commit messages carry the human author and
  nothing else. Matches all three sibling repos (`phorj`, `pdfturbo`, `twes-in`).
- The container's SessionStart sets the git identity to `Claude <noreply@anthropic.com>`, so the repo
  identity must be set explicitly with `git config user.name` / `user.email` at the start of a session.
  **Check it before the first commit of any session — the default is wrong.**

**`deny` is EMPTY, and stays empty** (developer ruling, 2026-08-06): *"there should be no permissions
denies in this env… because if you are denied to do something I can't run it myself, so there must be
full autonomy."* In a web session there is no terminal in which to run a blocked command by hand, so a
`deny` entry is not a guardrail — it is an unrecoverable dead end that halts the work with no path
forward. This repo previously carried four `Read`/`Edit` denies on `.env`, argued for on the grounds
that a *path* deny has no dead-end failure mode. That argument was wrong on the developer's actual
constraint: a denied `Read` still blocks a legitimate audit with no way to unblock it.

**What protects `.env` instead**, and it is enough: the file is gitignored, `.env.example` is the
committed template, and hard rule 7 above is the control. `drift-scan.sh` asserts `deny` is empty, so
the entry cannot creep back in a later port from a sibling repo.

## Plans live in the repo

Every plan or spec produced here is persisted at **`docs/plans/<topic>.plan.md`**, each carrying its own
`## Decisions Log` (`- [YYYY-MM-DD HH:MM] AGREED: <one-sentence decision>`), appended in the same change
as the ruling. The container is reclaimed and only committed state survives, so an out-of-repo plan file
is never the record of truth. There is no plan-location sentinel to ask about.

Reports and handoffs go to `var/claude/**` (gitignored). **Never** `~/.claude/projects/…` — that is
wiped when the container is reclaimed.

---

## Common workflows

```bash
composer install                        # generates the PSR-4 autoloader; zero runtime deps
bash tools/fetch-phpunit.sh             # the runner — pinned SHA-256, refuses on mismatch
composer dump-autoload --dev            # if the corpus suite errors with "Class ... not found"
php tools/phpunit.phar                  # the core suite — must stay green
bash tests/sabotage-check.sh            # proves the suite would CATCH a broken classifier
bash tests/test-tenure-guard.sh         # proves the §1 tripwire still fires, and stays quiet on PHP
bash tests/test-fetch-phpunit.sh        # proves the runner fetch refuses a bad signature
bash tests/test-ci-workflow.sh          # proves ci.yml still wires every step CLAUDE.md claims
bash .claude/skills/repair/drift-scan.sh                         # config/doc drift; exit 1 on P0/P1
bash -n .claude/hooks/*.sh tests/*.sh tools/*.sh
python3 prototype/scout.py --help       # the superseded prototype, reference only
```

**`tests/sabotage-check.sh` is not optional ceremony.** Every failure mode in the tenure module is
silent — a classifier that over-rejects looks exactly like a quiet rental market, and one that
under-rejects looks productive until an application is wasted. The store is the same shape: a
seen-set that stops persisting, a price history that stops recording, a run log that reports a dead
source as calm. A green suite proves the code passes the tests; only the sabotage run proves the
tests would notice if it stopped working. **Run it after any change to `src/php/Core/Tenure*`,
`Text.php`, the corpus, or anything under `src/php/Store/`.** It already found three undetected
regressions and one piece of dead safety code on the day it was written, and two more holes in the
store's own suite the day that was added.

<!-- ADAPT: keep in step with bin/scout.
     Target CLI surface, per spec §10:
       scout doctor              # health-check every source: status, timing, item counts
       scout dump <source>       # raw payload of the first item — for building field maps
       scout run --once [-v]     # single pass
       scout run --watch         # loop with jitter
       scout test-notify         # verify the notification channel
       scout replay <fixture>    # re-run parsing against a saved fixture
     `scout dump` is what makes onboarding a new source take 5 minutes instead of an hour.
     Build it early — it is milestone 1, not a nice-to-have. -->

## Testing & verification

Required coverage, per spec §11 — non-negotiable once `src/` exists:

- **Fixture-based parser tests.** One frozen payload per source under `tests/fixtures/<source>/`.
  Offline. No network in CI. A parser test that reaches the network is a monitoring check, not a test.
- **Classifier tests.** ≥30 hand-labelled listing texts covering pure-LLI In'li, mixed CDC Habitat,
  an explicit PLAI, an explicit PLS, and an ambiguous case. The suite must go red if the classifier
  regresses. **Done** — `tests/fixtures/tenure/corpus.json`, 114 cases, and the suite asserts all five
  shapes are present so "30 easy ones" cannot satisfy it. The corpus is **still 114/114 synthetic**:
  the spec asks for *real* texts and those need a captured payload (blocked on the DevTools cURL
  captures). Every case declares its `provenance` and a test asserts the declared counts, so the gap
  is visible as data. Replace them with captured texts as sources come online — append, never
  renumber.
- **Sabotage-verification is part of the classifier's test contract**, not an extra. See
  `tests/sabotage-check.sh` and § "Common workflows" above for why a green suite is insufficient here.
- **The surface matrix is the other half of that contract.** `tests/php/Core/SurfaceMatrixTest.php`
  takes the cross product of the classifier's own excluded-or-undetermined vocabulary — read from
  `LABELS`, `AMBIGUOUS_LABELS` and `PROCEDURAL` by reflection — and EVERY surface a listing presents,
  and asserts no cell reaches a notification. It exists because eight review rounds each found a P0
  of one shape: a correct rule applied to a subset of the surfaces it belongs on. A per-fixture
  corpus cannot find those; it only covers the cells someone thought to write.
  **Do not narrow the matrix to make a change pass** — an empty cell is a §1 hole, and the failure
  message says which surface. Two rules for editing it: a new surface is added to `surfaces()` and
  is opted INTO the counterweight by default, and a cell must be fed an input a real feed could
  emit. Both were violated on its first outing — the field-name cell was fed a JSON key containing
  spaces, so it passed while the two keys it was written for still matched.
- **Criteria tests.** Table-driven, covering every hard disqualifier and every score component.
- **Dedup tests.** Including the cross-portal fuzzy case, attacked from both sides (over-merge hides a
  flat, under-merge triple-notifies one).
- **Store tests.** The store has the most silent failure modes in the tree, and a review panel found
  25 defects in its first cut — so its contract is written down rather than left to judgement. Every
  test must exist in a named category: **identity** (nothing collapses onto a shared key: blank and
  Unicode-whitespace ids in UTF-8 *and* Latin-1, the no-information floor, URL and title
  normalisation, source scoping); **order** (a stale sighting manufactures no price drop, does not
  overwrite current state, and does not corrupt the changes-only history; every run is logged
  whatever its timestamp says); **rent events** (a drop, a rise, an unknown rent and a rent that
  vanishes and returns are four different facts); **time** (a trailing `Z` is UTC on any host
  timezone, fractional seconds of any width parse, a non-existent date is refused, the DST gap is an
  instant); **health** (every `SourceStatus` member reachable and asserted, every `SourceHealth`
  field asserted — five were once replaceable with constants while the suite stayed green);
  **seen-set** (a listing is new exactly once, and *notified* is a different fact from *seen* — the
  store's two most basic guarantees, and the two that had no category for three rounds);
  **persistence** (the seen-set and price history survive reopening; an older schema is upgraded and
  a newer one refused; a snapshot carries every field it claims); **concurrency** (WAL, and a second
  writer that WAITS rather than failing — demonstrated, because a deferred transaction silently
  skips SQLite's busy handler); **failure paths** (every refusal is loud and leaves nothing
  half-written); **secrets** (`Redact` masks before anything is persisted or shown, and does not eat
  the diagnostic). A new store behaviour without a category is a behaviour nobody decided to
  guarantee.

  **`scout doctor` and the run loop MUST pass `$nowIso` to `Store::health()`.** Without it the store
  has no clock, and ONE verdict becomes underivable: `STALE` never fires at all. That is the clock's
  only job — an earlier version also used it to filter the run log, and that discarded real failures
  whenever the clock itself was the wrong thing. **`doctor` must also print `Store::journalMode()`**: WAL can
  be silently refused on a network mount, and a store in rollback-journal mode makes two processes
  contend instead of share. Both failures are silent.

## File layout quick reference

```
.env.example                Committed template for every secret and path. `.env` itself is gitignored
spec/PROJECT_BRIEF.md       Full specification — the source of truth, and a ruling set
state/                      The SQLite seen-set, price history and run log. Gitignored, NOT scratch
prototype/                  Pre-existing single-file prototype. Reference only; do not extend in place
docs/OPEN-QUESTIONS.md      All 25 questions, each closed 2026-08-07 with the default applied
docs/plans/                 <topic>.plan.md, each with its own ## Decisions Log
config/                     criteria.json + sources.json (committed) — JSON, ruled 2026-08-07 (Q22)
src/php/Core/               PHP 8.5 pure core — models + tenure classifier + source health
src/php/Store/              SQLite seen-set, price history and run log
src/phorj/                  phorj port of the same pure core                  [waits on phorj]
tests/php/                  PHPUnit suites
tests/fixtures/tenure/      corpus.json — the language-neutral classifier corpus
tests/fixtures/<source>/    Frozen payloads, one dir per source               [not yet created]
tests/sabotage-check.sh     Proves the classifier suite detects a regression
tests/test-tenure-guard.sh  Proves the §1 tripwire fires, and stays quiet on ordinary PHP
tests/test-fetch-phpunit.sh Proves the runner fetch refuses a bad signature
tools/fetch-phpunit.sh      Fetches the runner; pinned SHA-256, refuses to install on a mismatch
tools/phpunit.phar          Test runner (gitignored — see README § Getting started)
var/claude/                 Reports, handoffs — gitignored, container-lifetime
.claude/                    Project skills, reviewer agents, hooks, settings
.github/workflows/ci.yml    CI — suite+guards every push/PR, sabotage ledger nightly+dispatch
```

## Gotchas & pitfalls

- **`prototype/scout.py` has no tenure classifier at all.** It will happily surface PLAI and PLUS
  listings. It is reference material for the field-mapping and adapter shape only — treat its filtering
  logic as incomplete, not as a baseline to preserve.
- The prototype's commune matching is a substring search over `commune + cp + title + raw_text`. That
  over-matches: a Paris listing mentioning "proche Chatou" passes the commune filter.
- The prototype's `(l.rooms or 0) < self.min_rooms` **disqualifies an unknown room count**. Same for
  surface. That is the `None`-is-not-zero bug (hard rule 9) in its natural habitat.
- The prototype swallows every per-source exception and `continue`s — hard rule 3.
- The prototype's `max_floor` is a hard reject. **Ruled 2026-08-07 (Q5): floor and lift are score
  components only**, and more strongly than the spec asked — `max_floor` and `require_elevator` do
  not exist as config keys at all, so the prototype's behaviour cannot be reintroduced by editing a
  file. The high-floor penalty additionally requires the lift to be **explicitly absent**, never
  merely unmentioned: `null` is not `false` (hard rule 9), which is why it is its own score
  component rather than the negation of the bonus.
- `prototype/sources.yaml` mixes criteria, notification config and sources in one file. The target
  layout splits criteria and sources into two files under `config/`.
- **`allow` rules in `.claude/settings.json` are inert in cloud sessions.** They need an accepted
  workspace-trust dialog, which a cloud session never shows. `defaultMode` is what actually takes
  effect. Don't grow the allow list expecting cloud effect.
- **New skills need a session restart to appear.** Claude Code watches an existing `.claude/skills/`
  directory live, but a newly-created one is not watched until the CLI restarts. The `CLAUDE.md`
  sections bind immediately; the slash commands appear next session.
- **Commit messages: always `git commit -F -` with a QUOTED heredoc (`<<'EOF'`), never `-m "…"`.**
  A double-quoted `-m` string runs backtick command substitution, so any `` `Identifier` `` in the message
  is executed and replaced with its (usually empty) output. Hit on 2026-08-06 in commit `7234550`:
  `` `using` `` was eaten, leaving *"Closable + for the connection"*, and `bash` reported
  `using: command not found`. History was **not** rewritten — force-push is unauthorised here and the loss
  was one word in a message — so the cause is fixed instead. A `<<'EOF'` heredoc is literal: no expansion,
  no substitution, backticks safe.
- **Composer cannot install anything here, and that shaped the toolchain.** The container's egress
  policy returns **403 on `codeload.github.com` and on `api.github.com/.../zipball`**, which is where
  Composer fetches dists from. `git clone` over HTTPS *is* allowed, so `--prefer-source` works — but
  it pulls full git histories, and installing PHPUnit that way produced a **2.6 GB `vendor/`** for a
  test runner. The project therefore has **zero Composer dependencies**; `vendor/` holds only the
  generated autoloader (56 KB) and the runner is PHPUnit's official PHAR at `tools/phpunit.phar`
  (6 MB, gitignored, fetched from `phar.phpunit.de`, which is not blocked). Do not "fix" this by
  adding a dev dependency. Per `/root/.ccr/README.md`, a 403 from the proxy is reported, not routed
  around.
- **`composer dump-autoload` WITHOUT `--dev` silently breaks the corpus suite.** It omits the
  `RentWatch\Tests\` PSR-4 entry; PHPUnit still loads the test *files* itself, so the unit tests keep
  passing while every corpus test errors `Class ... not found`. It reads as a code regression and is
  a build state. `tests/bootstrap.php` now checks this and prints the fix, but if you see that error,
  run `composer dump-autoload --dev`.
- **`.claude/hooks/tenure-guard.sh` false-positives on ordinary PHP, and that is a known cost.** It
  fired five times while the first PHP was written, every time on prose or syntax: `$flat[] =`
  (PHP's array append, read as an empty-list literal — the pattern now enumerates the shapes that
  actually empty something: `= []`, `=> []`, `return []`, `: []`, `: null`, `= array()`), a
  `0.0001` float epsilon read as a lowered confidence threshold, and phrases like *"no tenure
  signal"*, *"clear the floor"* and *"must never be deleted"*. When it fires, check WHICH pattern
  matched before assuming a real problem — reproduce with
  `tr '[:upper:]' '[:lower:]' < file | grep -oE '<pattern from the hook>'`. Reword prose to keep the
  tripwire credible; never weaken a pattern without a matching case in `tests/test-tenure-guard.sh`.
- `ruff` **is** available in this container, and although the PHP side now has a `composer.json`
  there is still no Python manifest — so
  `.claude/hooks/lint-on-write.sh` is live and will report on `prototype/scout.py`. Those findings are
  known and deliberately unfixed: the prototype is kept verbatim as received.

## Credentials & stateful data

**Nothing reads the environment yet** — the adapters, the channels and the CLI do not exist, so
`.env.example` is the agreed SHAPE of the configuration rather than live settings.
`.env.example` is the committed template and lists every key: the dedicated alert mailbox's IMAP
host/user/password, the notification channel token (ntfy / Telegram / SMTP), the IDFM/PRIM API key,
`RFR_N2` if income-eligibility checking is enabled (Q6), and `RENT_WATCH_DB`. Keep the two in sync —
a key added to `.env` and not to the template is invisible to the next deployment.

**Adapter error text is a secrets channel, and the guard is already in place.** An exception from an
HTTP or IMAP adapter naturally carries the request URL (the IDFM key is a query parameter) or the
mailbox it failed on. `Store::recordRun()` persists that text and `Store::health()` interpolates it
into a user-facing detail, so `RentWatch\Core\Redact` masks it at that single funnel. Do not bypass
`Redact::text()` when adding an adapter, and do not add a second, per-adapter copy of it.

Stateful data that must not be casually deleted (the container-era `BLAST-RADIUS.md` that
documented this left with `scripts/claude-bootstrap/` on 2026-08-18 — this list is now the record):

- the seen-set / listings DB (`RENT_WATCH_DB`, default `state/rent-watch.sqlite3` — deliberately NOT
  under `var/`, which this file documents as container-lifetime scratch) — deleting it makes the next
  run **re-notify everything**
- price history — a rent drop is a notification-worthy event; the history is not reconstructible
- `tests/fixtures/**` — the frozen payload IS the test's ground truth
- the classifier corpus labels — relabelling one can make a false positive "correct"

---

## Claude config in this repo

```
CLAUDE.md                          This file — project scope, wins on any conflict
.claude/settings.json              Allow-list permissions, defaultMode auto, hook wiring
.claude/hooks/tenure-guard.sh      PostToolUse tripwire on the §1 rule; exits 2 when it fires
tests/test-tenure-guard.sh         Sabotage test FOR that hook — must-fire and must-stay-silent halves
.claude/hooks/lint-on-write.sh     Lints the file just written (ruff / yamllint / shellcheck / json)
.claude/hooks/format-on-write.sh   Reports formatting drift; never rewrites behind Claude's back
.claude/hooks/log-helpers.sh       log_obs() — SOURCED by the three hooks above, not itself a hook
                                     (so it is unregistered and mode 644 by design). Vendored here
                                     2026-08-18 when scripts/claude-bootstrap/ was removed
.claude/agents/tenure-correctness-reviewer.md    correctness + regression lens
.claude/agents/source-resilience-reviewer.md    resilience + legal posture + secrets lens
.claude/agents/completeness-reviewer.md         completeness + blast-radius lens
.claude/skills/                    Repo-native slash skills; `ls` is the authoritative list
.claude/skills/repair/drift-scan.sh  The mechanical half of /repair — run it in a gate
tests/sabotage-check.sh            Breaks the classifier many ways; the suite must catch every one
tests/test-fetch-phpunit.sh        Proves the runner fetch refuses a bad signature
tests/test-ci-workflow.sh          Proves ci.yml still wires every step this file claims CI runs
.github/workflows/ci.yml           CI: suite+guards on every push/PR; sabotage ledger nightly+dispatch
.claude/hooks/precompact-handoff.sh  PreCompact -> var/claude/handoff/latest.md; refuses to overwrite
                                     a hand-written one marked `<!-- manual -->`. Vendored from /stack
                                     2026-08-18 (the four siblings had NO working handoff after the
                                     bootstrap removal). Registration lives in .claude/settings.json
.claude/hooks/tests/test-precompact-handoff.sh  42-assertion suite for that hook — run it directly
```

Beyond the ported set: `/add-source` (onboard a landlord or portal, config-only) and `/ask-human`
(the question protocol — `AskUserQuestion` with this repo's extra rules; it **shadows** the global
skill of the same name — project scope wins).

`/repair` detects drift between what this config *claims* and what exists. Its mechanical half is
`bash .claude/skills/repair/drift-scan.sh` — exit 1 on any P0/P1, so it works as a gate. Run it after
adding a skill, agent or hook, and after any port from a sibling repo. It exists because one session
found five such defects by hand, including a shipped framework that denied having a skill it had.
