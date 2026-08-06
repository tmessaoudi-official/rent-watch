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
Changes: whether `src/enrich/transit.*` is milestone-1 infrastructure or a later add-on.

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
- [2026-08-06] AGREED: **run every assumption past the developer** — *"even if you assume anything run
  it by me"*. Assumptions get stated explicitly and recorded here, not absorbed silently.
