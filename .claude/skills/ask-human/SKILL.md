---
name: ask-human
description: >
  PLAIN-TEXT question protocol — never AskUserQuestion. Context, a minimal failing example,
  clear numbered options, a recommended option first with its reason, then STOP and wait.
user-invocable: true
disallowed-tools: AskUserQuestion
---

<!-- ═══════════════════════════════════════════════════════════════════════════════════
  REWRITTEN 2026-07-27 (developer ruling, recorded in `docs/plans/claude-bundle-cross-repo-audit.plan.md`). This skill previously
  mandated `AskUserQuestion` and forbade prose questions. That is now INVERTED:

    `AskUserQuestion` is FORBIDDEN in this project. It silently fails here — it returned
    "the user did not answer" four separate times on 2026-07-26 while the developer was
    actively at the keyboard, so a question asked that way can be lost with no trace and
    the turn ends as if nothing was asked. A gate that cannot fire is worse than none.

  The developer's instruction, verbatim: *"never use askUserQuestion — you must put the
  context clearly with clear options and clear examples with a recommended option"*.

  rent-watch ADAPTATION (2026-08-06) of the twes-in port: the protocol itself is UNCHANGED — five
  parts, the shape template, and every non-negotiable rule are exactly as ported. Only the
  illustrations were re-grounded: twes-in's invariants (money/tax rounding, invoice state machines,
  tenant isolation, e-invoicing validity) were replaced by rent-watch's own, and the worked example is
  now a tenure-classification question instead of a VAT-rounding one. `AskUserQuestion` remains
  FORBIDDEN project-wide.
═══════════════════════════════════════════════════════════════════════════════════ -->

## --help

> If ARGUMENTS contains `--help`: output the text below verbatim, then STOP — do not execute any other steps.
>
> ```
> /ask-human — Plain-text question protocol: context + example + numbered options,
>              recommended first with its reason, then stop and wait.
>              AskUserQuestion is forbidden — it silently fails in this container.
>
> No flags — invoked automatically by Claude whenever a decision belongs to the developer.
> ```

---

# Plain-text question protocol

Every question to the developer is **ordinary text in the response**. No tool call, no dialog, no
hidden state. Then **STOP**: end the turn and wait. Never assume an answer, never proceed on a
default, never re-ask a different question because the first one went unanswered.

## The five required parts

| # | Part | Requirement |
|---|---|---|
| 1 | **Context** | What is being decided and *why it is being asked now* — one short paragraph. Enough that the developer needs no scrollback. |
| 2 | **Example** | A **minimal concrete example** of the problem — for a language question, a runnable current-syntax program and its actual current output/error. Not a description of the program: the program. |
| 3 | **Options** | Numbered, mutually exclusive, each with its own consequence. Ordinarily 2–4. |
| 4 | **Recommendation** | **Option 1 is the recommended one**, marked `(recommended)`, with the reason it wins stated in the same breath. |
| 5 | **Escape hatch** | A visible final option — *"none of these / challenge the premise"* — plus an explicit invitation to tweak any option. The developer must be able to answer *and* amend in one reply. |

## Shape

```
## Question — <one-line subject>

<Context: what is being decided, why now, what is blocked on it.>

Today:

    <minimal example — actual code, actual output/error>

**Option 1 — <name> (recommended).** <What it does.> <Why it wins.>
   After: <the after-state — the same example under this option>

**Option 2 — <name>.** <What it does.> <Cost or risk that makes it second.>
   After: <after-state>

**Option 3 — none of these / challenge the premise.** <What you would want to hear.>

I'll wait for your answer before doing anything else.
```

## Non-negotiable rules

- **Never `AskUserQuestion`.** Not as a fallback, not "just to try", not for a yes/no.
- **Never a bare `?` with no options.** If a real choice exists, enumerate it. An unstructured
  question makes the developer do the work of designing the options.
- **Always a recommendation.** "What do you prefer?" with no lean is an abdication. State the
  recommendation and why — the developer can then disagree cheaply.
- **The after-state goes in the option.** Prose written *outside* the option list is easy to miss
  while comparing options; put each option's consequence *inside* that option.
- **One STOP per question set.** Batch related questions (3–4 is fine when the developer asked to
  move faster), but end the turn after the batch — never answer your own question and continue.
- **Never re-open a ruled decision** without new evidence, and say what the new evidence is.
- **Challenge before accepting.** If the developer's proposal has a failure mode, say so in one or
  two sentences *and still deliver what was asked* under a stated assumption if they reaffirm it.

## When this protocol is mandatory

- Any **user-visible product decision** — which communes or filters are in scope, what a notification
  says and how it is ranked, which tenure classes are searched at all, which sources are enabled.
  Those are the developer's, made interactively, never ruled alone.
- Any **irreversible or outward-facing action** — a force-push, rewriting published git history, a
  schema migration run against the real listings database, sending a live notification to the
  developer's phone, subscribing or reconfiguring the alert mailbox, or a first request to a landlord
  site from this container. Note that ordinary `git add` / `git commit` / `git push` are
  **autonomously authorised** here (CLAUDE.md § "Git autonomy") and must NOT be asked about.
- Any **change to a documented invariant or a declared ceiling** — the excluded-tenure set, the 0.6
  fail-closed confidence threshold, where `UNKNOWN` is routed, the boundary between a hard
  disqualifier and a score penalty, the `Source` adapter contract, or the opt-in gate on portal
  scraping. Weakening one of these is a product decision, not an implementation detail — and for the
  social-housing exclusion specifically, the answer is that it does not get weakened at all
  (CLAUDE.md §1); ask about *how* to satisfy it, never *whether* to.
- A **certification loop that hits its cap** (CLAUDE.md § "Certification ladder": 5 rounds with findings still open → ask, never
  silently proceed).
- Any point where two readings of the request lead to **materially different work**.

## When it is NOT needed

Routine judgement calls with an obvious default, and pure information questions. Asking about
everything is its own failure — it converts the developer into a decision queue. Decide what you can
defend, state the assumption, and keep moving.

## Worked example

```
## Question — is PLS in scope, or excluded with PLAI and PLUS?

The tenure classifier needs this before it can have a default, and the answer changes which
sources are worth building at all. PLS is the top tier of *social* financing — its income
ceilings are high and landlords routinely market it on the same page as intermediate stock — so
it is the one genuinely ambiguous class in the glossary. PLAI and PLUS are settled (never), LLI
is settled (always). This is an eligibility question, not a preference, and I cannot answer it
from the repo.

Today, on a real CDC Habitat result set, the same page returns all three:

    "T4 82m² — financement: PLAI"  → excluded, no ambiguity
    "T4 78m² — financement: PLS"   → ??? currently classified SOCIAL and dropped silently
    "T4 80m² — logement locatif intermédiaire" → LLI, notified

    With PLS excluded, roughly a third of CDC Habitat's T4 stock never reaches you.
    With PLS included, some of what you get needs an SNE number you may not have.

**Option 1 — exclude PLS, treat it as social (recommended).** Keeps the excluded set aligned
   with "requires SNE registration and a commission d'attribution", which is the practical test
   for whether you can actually apply.
   After: PLS listings are dropped silently and logged, exactly like PLAI and PLUS. Highest
   precision, and every notification is one you can act on.

**Option 2 — surface PLS in the "à vérifier" digest only.** Treats it as neither a match nor a
   silent drop: it never reaches the high-priority notification, but you can review the batch.
   After: you see PLS stock without it competing with LLI for your attention. Costs you a daily
   digest to skim, and the digest is where genuinely-unclassified listings already go.

**Option 3 — include PLS as a full match, ranked below LLI.** Maximum recall.
   After: notification volume rises materially and some alerts are for units you cannot get
   without an SNE number — which is the failure mode that makes the tool untrustworthy.

**Option 4 — none of these / challenge the premise.** For example: include PLS only for specific
   landlords where you know the allocation route, or only above a rent threshold that implies a
   high ceiling — say so if that is the target.

I'll wait for your answer before doing anything else.
```
