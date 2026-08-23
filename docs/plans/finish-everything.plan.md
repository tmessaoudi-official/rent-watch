# Finish everything except phorj

> **Written 2026-08-23** on the developer's instruction: *"let's finish everything! except for phorj!
> because phorj is not ready."* This file is the durable record of what "everything" means, because
> the instruction arrived immediately before a `/compact` and a scope held only in context is a
> scope that does not survive one.

## What is already finished (do not redo)

Spec milestones **1–5 are done and in production**: the pure core, the tenure classifier, In'li,
health + `doctor`, and CDC Habitat. Milestone **7 is measured out** — 8 of 15 catalogued landlords
are recorded NOT POLLABLE with evidence in `docs/SOURCES.md`, and all four worth building are live
(In'li, CDC Habitat, Cityloger, Logirep). Detail hydration (phases 2 and 2b) is built, certified and
**deployed**; production ran v4 → v6 on 2026-08-23 and converged to `478 annonce(s) · 82
correspondance(s), 10 à vérifier` with In'li at 167/168 detail pages cached.

`src/phorj/` is **OUT OF SCOPE by this instruction** — not deprioritised into a later phase of this
plan, simply not in it. `docs/PHORJ-REQUIREMENTS.md` stays the record of what it would need.

## The two buckets, and why the split is the whole plan

**Everything remaining divides on one question: does it need something only the developer can
supply?** That is not a scheduling convenience — it is the difference between work that can proceed
autonomously tonight and work that cannot start at all. Bucket A is done first *because* it needs
nobody; Bucket B is written down now so the inputs can be gathered in parallel rather than
discovered one at a time.

### Bucket A — unblocked, no inputs required

| # | Item | Size | Where it is recorded |
|---|---|---|---|
| A1 | Classifier performance (~155 ms/listing) | S | `milestone-1-pipeline.plan.md` — the table's one explicitly unblocked row; the `src/` freeze that deferred it is lifted |
| A2 | `scout digest` on demand | S–M | Q34 **RULED** it real: on-demand emission, "only what is new since the last successful emission", entries marked emitted only after the channel confirms delivery, unsent digest retried next run |
| A3 | `scout reclassify [--since]` | M | Q35 **RULED** it real. Its stub names raw-text storage as the blocker — **schema v6 `listing_detail.fields_json` now stores exactly that** for hydrated listings, so the stated blocker is half gone |
| A4 | Final MAXIMAL certification round | M | Three lenses, two consecutive clean rounds, against a frozen commit |

### Bucket B — blocked on four inputs, each supplying something no default can invent

| # | Input needed | Unlocks | Size |
|---|---|---|---|
| B1 | IMAP credentials **and one real portal alert email** | The first `email_alert` portal, and with it all eleven Tier B portals — spec milestone 6 | M |
| B2 | `plafonds de ressources` figures, **both halves** (LLI *and* PLAI/PLUS/PLS, per zone and household, with their year) | Classifier tier 4. The rung, the injectable table and a guard test are already wired; `PlafondBands` ships empty by policy | S |
| B3 | IDFM/PRIM API key | `Enrich/transit` + `Enrich/geo` — the only spec layer with no code at all | M |
| B4 | An authenticated DevTools cURL capture of AL'in | A4 AL'in, the ONLY route to the Action Logement ESH stock (A5, A6 and A8 all dead-end there) | M |

**Hard rule 1 is why B2 and B4 are inputs rather than tasks.** An endpoint or a plafond figure
written from memory is forbidden here, and a plausible invented number is worse than an absent tier
because it fails silently in the direction of a wrong verdict.

## Sequence

1. **Bucket A, in order A1 → A2 → A3.** Each is independently shippable; commit and push each on its
   own so a red build names one change.
2. **Ask for the Bucket B inputs once**, as a single question with all four named — not four
   questions spread over four sessions.
3. **A4 last, against a frozen commit**, covering everything that landed. A round run on a moving
   tree cannot count toward the two-clean requirement, and two panels for one milestone is the waste
   that rule exists to prevent.

## Standing constraints that govern every item here

- **§1 is not negotiable.** No item below may add a config key, flag, env var or default that can
  re-enable `PLAI`/`PLUS`/`PLS`/`ANRU`/`ANAH`. A2 and A3 both touch the digest, which is §1's only
  landing zone — a `reclassify` that can flip a verdict is a §1 surface, not a convenience command.
- **TDD, and the test must be seen red for the stated reason** before the implementation exists.
- **The sabotage ledger must gain a case for any new guarantee**, and a green suite is not evidence
  the guarantee is watched.
- Conventional commits; `master` only; plain `git push`; no `Co-Authored-By`, no `Claude-Session`.

## Decisions Log

- [2026-08-23 17:30] AGREED: finish everything remaining EXCEPT `src/phorj/`, which is not ready and is out of scope for this plan.
- [2026-08-23 17:30] AGREED: the work splits into Bucket A (no inputs, proceeds autonomously) and Bucket B (four developer-supplied inputs), and Bucket A runs first precisely because it needs nobody.
- [2026-08-23 17:30] AGREED: the four Bucket B inputs are asked for ONCE, together, rather than discovered one per session.
- [2026-08-23 17:30] NOTED: `scout reclassify`'s recorded blocker is half retired — schema v6's `listing_detail.fields_json` persists the raw fields the stub says `listings` does not keep. Verify before sizing A3.
