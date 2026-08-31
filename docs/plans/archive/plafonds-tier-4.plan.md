> SUPERSEDED (2026-08-31) by docs/plans/scout-unified-execution.plan.md. Kept for its
> Decisions Log and measurements; do not execute from this file.

# Plafonds — classifier signal tier 4 Plan

Tier 4 is the last unimplemented rung of the classifier's documented signal ladder
(`CLAUDE.md` § "Domain glossary"): *compare quoted ceilings against known intermediate vs social
bands for the zone*. The scaffolding already exists and ships deliberately empty —
`Core/PlafondBands` with an injectable table, `TenureClassifier::plafondSignals()` returning `[]`,
and `TenureClassifierTest::testPlafondTierIsInertUntilRealBandsAreSourced()` asserting the tier
stays inert so that the day real figures land, the test says the tier woke up.

**What is missing is the DATA, and hard rule 1 governs how it arrives.** The figures vary by zone
(A bis / A / B1 in Île-de-France) and by household size, and they are revised annually. Writing them
from memory is forbidden; they must be fetched from a dated official publication and committed with
their URL and year, exactly as the landlord payloads were frozen.

## Decisions Log

- [2026-08-26 00:13] AGREED: tier 4 is built at **CONSERVATIVE per-zone thresholds**, not at full
  household-size fidelity. A listing quoting a bare `plafond de ressources` figure states neither
  its zone nor its household size, so the tier fires ONLY where the answer is unambiguous across
  every household size: at or below the highest social ceiling for the zone reads SOCIAL; strictly
  above every social ceiling and inside the intermediate bands reads intermediate; anything between
  emits nothing. An unknown zone assumes the zone whose social ceilings are HIGHEST, so the error
  direction is over-reject — the §1-safe one. `classifyCeiling(int $ceilingEur, string $zone)` keeps
  its current signature.
  **Rejected:** full fidelity (household-size-aware cells plus a commune→zone map for all of IdF).
  It needs a SECOND dated dataset — the official zonage A/B/C by commune — which is a second thing
  that goes stale annually, and tier 4 still only fires on listings quoting a bare number, which may
  be very few. **To reverse:** add the household parameter and the zonage table.
  **Also rejected:** reference-only (commit the figures, leave the tier inert).
- [2026-08-26 00:13] AGREED: certification runs autonomously at `advisor()`-only; no reviewer panel.
  Carve-out retained: STOP and ask if anything here would weaken §1, or if the official source turns
  out to be unreachable from this container.

- [2026-08-26 02:10] MEASURED, and it REFUTES the premise the chosen option rests on — recorded as a
  ruling because the conclusion changed what got built. The option said tier 4 fires *"only when a
  quoted figure is unambiguous across EVERY household size"*; computing that region against the real
  tables leaves far less than expected:
  - **Even at the same household size**, zone B1's intermediate ceilings sit BELOW the Paris PLS
    ceilings for every size from two upward (B1 couple 48 268 € vs PLS 52 303 €). Only **13 of 18**
    (zone, size) pairs separate at all.
  - A listing states a bare figure with **no household size**, so the sizes collapse — and the bands
    then overlap from **36 144 €** (B1, one person) to **109 595 €** (PLS Paris, six), a 73 451 €
    range. **0 of 18** pairs separate once the size is unknown.
  - Social ceilings are **unbounded upward** (a per-additional-person increment), so the *"above
    every social ceiling ⇒ intermediate"* half of the rule has no derivable region at all.
  So the rule as stated yields exactly one usable direction: **strictly below 36 144 € ⇒ SOCIAL**.
  This is not a departure from the ruling — it is the ruling evaluated against the figures it was
  waiting for. **To widen:** a commune→zone map plus a household-size reader would restore the
  13 separable pairs.
- [2026-08-26 02:10] AGREED: tier 4 emits **SOCIAL or nothing** — never an intermediate verdict
  (manufacturing eligibility from a number is the §1-dangerous direction, and `PlafondBands` refuses
  such a band at construction), and never a doubt either (a numeric doubt contradicts a correct
  tier-2 label into the digest, exactly as `loyer plafonné` did to `lli-004` and `lli-011` when it
  was tried as one).

## Formal Plan

1. `Core/PlafondBands` carries both committed tables with their URLs and dates, derives the
   threshold with `min()` rather than writing it, refuses any non-SOCIAL band at construction, and
   compares **strictly** (`<`) because the threshold is itself an intermediate ceiling.
2. `TenureClassifier::plafondSignals()` reads the listing; `quotedCeilingsIn()` carries the five
   extraction guards, each one a lesson already paid for elsewhere in this repo.
3. Tier 4 is armed by default; `testPlafondTierIsInertUntilRealBandsAreSourced` becomes
   `testPlafondTierIsArmedFromTheCommittedFigures` — its own docblock called that the planned
   wake-up.
4. Corpus gains five cases, `plafond-005` isolating the anchor after a sabotage run proved the
   obvious demonstration proves nothing.
5. Eight sabotage cases, all detected.

## What it does on real data today: NOTHING, and that is stated rather than discovered

Measured 2026-08-26, after arming:

- A live `--once --seed` pass over Logirep and Cityloger: **165 listings, tier 4 fired on 0.**
- The 20 captured In'li detail descriptions (`tests/fixtures/inli/descriptions.json`): **0 mention
  `plafond` at all**, so tier 4 fired on 0.
- The only real texts in the tree quoting `plafond de ressources` are corpus cases, and none of the
  pre-existing ones carries a figure — which is why all 140 corpus tests still pass unchanged.

So arming tier 4 **changed no live verdict**. That is the honest result and not a disappointment: the
shape it catches is real (a social listing quoting a small-household ceiling without naming its
scheme — PLAI at every size, PLUS for one or two, PLS for one) and none of the five live sources
currently emits it. The In'li listings that DO state `PLS` say the acronym, which is a tier-2 label
caught at 0.90 and never tier 4's business.

**Do not read the zero as "it works".** It says the tier is inert against today's payloads, which is
also what a broken extractor would say. What proves the mechanism is the eight sabotage cases and the
five corpus cases; what proves it is harmless is the 2 023-test suite and the unchanged corpus.

## Deployed 2026-08-26 05:44 UTC

Same rebuild as the Q34 floor. Verified inside the DEPLOYED image rather than the working tree:

```
bands loaded: yes  IdF threshold: 36144 EUR
classifier armed: yes
```

**The full sabotage ledger was NOT run locally.** This item touches
`src/php/Core/TenureClassifier.php` and the corpus, both on `CLAUDE.md`'s mandatory trigger list, and
a local ledger run is ~5 h on this debug build. `gh` is not installed here, so the on-demand
`workflow_dispatch` route was unavailable; the judge is therefore the **03:00 UTC nightly**. What was
run: the 8 new cases individually (all detected), `test-sabotage-applies.sh` over all 481 expressions,
and the full 2 023-test suite.

> **The nightly judged it — noted 2026-08-29.** Green nightlies on 2026-08-27 and 2026-08-28 and a
> local full run on the 28th (506/0, `var/claude/sabotage-ledger-20260828.log`) all include this
> change. Then on 2026-08-29 the nightly was CUT OFF at 90 minutes by its own `timeout-minutes` —
> a budget the ledger had outgrown — and the notice step did not fire, because a timeout is a
> `cancelled` job and the step was gated on `failure()`. Both fixed the same day
> (`.github/workflows/ci.yml`, pinned by `tests/test-ci-workflow.sh`). Nothing about tier 4 was
> in question; the judge was.
