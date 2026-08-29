# Filter catalogue — every dimension we could filter or score on

> Requested 2026-08-06: *"i should be able to filter with everything — so i want you to enumerate all
> possible filters we can use"*.
>
> **SETTLED 2026-08-07.** Rows F1–F9 and S1–S8 are now RULINGS — see `docs/OPEN-QUESTIONS.md`
> Part 1, and `config/rent/criteria.json`, which is their implementation and the authority on any
> disagreement. This file stays the *menu* of what could be filtered, and the rows below that are
> not in `criteria.json` are candidates rather than behaviour.
>
> **This is the menu, not the order.** Nothing in the un-ruled rows is decided. Each row says what the filter is, whether
> it works as a **hard disqualifier** (reject, log only) or a **score component** (rank, never reject),
> and — the column that actually matters — **how often the data is even present in a listing**.
>
> `CLAUDE.md` hard rule 8 keeps disqualifiers and scores separate; hard rule 9 says `None` is not zero.
> Those two rules are why the *availability* column decides more than the *want* column: **a hard filter
> on a field that is usually absent silently rejects almost everything, and you never find out**, because
> nothing arrives. That failure is invisible by construction. When in doubt, make it a score.

**Availability legend** — my estimate of how often a field is usable, to be replaced with measured
numbers once `scout --domain=rent dump` has run against real payloads. `[Unverified]` throughout: these are
expectations from how listing sites are structured, not counts.

- **A** — nearly always present, structured
- **B** — usually present, sometimes only in free text
- **C** — often missing; regex-sniffed from the description at best
- **D** — rarely present; treat as unknown by default

---

## 1. Tenure & eligibility — the non-negotiable group

| # | Filter | Kind | Avail | Notes |
|---|---|---|---|---|
| T1 | **Tenure class** (LLI · LIBRE · PLS · PLUS · PLAI · ANRU · ANAH · conventionné · UNKNOWN) | **Hard** | B | Settled: LLI + LIBRE in, rest out. **Not user-overridable** (`CLAUDE.md` §1). |
| T2 | Classifier confidence < 0.6 on a mixed source → `UNKNOWN` → digest | **Hard** | A (computed) | The fail-closed rule. |
| T3 | Confidence as a ranking penalty | Score | A | An `LLI` at 0.65 and one at 0.98 should not rank equally. Proposed, not in the brief. |
| T4 | Requires SNE / numéro unique | **Hard reject** | C | Procedural tell — social regardless of the label. |
| T5 | Requires a commission d'attribution | **Hard reject** | C | Same. |
| T6 | Income ceiling quoted (plafond de ressources) | Signal + score | C | Which band it matches is classifier signal priority 4. |
| T7 | Action Logement employer reservation | Score / flag | C | Not a reject — you may qualify. Surfacing it tells you to mention your employer. |
| T8 | Dossier requirements (garant, revenus ×N) | Score / flag | C | "revenus ≥ 3× loyer" tells you if applying is realistic. |

## 2. Geography

| # | Filter | Kind | Avail | Notes |
|---|---|---|---|---|
| G1 | **Commune** allow-list | **Hard** | A | Must match a structured field, **not** a substring over the whole blob — the prototype's bug. |
| G2 | Commune **preference rank** | Score | A | Needs an ordered list, not today's flat set. |
| G3 | Postcode / CP prefix | **Hard** | A | Compare as a **string** — `"09xxx"` loses its leading zero as an int. |
| G4 | Département | **Hard** | A | **78 / 95** — ruled 2026-08-07 (F3). `92` was removed: every commune in the filter is in 78 or 95, verified against `geo.api.gouv.fr`, so `92` could never admit anything the commune filter also admitted. The prefix's real job is rejecting a same-named commune elsewhere in France. |
| G5 | INSEE code | **Hard** (canonical) | B | More reliable than the name; needs `enrich/geo`. |
| G6 | Quartier | Score | C | High local value, low availability. |
| G7 | Radius from a point (lat/lon + km) | **Hard** or score | B | More natural than a commune list for "20 min from home". Needs geocoding. |
| G8 | Exclusion zones (a street/quartier to avoid) | **Hard reject** | C | You know the local ones. |
| G9 | Distance to a named school | Score | D | Needs an external dataset. Later. |
| G10 | Same commune as now | Score | A | Cheap bonus if staying put has value. |

## 3. Transport — needs `enrich/transit` (IDFM / PRIM)

| # | Filter | Kind | Avail | Notes |
|---|---|---|---|---|
| X1 | Door-to-door commute time to a named station/address | **Hard** or score | computed | Most useful filter for daily life; most work to build. |
| X2 | Walk time to the nearest station | Score | computed | |
| X3 | Number of changes | Score | computed | 40 min direct beats 30 min with two changes. |
| X4 | Specific line access (RER A, L, J, Transilien) | **Hard** or score | computed | |
| X5 | Motorway access / car commute | Score | computed | Only if you drive. |
| X6 | Parking included | Score | C | Also a cost question — see M8. |

## 4. Size & layout

| # | Filter | Kind | Avail | Notes |
|---|---|---|---|---|
| S1 | **Rooms** (pièces) | **Hard** | A | ⚠️ `None` means unknown, never "below minimum". |
| S2 | **Living surface** m² | **Hard** | A | Same `None` rule. |
| S3 | Bedrooms (chambres ≠ pièces) | **Hard** or score | B | Often more meaningful than `pièces` for a family. |
| S4 | Surface per person | Score | computed | |
| S5 | Separate vs open kitchen | Score | C | |
| S6 | Bathrooms / WC séparé | Score | C | Matters with children. |
| S7 | Balcony / terrace + its m² | Score | C | |
| S8 | Garden / private outdoor | Score | C | |
| S9 | Cellar / storage | Score | C | |
| S10 | Dual-aspect / orientation | Score | D | High comfort value, rarely structured. |
| S11 | Ceiling height / atypical | Score | D | |

## 5. Money

| # | Filter | Kind | Avail | Notes |
|---|---|---|---|---|
| M1 | **Rent charges comprises (CC)** | **Hard** | A/B | ⚠️ The #1 correctness trap. Sources disagree CC vs HC; each must declare which it reports and be **normalised** before comparison. |
| M2 | Rent hors charges | derived | A/B | Keep both, compare on CC. |
| M3 | Charges amount + what they cover | Score | C | |
| M4 | Ceiling **tolerance** band (+10 % at reduced score) | Score | computed | The Q2 decision. |
| M5 | Headroom below the ceiling | Score | computed | Good tiebreaker. |
| M6 | €/m² | Score | computed | Catches overpriced small flats a flat cap misses. |
| M7 | **Agency fees** | **Hard** or score | C | LLI and PAP have none; agencies do. Real cash. |
| M8 | Parking billed separately | Score | D | |
| M9 | Deposit months | Score | C | Cash-flow relevant. |
| M10 | Heating billing (individual vs collective) | Score | D | Large hidden cost. |
| M11 | **Rent drop on a known listing** | Event → notify | computed | Required, brief §7. Needs price history. |

## 6. Building, floor & accessibility

| # | Filter | Kind | Avail | Notes |
|---|---|---|---|---|
| B1 | **Floor** | Score (recommended) | B/C | ⚠️ `floor == 0` (RDC) is falsy but real. Hard-rejecting drops good flats whose listing omits the lift. |
| B2 | **Elevator** | Score | C | ⚠️ `False` and `None` are different facts. |
| B3 | Floor + elevator combined | Score | computed | "≤1 **or** lift present" — the actual constraint. |
| B4 | Step-free / pushchair access | Score | D | The real need behind B1/B2. |
| B5 | Construction era | Score | C | Proxy for insulation, noise, charges. |
| B6 | Units in the building | Score | D | |
| B7 | Gardien | Score | D | |
| B8 | Bike storage | Score | D | |
| B9 | Secured entry | Score | D | |

## 7. Condition, energy & comfort

| # | Filter | Kind | Avail | Notes |
|---|---|---|---|---|
| E1 | **DPE class** (A–G) | **Hard** or score | B | Legally required in listings, so availability is decent. **F and G are rent-frozen / being phased out of the rental market** — a strong signal beyond comfort. |
| E2 | GES class | Score | B | |
| E3 | Estimated annual energy cost | Score | C | Usually a range in the DPE block. |
| E4 | Heating type | Score | C | Individual gas usually cheapest to run; collective is a charges risk. |
| E5 | Double glazing | Score | D | |
| E6 | Renovation state | Score | C | |
| E7 | Air conditioning | Score | D | |
| E8 | Fibre availability | Score | D | Matters for a developer. |

## 8. Availability & timing

| # | Filter | Kind | Avail | Notes |
|---|---|---|---|---|
| D1 | Available-from date | **Hard** or score | B | A flat free in 6 months is not the same find as one free now. |
| D2 | **Freshness** — first seen < 1 h | Score | computed | The brief's speed premise. |
| D3 | Lease type (vide 3 ans / bail mobilité) | **Hard** | B | Bail mobilité is short-term — likely exclude. |
| D4 | Already let / visits closed | **Hard reject** | C | Avoid dead listings. |
| D5 | Listing age at source | Score | B | An old listing still up may be a problem flat. |
| D6 | Re-listing detection | dedup | computed | "Back on the market" vs "new". |

## 9. Hard exclusions

| # | Filter | Notes |
|---|---|---|
| R1 | Colocation | In F9 today. |
| R2 | **Meublé** | ⚠️ **Current regex bug**: `meubl` also matches *"cuisine meublée"* — a fitted kitchen in an unfurnished flat, i.e. a desirable listing wrongly rejected. Must anchor to the property *type*, not any occurrence. |
| R3 | Résidence étudiante / senior / services | In F9. |
| R4 | Foyer / EHPAD | Add. |
| R5 | Sous-location | Add. |
| R6 | Bail mobilité / saisonnier | Add. |
| R7 | Parking / box / cave-only | Add — they pollute results. |
| R8 | Local commercial / bureau | Add. |
| R9 | Viager / vente (a sale) | Add. |
| R10 | LMNP / investment products | Add. |
| R11 | Loge de gardien | Add. |
| R12 | Landlord/agency blocklist | Yours to fill after a bad experience. |
| R13 | Scam patterns (no visit, upfront transfer, off-platform contact) | Genuinely useful on Leboncoin. |

## 10. Source & trust

| # | Filter | Kind | Avail | Notes |
|---|---|---|---|---|
| P1 | **Track** (Intermediate vs Private) | **Routing** | A | Drives which mailbox and which notification. |
| P2 | Source allow/deny per run | operational | A | `--only inli`, `--track private`. |
| P3 | Private landlord vs agency | Score | B | No fees, often a faster answer. |
| P4 | Duplicate across portals | dedup | computed | Cluster, keep every URL, notify once. |
| P5 | Photo count / floor plan present | Score | B | 1 photo and no plan is usually worse. |
| P6 | Description length | Score | A | Weak but free signal. |
| P7 | Source historic false-positive rate | Score | computed | Long-term refinement. |

## 11. Household fit — your side, not the listing's

| # | Filter | Notes |
|---|---|---|
| H1 | Household composition (2 adults + 2 children) | Drives S3/S4 and the LLI ceiling band. |
| H2 | Pets allowed | Only if relevant. |
| H3 | Home-office space | Feeds S3/S5. |
| H4 | **Two-workplace commute** (both adults) | X1 twice, combined — a real constraint most tools ignore. |

## 12. Operational — not about listings

| # | Control | Notes |
|---|---|---|
| O1 | `--once` / `--watch` + interval and jitter | Requested explicitly. |
| O2 | `--track intermediate\|private\|both` | The two-track split. |
| O3 | `--only <source>` / `--dry-run` / `--no-state` | ⚠️ `--no-state` re-notifies everything; guard it. |
| O4 | Score threshold for push vs digest | |
| O5 | Quiet hours (no push 22:00–08:00) | Worth having, given `--watch`. |
| O6 | Per-source rate limit + jitter | Politeness, hard rule 5. |
| O7 | `SOURCE_BROKEN` alerting | Required, brief §8. |

---

## The three that will actually bite

Everything above is a menu. These three are correctness, not preference:

1. **M1 — CC vs HC.** Get it wrong and every rent comparison is wrong in the direction that *silently
   rejects* affordable flats. Nothing arrives and you conclude the market is empty.
2. **R2 — the `meubl` regex.** It rejects *"cuisine meublée"* today. A real bug in the prototype,
   inherited by anything copied from it.
3. **B1/B2 — floor and elevator as hard rejects.** The prototype rejects a 3rd floor whenever the listing
   simply doesn't mention the lift, which is most of the time. `None` is not "no lift".

All three are the same underlying mistake: **treating absent data as a negative fact.** That is hard
rule 9, and it is the likeliest reason this tool would quietly under-deliver.
