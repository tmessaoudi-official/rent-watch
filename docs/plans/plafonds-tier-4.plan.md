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

## Formal Plan

*(written at Phase 4)*
