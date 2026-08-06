# core/tenure + core/models Plan

The first application code in this repo. Builds the **pure core** — the part that needs nothing from
phorj, no mailbox, no endpoint and no secret — while the phorj team analyses
[`docs/PHORJ-REQUIREMENTS.md`](../PHORJ-REQUIREMENTS.md).

Scope: `models` (the value objects the whole pipeline passes around) and `tenure` (THE classifier,
spec §4, and the module that carries `CLAUDE.md` §1). Nothing else — no adapters, no criteria, no
store, no notify.

---

## Decisions Log

- [2026-08-06 22:30] AGREED: **Start the pure core without waiting for phorj.** Developer: *"let's
  start without phorj and then we will do it when it's ready"*. The classifier is pure text→verdict,
  so it needs none of phorj's three missing modules (`Core.Imap`, an HTML parser, `sleep`).
- [2026-08-06 22:32] AGREED: **PHP 8.5, latest of everything.** Developer: *"use php 8.5"* / *"latest
  of everything"*. Installed PHP 8.5.9 from the ondrej PPA and made it the default `php`; Composer
  self-updated to 2.10.2. PHPUnit pulled at its latest release.
- [2026-08-06 22:35] AGREED: **Two-language layout is `src/<lang>/`.** The spec's tree says
  `src/core/`, written when the project was single-language. The developer has since ruled *"do it in
  both phorj and php so i can test phorj lift and transpile"*, so the tree becomes `src/php/Core/` and
  later `src/phorj/core/`. `spec/PROJECT_BRIEF.md` §3's tree is amended by this, not violated.
- [2026-08-06 22:35] AGREED: **The classifier corpus is language-neutral JSON**, at
  `tests/fixtures/tenure/corpus.json`, read by BOTH implementations. That single shared file is what
  makes the phorj-vs-PHP differential test meaningful — if the two implementations disagree on one
  fixture, the corpus says which is wrong. JSON rather than YAML because `Core.Json` is a confirmed
  **default** phorj feature and no YAML module is confirmed.
- [2026-08-06 22:36] AGREED: **Confidence is computed in integer basis points (0–100), exposed as a
  float.** Float arithmetic is not guaranteed bit-identical across two language runtimes; integer
  arithmetic is. Since the whole point of the dual implementation is byte-identical differential
  testing, the internal representation must be exact. `confidence()` divides by 100 only at the
  boundary.
- [2026-08-06 22:38] AGREED: **`mixedTenure` defaults to `true`.** A source added without declaring
  itself must be treated as capable of carrying social stock, so the fail-closed rule engages. The
  opposite default would let a config omission silently disable the §1 protection.
- [2026-08-06 22:40] AGREED: **PLS is in the excluded set in code**, per the Q4 answer of 2026-08-06.
  `CLAUDE.md`'s glossary and `spec/PROJECT_BRIEF.md` §2 still say `OPEN — Q4` / `ASK USER (Q4)`; both
  are stale and are corrected in this change.
- [2026-08-06 22:41] AGREED: **The corpus ships as `synthetic` and says so in the data.** The spec asks
  for 30 *real* listing texts. Real texts cannot be captured until an endpoint or a browser session
  exists (blocked on U3). Every fixture therefore carries a `provenance` field, and the suite asserts
  the corpus knows how many of its own entries are synthetic — so the gap is visible as data, not as a
  comment nobody reads.

---

## The design

### Why the classifier is not just a keyword match

Three things make this module harder than it looks, and each has fixtures:

1. **`PLUS` is an extremely common French word.** *"plus de 3 chambres"*, *"au plus tard"*, *"plus
   lumineux"*. A naive `str_contains($text, 'plus')` classifies most of the Paris rental market as
   social housing. `PLAI` is worse in a different direction: as a bare substring it matches
   *plaisant*, *plaine*, *plaisir*. Every acronym is therefore matched **word-boundaried**, and the
   two genuinely ambiguous ones (`PLUS`, `PLS`) additionally require ALL of: an uppercase spelling in
   the original text, an adjacent financing-context word, and no French comparative immediately
   after — each tested on the SAME occurrence, not anywhere in the document.

2. **Signal priority is a ladder, not a vote.** The highest tier that fires decides the tenure. Lower
   tiers may only adjust confidence. That is `CLAUDE.md`'s *"a lower-priority signal must never
   override a higher one"*, implemented rather than restated.

3. **The ladder alone is not fail-closed enough.** If a structured field says `LLI` and the body text
   says `PLAI`, the ladder keeps `LLI` — and a 0.97 confidence sails past the 0.6 floor into a match.
   So there is a **conflict rule** on top of the ladder: an eligible verdict contradicted by any
   excluded-tenure signal collapses to `UNKNOWN`, and `UNKNOWN` never matches. It does not assert the
   listing is social; it withholds. The reverse is deliberately **not** symmetric — an excluded
   verdict contradicted by an eligible signal stays excluded. Softening an exclusion is the one
   direction that costs the user a wasted application.

### Signal ladder and confidence

| Tier | Signal | Base confidence |
|---|---|---|
| 1 | Explicit structured field (`financement`, `typeProduit`, `categorie`, …) | 97 |
| 2 | Explicit label in text (`logement intermédiaire`, `LLI`, `PLAI`, `logement social`, …) | 90 |
| 3 | Procedural tell (`SNE`, `numéro unique`, `commission d'attribution` ⇒ social) | 80 |
| 4 | Plafonds de ressources band | 70 — **ships with no band data**, see below |
| 5 | Source default | **50** |

Tier 5 sits **below the 0.6 floor on purpose**. A mixed source that emits a listing with no tenure
signal at all lands in the digest, never in a notification. That is `CLAUDE.md`'s *"an absent signal
must lower confidence, never silently inherit `default_tenure` at full confidence"* made structural.

Corroboration from a lower tier: **+3** each, capped at 99. Contradiction: **−15**, floored at 10.

Tier 4 is **wired but empty**. Real 2026 plafond figures per zone and household size are not in this
repo and must not be written from memory (`CLAUDE.md` hard rule 1). The tier exists in the ladder, the
band table is injectable, and it ships with zero bands — so it produces no signal until real figures
are sourced. A test asserts exactly that. Invented numbers would be worse than an honest gap.

### Outcome

`Outcome` is derived from tenure + confidence + the source profile, and is the only thing the rest of
the pipeline should branch on:

```
tenure is excluded         → REJECT   (always — confidence is irrelevant to an exclusion)
tenure is UNKNOWN          → DIGEST
eligible, confidence ≥ 60  → MATCH
eligible, < 60, mixed src  → tenure becomes UNKNOWN → DIGEST   ← the §1 fail-closed rule, verbatim
eligible, < 60, pure src   → MATCH    (a pure source publishes no social stock to be confused with)
```

`Tenure::isExcluded()` is a method on the enum with a hard-coded set. There is no config key, flag or
constructor argument that can change it — per `CLAUDE.md` §1, *"not user-overridable"*.

---

## Files

| Path | What |
|---|---|
| `composer.json` | PSR-4 `RentWatch\` → `src/php/`. **Zero dependencies** — the runner is `tools/phpunit.phar` |
| `src/php/Core/Tenure.php` | The `Tenure` enum + excluded/eligible sets |
| `src/php/Core/Outcome.php` | `MATCH` / `DIGEST` / `REJECT` |
| `src/php/Core/RawListing.php` | What an adapter emits — spec §3 `models` |
| `src/php/Core/SourceProfile.php` | The subset of `Source` the classifier needs |
| `src/php/Core/TenureSignal.php` | One fired signal: tier, tenure, reason, evidence |
| `src/php/Core/Classification.php` | tenure + confidence + signals + outcome |
| `src/php/Core/TenureClassifier.php` | The ladder, the conflict rule, the fail-closed rule |
| `src/php/Core/Text.php` | Deterministic normalisation shared by both languages |
| `tests/fixtures/tenure/corpus.json` | The shared, language-neutral labelled corpus |
| `tests/php/Core/*Test.php` | PHPUnit suites, corpus-driven + unit |
| `tests/bootstrap.php` | Fails loudly when the dev autoloader is missing, instead of erroring per-test |
| `tests/sabotage-check.sh` | Breaks the classifier 15 ways; the suite must catch every one |
| `tests/test-tenure-guard.sh` | Sabotage test for the §1 tripwire itself — must-fire and must-stay-silent |

## Blast radius

- `CLAUDE.md` glossary: PLS `OPEN — Q4` → `NEVER`; architecture table `src/core/` → `src/php/core/`.
- `spec/PROJECT_BRIEF.md` §2: PLS/LIBRE `ASK USER (Q4)` → the Q4 answer.
- `.claude/hooks/tenure-guard.sh`: add `pls` to the excluded-term patterns.
- `.claude/skills/repair/drift-scan.sh` S6: add `PLS` to `TERMS`, so the guard can never lose it again.
- `docs/OPEN-QUESTIONS.md`: two new questions surfaced by building this (PLI, plafond bands).
- `.gitignore`: `/vendor`.
- `README.md` + `CLAUDE.md` § "Common workflows": the repo now has something to run.

---

## What building it actually found

Recorded because each was found by the machinery rather than by review, and each is the kind of thing
that would otherwise have shipped looking fine.

- **`route()`'s undetermined-tenure arm was unreachable dead code.** Two paths — no evidence at all,
  and the conflict rule — each built their own `Classification` with a hard-coded DIGEST, so the
  safety arm in `route()` was never executed and could be deleted with the suite green. Found by
  `tests/sabotage-check.sh`. Fixed by funnelling every exit through one `verdict()` helper, which is
  what the `Outcome` docblock already claimed was happening.
- **The collocation guard was untested; the comparative suppression was doing all the work.** Every
  `plus` trap in the corpus happened to be followed by a comparative (`plus de`, `plus tard`,
  `plus lumineux`), so deleting the collocation test left the suite green. Fixed with a SHOUTED
  fixture — `PLUS UN BUREAU` — because uppercase is enforced by the acronym pattern itself, so a
  lowercase trap never reaches the guard at all and proves nothing.
- **The suppression was evaluated per document, not per occurrence.** `Logement PLUS. Plus grand que
  la moyenne.` would have had its genuine financing label suppressed by an unrelated adverb later in
  the description — a social listing reaching a notification. Rewritten to test each occurrence in
  its own local context.
- **The corpus miscounted itself.** `declared_counts` said 47 where there were 52. The provenance
  test caught it, which is the whole reason that test exists.
- **`tests/sabotage-check.sh` had the classic self-verification bug**: it reported 13/13 detected
  while the baseline suite was *already red* from a missing autoloader, so every sabotage trivially
  "passed". It now asserts the baseline is green before running anything.
- **`tenure-guard.sh`'s `[]` pattern collided with PHP syntax.** `$flat[] = …` is an array append,
  which the excluded-set-emptying pattern read as an empty-list literal. Narrowed to `= []` / `: []`,
  which still catches every real emptying — proven by the new `tests/test-tenure-guard.sh`, which
  tests both halves of the tripwire's contract: 10 writes it must catch, 7 it must ignore.
- **PLS was excluded in the Q4 answer but absent from the tripwire's patterns**, and `drift-scan.sh`
  S6 could not notice because its `TERMS` table did not list PLS either. Both fixed.

## Decisions Log (continued)

- [2026-08-06 23:10] AGREED: **zero Composer dependencies; PHPUnit via the official PHAR.** The
  container's egress policy 403s `codeload.github.com` and GitHub zipballs, so Composer falls back to
  full `git clone`s — a PHPUnit dev dependency produced a **2.6 GB `vendor/`** for a test runner.
  `phar.phpunit.de` is not blocked. `vendor/` is now 56 KB of generated autoloader. Per
  `/root/.ccr/README.md` a proxy 403 is reported, not routed around; this is a different channel, not
  a workaround.
- [2026-08-06 23:15] AGREED: **sabotage-verification is part of this module's test contract.** Not a
  one-off. Every failure mode here is silent, so a green suite is not evidence that the tests would
  catch a regression. Wired as `tests/sabotage-check.sh`, documented in `CLAUDE.md` § Common
  workflows, and it must be run after any change to the classifier, `Text.php` or the corpus.
- [2026-08-06 23:20] AGREED: **the corpus declares its own provenance and the suite checks it.** The
  spec asks for real listing texts; all 56 are synthetic until a payload can be captured. Making that
  machine-checked keeps the gap visible instead of letting it decay into a stale comment.

---

## Round 1 of the certification panel — what it found

Three fresh-context adversarial reviewers, MAXIMAL tier. **28 findings, 3 of them P0.** Every one is
fixed below. Recorded in full because the interesting part is not that they were found but that the
suite, the sabotage run and my own reading all passed the code first.

### The round did not count, and that is a finding about me

Two reviewers independently flagged it: I committed `54fb014` while the panel was in flight, and
added an 81-line security-relevant shell script after dispatch. `CLAUDE.md` § Certification ladder
says a MAXIMAL round runs **against a frozen commit** precisely so this cannot happen. So round 1 is
advisory only; the two-consecutive-clean requirement restarts from a frozen tree.

### P0 — three ways a social listing could reach a notification

1. **`sans commission` read as an allocation tell.** In the wild that string is almost always
   `sans commission d'agence` — a FEE disclaimer that says nothing about how a flat is allocated, and
   which bailleurs sociaux advertise too. Tier 3 clears the floor unaided, so one commercial sentence
   converted a fail-closed digest into a notification. Removed; the two literals that carry the
   attribution sense explicitly remain. Fixture `regress-001`.
2. **`logement libre` read as tenure LIBRE.** It is not a tenure term at all — it is the standard
   French *vacancy* phrase (`libre au 1er août`, `libre de suite`). It fired tier 2 at 90 on a move-in
   date. Compounding it, `dropConventionneWhenIntermediateIsStated()` tested `isEligible()` rather
   than "is an intermediate label", so the spurious LIBRE **disarmed the conventionné exclusion** — a
   listing whose own title read *"Logement conventionné"* reached MATCH with the word absent from its
   reasons. Two independently reasonable-looking table entries composing into a §1 breach. Both
   fixed; fixtures `regress-002` and `regress-003b`.
3. **Malformed UTF-8 folded to an empty string.** `preg_replace('/\s+/u', …)` returns `null` on
   malformed input and a `(string)` cast turned that into `''`. The classifier then reported *"aucun
   signal dans l'annonce"* — a false statement about the listing, on the developer's phone — and
   matched on the source default. A cp1252 body carrying `conventionné PLAI … numéro unique
   d'enregistrement` classified as LLI/MATCH. This is `CLAUDE.md` hard rule 3 in its purest form: an
   error became an absence. Now a typed `MalformedText` refusal that routes to the digest.

### P1

- **`COMPARATIVE_TAIL` was an uncompletable denylist.** Any French adjective not on it turned
  `LOGEMENT PLUS MODERNE` into tenure PLUS and a silent REJECT. Replaced with the closed question:
  does a financing label *end* the phrase (punctuation, end of text, another acronym)? A known
  comparative means adverb; anything else is **indecidable and digests** rather than being guessed in
  either direction. Fixtures `regress-004`, `trap-003b`, `trap-003c`.
- **NFD-decomposed accents deleted the social tells.** The fold tables carried precomposed
  codepoints only, so an NFD `numéro unique d'enregistrement` never matched while an unaccented `LLI`
  in the same listing still did — the social side vanished and the eligible side survived. One line
  strips combining marks. Fixture `regress-005`.
- **Undecoded HTML entities were worse than silent.** An entity inside one label deleted that label
  and left the others standing: `logement&nbsp;social … loyer intermediaire` classified as LLI. Now
  refused as evidence that the ADAPTER stopped short, which is where the bug actually is. Fixtures
  `case-005`, `case-006`.
- **The tripwire's own narrowing lost detection, and its comment denied it.** Anchoring the
  empty-list pattern to `= []` silenced `public static function excluded(): array { return []; }` —
  exactly how you would empty an accessor in the language this repo now uses. `return []` and `=> []`
  are listed explicitly, and `!== []` no longer trips it.
- **The guard's own test poisoned the observability log.** Every run appended ten synthetic
  `FIRED on …` lines to the real `var/claude/logs/hooks-errors.log`, byte-identical in shape to a
  genuine §1 firing. `OBS_LOG` now points at a scratch file.
- **The whole certification surface still said the application did not exist.** 11 `SKILL.md` files,
  3 reviewer agents and `scripts/claude-bootstrap/CLAUDE-global.md` — the last of which `install.sh`
  ships as the NEXT session's system prompt. Worst of them: `tenure-correctness-reviewer.md` told the
  reviewer to return `PANEL VERDICT: CLEAN` when the diff did not touch `src/core/tenure.py`, a path
  that never existed here. A scripted route to CLEAN on the one module §1 exists to protect.

### P2/P3 worth naming

- `resolve()` implemented the **opposite** of its own docblock — transposed `strlen` terms made the
  shorter evidence win a tie, so `{financement: LLI, categorie: PLAI}` was decided by `lli` being
  three characters. A phorj port written from the docblock would have disagreed on exactly that
  input, which is the differential the shared corpus exists to expose. Fixture `regress-006`.
- The **fail-closed rule changed only the `Outcome`**, leaving `tenure` as LLI. Spec §4 requires the
  verdict itself to become UNKNOWN; the two halves of the object disagreed. No fixture reached the
  branch because every mixed source in the corpus declared no default. Fixture `regress-007`.
- `sabotage-check.sh` trusted a **non-zero exit code** as proof of detection — which a PHP parse
  error or a failed `cp` also produces. It now requires the suite to *say* it failed. It also ran
  `rm -rf "$work/repo"` with `$work` unchecked, and carried one sed expression that had silently
  been a no-op.
- `composer.json` committed `preferred-install: source`, which makes the git-clone fallback
  unconditional rather than a consequence of the egress policy — i.e. it would reproduce the 2.6 GB
  `vendor/` on hosts where dists work fine. Removed.

## Blast radius — corrected

The original list below was incomplete, and that incompleteness is what let the stale-scope
findings through. The `.claude/**` surface is part of the blast radius of any change that makes an
absent thing present.

- `CLAUDE.md`: PLS glossary, the excluded set, architecture table, status, workflows, gotchas, counts.
- `spec/PROJECT_BRIEF.md` §2: the Q4 answer.
- `.claude/hooks/tenure-guard.sh` + `tests/test-tenure-guard.sh`.
- `.claude/skills/repair/drift-scan.sh` S6.
- **`.claude/skills/*/SKILL.md` — 11 files carrying a shared "Absent: `src/`" banner.**
- **`.claude/agents/*.md` — all 3 reviewer charters.**
- **`scripts/claude-bootstrap/CLAUDE-global.md` — shipped as the next session's system prompt.**
- `docs/OPEN-QUESTIONS.md`: Q18, Q19, Q20.
- `.gitignore`: `/vendor/`, `/composer.lock`, `/tools/*.phar`.
- `README.md`, and this plan.
