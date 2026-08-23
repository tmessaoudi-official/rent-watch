# Phase 2b — In'li floor + lift from description prose

## Why

Phase 2 gave In'li a `detail_map` carrying `title` and `description`. Live acceptance on
2026-08-23 hydrated 20 real detail pages and measured what else those pages state:

- **18 of 20 mention `ascenseur`**
- **19 of 20 state a floor**

Neither is mapped, so both stay `null`. In'li is roughly **two thirds of all matches**, and floor
and lift are score components (Q5), so this is the largest yield still reachable without a new
input.

> **CLAUDE.md claimed In'li "publishes no lift at all". That was measured on ONE page.** The frozen
> fixture contains neither word. This is a generalisation from n=1 — the same error class as the
> retired *"live yield is 0"* claim. Corrected in `CLAUDE.md`; the test docblock repeating it is
> fixed in this change.

## The defect this uncovered (fix first, independently)

`Payload::floor()` returns **4 for a flat on the 3rd floor**, measured:

```
"au 3? étage d'un immeuble de 4 étages"   ->  floor = 4      (want: null)
```

In'li's own page carries a typo (`3?`). The regex `(\d+)\s*(?:er|ere|eme|e)?\s*etage` fails on it,
then matches the NEXT occurrence — `de 4 étage**s**`, the **building's height**. `PRV-060708`
returns the correct 12 only because the flat's floor happens to be worded first; that is word order,
not a rule.

**Minimal repair: require the singular — `etage\b`.** A building height is always plural
(`de N étages`), a flat's floor always singular. Measured:

| case | old | new | want |
|---|---|---|---|
| `au 3? étage d'un immeuble de 4 étages` | 4 | **null** | null |
| `se situe au 12e étage … de 18 étages` | 12 | 12 | 12 |
| CDC card `3 pièces - 4ème étage - 82m²` | 4 | 4 | 4 |
| `immeuble de 6 étages` | 6 | **null** | null |

This is deliberately NOT the position anchor (`au|en`): CDC's card has no preposition, so anchoring
in the shared reader would regress a live source. The anchored reader is separate and opt-in.

## Design

### 1. `Core/Prose` — a new pure, opt-in reader

`Prose::floor(string): ?int` and `Prose::elevator(string): ?bool`. Pure, no I/O, French-specific —
same shape and same home as `TenureClassifier`.

**Floor: position, never count.**

- Match `au|en` + ordinal + singular `étage`; and `rez-de-chaussée` / `RDC`.
- **Never** match `de N étages` (plural = the building's height).
- Ordinals in digits (`3e`, `2ème`) and spelled (`troisième`, `deuxième`). **Ordinals only** —
  for spelled numbers the ordinal/cardinal distinction does the discriminating for free:
  `au troisième étage` is a position, `de trois étages` is a count.
- **Ambiguity → the anchored `étage` match wins over an `rdc` match.** `des commerces en
  rez-de-chaussée` is common French listing furniture and would otherwise report RDC for a 5th-floor
  flat — a wrong *displayed* fact. An `rdc` with no anchored floor and a commerce/local/parking word
  within a short window returns `null` rather than 0.
- **Under-extract the hard cases on purpose**: the bare ordinal (`cet appartement est situé au
  deuxième.`) is skipped, and the `3?` site typo is not encoded into the grammar. `null` is the safe
  direction (hard rule 9); a typo is one listing.

**Lift: negation FIRST.**

Precedence is not stylistic. A wrong `false` lowers the score → less likely to notify → the safe
direction. A wrong `true` awards a bonus for a lift that does not exist. So: any negation near
`ascenseur` → `false`; else a positive → `true`; else `null`.

Real negations in the 20 pages — **5 of 18** — are varied: `sans ascenseur ni régisseur`,
`Aucun ascenseur dans la résidence`, `ne dispose pas d'ascenseur`, `ne dispose pas d’ascenseur`
(curly apostrophe), `Pas d'ascenseur`. `Text::fold` already normalises U+2019 to `'` [verified].

The negation window is anchored to `ascenseur` itself, so `SANS FRAIS DE DOSSIER` — which appears in
these very descriptions — cannot trip it.

**`Payload::bool()` cannot do this and must not be extended to.** It matches the whole trimmed
string, so prose returns `null` today (safe). A substring reader would read *"Aucun ascenseur dans la
résidence"* as `true` — hard rule 9 inverted, manufacturing a fact from its own negation.

### 2. Wiring — explicit, and unknown readers REFUSE

`=> prose:floor` is unusable: `prose:floor` is itself a valid regex, so the parser cannot tell an
extractor from a capture. The field-map value becomes an optional object:

```json
"floor":    { "selector": ".advert-body-description p", "read": "prose_floor" },
"elevator": { "selector": ".advert-body-description p", "read": "prose_elevator" }
```

A string value keeps its current meaning exactly. An **unknown `read` name is refused at load** —
same asymmetry as the budget-zero refusal: a reader that silently does nothing is a disabled feature
wearing a configured one's clothes.

### 3. Cache staleness — schema v6 map fingerprint

**This is a hole in Phase 2's design that 2b exposes.** Detail rows are keyed `(source,
external_id)` and "a page already on record costs no request ever again". Adding `floor`/`elevator`
to the map therefore leaves every already-hydrated row serving title+description **forever** — no
refetch, no error, no signal.

Two mitigations, both taken:

- **Timing**: nothing is deployed. The prod container runs the pre-Phase-2 image and its DB is v4
  with zero `listing_detail` rows, so landing 2b before the push means no stale row ever exists.
  That is luck, not design.
- **Design**: schema **v6** adds `map_fingerprint` per row. A row whose fingerprint differs from the
  current `detail_map` is treated as **absent** and refetched under the existing budget and priority
  machinery — no new mechanism. Additive, re-runnable, same pattern as v5.

A stated cost saying "invalidate the cache by hand" would be forgotten; this session alone changed
In'li's map twice.

## Acceptance

- `Payload::floor()` returns `null`, not 4, for the building-height case
- `Prose` labelled against **all 20 captured descriptions** (`tests/fixtures/inli/descriptions.json`)
  plus synthetic traps — the fixture IS the corpus
- A v5 row with a stale fingerprint refetches; a current one does not
- Sabotage cases, each of which must turn the suite red:
  1. drop the singular `\b` (building height read as the tenant's floor)
  2. make lift positive-first (a negation read as a lift)
  3. let floor match plural `étages`
  4. drop the fingerprint check (stale rows served forever)
- Full suite green; drift-scan P0=0 P1=0

## Stated cost

Two of the 20 pages yield no floor by design (the bare ordinal and the site typo), and 2 of 20 state
no lift. `null` says nothing rather than saying no.

## Scope

Mechanism generic; **wiring In'li only** this round. CDC and Cityloger already read floor and lift
from structured fields and are untouched.

## Decisions Log

- [2026-08-23 07:45] AGREED: next work is Phase 2b — In'li floor + lift from description prose (developer choice at the direction gate).
- [2026-08-23 08:05] AGREED: `Payload::floor()` requires singular `etage\b`; the `au|en` position anchor stays OUT of the shared reader because CDC's card has no preposition and would regress.
- [2026-08-23 08:05] AGREED: lift extraction is negation-first — a wrong `false` lowers the score and is the safe direction; a wrong `true` awards a bonus for a lift that does not exist.
- [2026-08-23 08:05] AGREED: field-map values gain an optional object form with a named `read`; `=> prose:floor` is rejected because it is indistinguishable from a regex capture. An unknown `read` name refuses at load.
- [2026-08-23 08:05] AGREED: schema v6 adds a per-row detail-map fingerprint; a changed map makes cached rows refetch through the existing budget/priority path rather than being served stale forever.
- [2026-08-23 08:05] AGREED: the bare ordinal (`situé au deuxième`) and the `3?` site typo are deliberately NOT extracted — under-extraction is the safe direction.
- [2026-08-23 08:05] VERIFIED: all 168 In'li dedup keys are `id`-based, so hydration adding a title cannot re-key a listing; the pending push carries no mass-renotification risk.

## Outcome (2026-08-23)

**Built and accepted against the live site.**

- `Payload::floor()` defect fixed: singular `etage\b`. The wrong `4` is now a safe `null`.
- `Core\Prose` — position-only floor, negation-first lift. 20 captures, each hand-labelled.
- Wiring: reserved `=> prose:` capture prefix; unknown reader refuses at load.
- Schema **v6** map fingerprint; re-runnable ALTER (SQLite has no `ADD COLUMN IF NOT EXISTS`).
- **Live acceptance**: `scout doctor --source=inli` → 168 annonces, `ok`, schema v6. The widened map
  invalidated all 40 cached rows; 20 refetched this pass (the budget), and **live extraction agrees
  with the hand labels 20/20**.
- Suite **1728 green**, drift-scan P0=0 P1=0, five new sabotage cases all verified applied and
  detected (ledger now 350).

**Two defects found that were not in scope, both live:**

1. The adverb `plus` was demoting 4 of 40 hydrated matches to the digest — `title`/`description` now
   re-route to the prose scan. Third instance of the `au plus près` class.
2. In'li is **not pure LLI**. Two live listings state `plafond de ressources PLS`, which their cards
   never mentioned. Under §1 that is a reject, and only the detail page carries it.

**Still UNCERTIFIED-BY-EXECUTION:** the full 350-case sabotage ledger has not been re-run end to end
since these changes; only the five new cases plus the full unit suite have. CI runs it nightly.

- [2026-08-23 12:05] DONE: Phase 2b built, live-accepted 20/20 against hand labels.

## The §1 finding, and what it cost (2026-08-23)

Hydration proved In'li publishes **PLS** — two live listings state `plafond de ressources PLS`,
declared nowhere but the detail page. The source shipped `mixed_tenure: false`, so the fail-closed
rule was DISARMED on a source with proven social stock.

Arming it alone was not available: 166 of 168 listings sit at 50bp, so all of them — about two
thirds of the tree's yield — would digest for ever.

**Ruled (developer, at the direction gate): gate the source-default match on hydration.** The
fail-closed rule now reads the EVIDENCE rather than the source — weak label + mixed source + page
not yet read digests; a page read and found to say nothing excluding lets the default stand.

Measured live:

| state | matches | à vérifier |
|---|---|---|
| cold, 0 hydrated | 0 | 54 |
| drained, 167/168 read | 53 | 1 (the page that 404s) |

PLS rejected in both. Cost is a cold start only: matches are withheld until the backlog drains
(~9 passes at the 20/pass budget). Steady state is unchanged.

**Corpus:** 8 In'li cases are marked `detail_read: true` rather than flipped to DIGEST — flipping
would have KILLED the traps, since the fail-closed rule rewrites tenure to UNKNOWN and
`trap-001-plus-de-chambres` would then expect the same value whether or not the trap fired. The
default is `false`, the fail-closed direction. The spec's "pure-LLI source" shape is now a synthetic
source; no live source has it.

- [2026-08-23 13:40] AGREED: source-default matches are gated on hydration; In'li is `mixed_tenure: true`.
- [2026-08-23 13:40] DONE: verified live, cold 0/54 -> drained 53/1, PLS rejected throughout.

## Deployed (2026-08-23) — and what the seventeen-hour gap actually cost

Phase 2b was committed, pushed and green while the **running watcher was still the 2026-08-22 20:08
build**, which predates all of Phase 2. Production was at schema v4; the repo was at v6. The §1
fail-closed hydration gate was certified and unarmed at the same time.

**Audit of what the stale build let through** — cross-referencing the 54 notified In'li rows against
`/tmp/rw-gate.sqlite3`, the fully-drained acceptance database (168/168 detail pages read):

| prod verdict | hydrated verdict | n |
|---|---|---|
| `LLI` | `LLI` | 53 |
| `LLI` | `UNKNOWN` | 1 |

No social-housing listing reached the user — **and not because the classifier held.** Both proven
PLS listings (`PRV-317130`, `PRV-317131`, Viroflay) are 47.6 m² and 43.4 m², so `min_surface_m2: 50`
rejected them upstream of tenure; both show `notified_at` NULL in the production database. The one
`LLI → UNKNOWN` row is a listing notified as a match whose detail page 404s; under the gate it is a
digest entry.

**Deploy, executed in the advisor-recommended order** — build before stop, so a failed build cannot
leave production down, and rehearse the migration before it touches the real seen-set:

- `rent-watch:pre-v6` tagged — rollback is a retag, not a rebuild.
- Migration rehearsed on a `.backup` copy of the live database, opened offline with the new code:
  **v4 → v6, `listings` 478, `notified` 94, `price_history` 365, `source_runs` 309 all preserved**,
  journal still WAL.
- Graceful stop, second `.backup` to `state/rent-watch.sqlite3.pre-v6.1787492935.bak`, `up -d`.

**Verified live after the first pass:** `schema_version` = 6; `listing_detail` filling at 20/pass per
source (20 In'li + 20 Cityloger, 0 errors); notified count **unchanged at 94, 0 new** — the migration
caused no re-notification; the PLS pair now reads `UNKNOWN 0`, withheld by the gate.

The pass line went from `83 correspondance(s), 9 à vérifier` to `29 correspondance(s), 63 à vérifier`
over the identical 478 listings. That is the documented cold-start cost of gating a source-default
match on hydration, not a regression — the backlog drains at `detail_budget_per_pass` per source.

**UNCERTIFIED-BY-EXECUTION:** the 350-case sabotage ledger has not re-run end to end since Phase 2b;
this deploy rests on the 1731-case unit suite plus the five targeted cases verified at build time.
Nightly CI covers the ledger. Deploying on that evidence was the right trade — waiting left a §1 hole
armed-in-repo and absent-in-production for hours — but it is stated rather than implied.

- [2026-08-23 15:48] DONE: Phase 2/2b deployed; v4→v6 migrated in production, rehearsed first, backed up twice.
- [2026-08-23 15:52] VERIFIED: 53/54 notified In'li rows correct, 1 over-confident, 0 social-housing false positives; the PLS pair was stopped by `min_surface_m2`, not by tenure.
