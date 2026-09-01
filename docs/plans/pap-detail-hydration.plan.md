# PAP detail hydration — giving an email source the words its alert omits

> Developer ruling, 2026-09-01: **PAP only**, logirep decided separately.

## Why

The developer reported colocations arriving from PAP. Confirmed by fetching one real listing
(robots-checked, single request, honest UA):

```
alert says   : Location appartement · Massy 91300 · 84 m² · 5 pièces · 650 EUR
detail page  : "Location meublée appartement 5 pièces 84 m² Massy (91300)"
               "Colocation Massy. Colocation 4 chambres – appartement rénové & meublé"
               "Bail individuel pour chaque chambre"   "Chambre 1 – 11,5 m² - 750€"
```

The 650 € is ONE ROOM. On the detail page: `colocation` ×6, `meublé` ×10, `chambre` ×15 —
`exclude_patterns` would reject it instantly. None of it is in the alert.

**Three independent defences are all inert on PAP**, measured:

1. **No listing prose.** The alert body is header + the subscriber's own criteria line + four
   structured facts + a link + a legal footer. `exclude_patterns` scans title and description and
   finds only boilerplate — so `colocation`, `coloc`, `coliving` and the meublé family can never
   fire on this source.
2. **A bare title.** The current template yields `Location appartement`, so
   `exclude_title_patterns` (the `chambre` family) has nothing to match. The SUBJECT is no better:
   all 56 stored PAP subjects say `Appartement` or `Maison` — PAP types a shared-flat room as an
   apartment.
3. **The one numeric heuristic built for this is inert by design.** `CriteriaEngine::pricePerM2()`
   reads `effectiveRentCc()`; PAP is HC-only (`rentCc=NULL, rentHc=650`), so it returns `null`.

**The numeric route is dead, and that is measured rather than assumed.** Over the 164 HC-only rows
carrying rent+surface+rooms, the cheapest are genuine cheap RURAL Logirep stock —
Châtillon-sur-Indre 3.65, Le Blanc 4.28, Buzançais 4.41 €/m² — while Massy sits at 7.74. Any
threshold catching Massy eats those first, and the distribution has no gap to anchor on (p5 4.73,
p10 5.54, median 8.44). Same shape as the documented reason `min_price_per_m2` cannot reach the
Champs-sur-Marne pair.

**This was missed by the Track 5b sweep**, and the way it was missed is the lesson: that sweep
asked whether the criteria line corrupted PAP's NUMBERS — it does not, the positional anchors hold
— and never asked what the description was actually set to. The right question was one query away.

## Scope ruling

**PAP only.** The developer's instinct was "hydrate all", and testing the premise collapsed it:

| source | why it lacks text | volume | risk |
|---|---|---|---|
| PAP | the alert carries no listing prose | ~5–8 new/day | the live defect |
| logirep | its JSON payload has **no description field** (all 22 checked) | 113 per poll | different problem |

Logirep is an institutional landlord (Polylogis); colocation risk there is nil. Its missing
description costs TENURE evidence, and `mixed_tenure: true` already fails closed, so those listings
land in the digest rather than being wrongly matched — a yield problem, not a §1 hole. It gets its
own measurement and its own decision.

## Design

**Extract, do not duplicate.** The hydration block in `HtmlSource` (lines ~408–700: `hydrate`,
`mayAttempt`, `rankedForHydration`, `withDetail`, `mergeDetail`, `detailFields`) becomes a
collaborator both adapters compose. Duplicating it would create a SECOND implementation of a
§1-adjacent path — the hydrated description is what the tenure classifier reads — and this repo
already rules that such a path gets exactly one implementation (`ListingMapper`, hard rule 9).

Every guarantee travels unchanged and is already covered: the cache is the gate (`listing_detail`,
keyed on `(source, external_id)`, never on `dedup_key`); the schema-v6 map fingerprint stops a
widened map serving rows captured under the old one; the per-pass budget bounds a cold start; an
explicit `detail_budget_per_pass: 0` is refused; a per-listing fetch failure is RECORDED with its
attempt count and backoff rather than voiding the pass, while config-shaped failures still throw.

**New for the email path:** `EmailAlertSource` gains an `HttpClient` and a `Robots` verdict, which
it does not have today. Both are injected, so `SCOUT_OFFLINE=1` keeps covering the suite
structurally.

**Posture.** Hard rule 4 makes email ingestion primary *because there is no bot*, and this adds
requests to one email source. It stays inside hard rule 5: `www.pap.fr/robots.txt` was read first
and allows `/annonces/` for a generic agent (its disallows name specific scraper bots); the User-Agent
identifies honestly; the rate is one request per NEW listing, ~5–8/day, and zero in steady state.

## Known cost before starting

`grep -c 'Rent/Adapters/HtmlSource.php' tests/sabotage-check.sh` answers **18**, and that is the
number to ignore — the 2026-09-01 `RunStore` split proved the expressions target code INSIDE the
moved bodies, which names no method. Only `tests/test-sabotage-applies.sh` answers the real
question. Retarget the file path, never the expression; verify each individually; each must match
in the new file AND not in the old.

## Steps

1. Extract the hydration collaborator; `HtmlSource` delegates. Suite green, ledger expressions
   retargeted and individually verified.
2. `EmailAlertSource` composes it; constructor gains the client and the robots verdict; every
   construction site updated (`RentScout`, tests).
3. PAP gains a `detail_map` in `config/rent/sources.json` — the description at minimum, so
   `exclude_patterns` starts working; a title too, so `exclude_title_patterns` does.
4. Fixture: the frozen PAP detail page, scrubbed. Tests: a colocation listing is REJECTED where it
   previously matched, and the counterweight — an ordinary flat still matches.
5. Sabotage: drop the hydration from the email path and the colocation is notified again.

## Decisions Log

- [2026-09-01 15:1x] AGREED: PAP alerts carry no listing prose, so the detail page is the only route
  to the words; hydrate PAP. Confirmed against a real listing rather than inferred.
- [2026-09-01 15:1x] AGREED: **PAP only** — logirep's missing description is a yield problem behind
  a fail-closed classifier and 15× the request footprint; it is decided separately.
- [2026-09-01 15:1x] DECIDED (engineering, not asked): EXTRACT the hydration rather than duplicate
  it, because the hydrated description feeds the tenure classifier and a second implementation of a
  §1-adjacent path is what this repo's one-implementation rule exists to prevent.
