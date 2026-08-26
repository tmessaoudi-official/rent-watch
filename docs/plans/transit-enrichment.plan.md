# Transit enrichment (IDFM/PRIM) Plan

> **Built 2026-08-26.** `src/php/Enrich/` was the last spec layer with no code at all. It exists
> because nothing in the score discriminated: 83 live matches spread across all eight departements
> scored 16–48, so `high_priority_score: 70` could never fire and the `!!` marker was dead.

## Verified against the live API (hard rule 1)

```
base    : https://prim.iledefrance-mobilites.fr/marketplace/v2/navitia
places  : places?q=<commune> <postcode>&type[]=address
journeys: journeys?from=<lon>;<lat>&to=<lon>;<lat>&datetime=YYYYMMDDTHHMMSS
header  : apikey
returns : journeys[].duration in SECONDS
quota   : 20 000 requests/day (documented); ~40 communes ≈ 80 requests, once
```

Three details are easy to get backwards and each is silent when wrong: **longitude first**,
**seconds not minutes**, and the **`apikey` header**. A reversed coordinate pair still returns a
perfectly plausible journey — between two other places entirely. All three are sabotage cases.

## THE KEY WAS SHADOWED, and the cause was a bad instruction

`.env` had two `IDFM_API_KEY=` lines — the empty template default, and the pasted value appended
after it. `Config\DotEnv` applies the FIRST occurrence and `continue`s on any later one, and an
empty string counts as set, so the real key could never be read. The API said so plainly:
`{"message":"No API key found in request"}`.

**Never hand out `>> .env` for a key that already has a template line.** Edit the line in place.

## The curve was wrong, and only measurement showed it

The obvious design — `max_minutes` as the zero point of a scale starting at 0 — assumes commutes
shorter than the ceiling are common. Measured against the live API, the affordable communes run
**68 to 131 minutes** to the destination:

| commune | minutes | ceiling-as-zero | ceiling-as-full |
|---|---|---|---|
| Sartrouville | 68 | 3/30 → score 41 | 30/30 → score 67 |
| Aulnay-sous-Bois | 88 | 0/30 → score 38 | 25/30 → score 62 |
| Dammarie-les-Lys | 112 | 0/30 → score 38 | 15/30 → score 52 |
| Dourdan | 131 | 0/30 → score 38 | 8/30 → score 46 |

Under the first curve the component separated the whole set by **three points** while adding 30 to
`positiveTotal()` — so every score in the tree dropped by about a quarter and the ordering barely
moved. **It made the problem it was built for worse.** The shipped curve is: at or under
`max_minutes` is full marks (the plain meaning of *"1h15 max"*), decaying linearly to zero at twice
it. Same data, **21 points of spread instead of 3**, and Sartrouville finally reaches 67 against the
dead 70 threshold.

The ties this creates below the ceiling are theoretical: nothing in the affordable set is under 68.

## Decisions Log

- [2026-08-26 15:10] AGREED (developer): `weights.commute: 30` — the largest single component, ahead of commune's 25. Chosen so it is the dominant factor, because nothing else separates the matches.
- [2026-08-26 15:10] AGREED (developer): 75 minutes maximum, verbatim *"but keep showing even those with more anyway"* — so it is a SCORE component and never a disqualifier, which is hard rule 8 independently.
- [2026-08-26 15:10] AGREED (developer): the destination is stored in the gitignored `config/criteria.local.json`, not the committed file. `deepMerge()` is recursive, so overriding `weights.commute` alone does not restate the other weights — verified.
- [2026-08-26 15:40] AGREED: `commuteMinutes` lives on `RawListing`, not as a `judge()` argument, because `scout reclassify` re-judges from the v7 snapshot and a value passed alongside would be absent on every re-judge. `floor` and `hasElevator` arrive by the same route.
- [2026-08-26 15:40] AGREED: enrichment runs BEFORE clustering — before the snapshot is written and before any disqualifier (hard rule 8), and upstream of clustering because `$observed` is keyed on object identity.
- [2026-08-26 16:05] CORRECTED by measurement: `max_minutes` is FULL MARKS, not the zero point. See the table above. The first curve was built, measured, and found to make the score worse; this is why the component was probed against the live API before being trusted.
- [2026-08-26 16:05] AGREED: a failed lookup is NOT cached. Caching it turns one bad afternoon at the API into a permanently missing component for that commune, and nothing would ever retry.
- [2026-08-26 16:05] AGREED: the reference departure is FIXED (next weekday 08:30), not "now". Cached durations must be measured against one timetable or a commune resolved at 02:00 is incomparable with one resolved at 08:30 — on the heaviest component in the score. Stated cost: every duration is a one-time sample of that departure, so it reflects neither the hour a listing was found nor a strike.

- [2026-08-26 16:40] FIXED, raised at the 6C gate: **the cache had no destination fingerprint.** Keyed on `(commune, postcode)` alone, it would answer with the journey to a PREVIOUS address for ever the day the destination changed — plausible numbers, confident reasons, nothing expiring, and failures deliberately not cached. Schema v10 adds `commute_cache.destination`, a SHA-1 of the address (hashed rather than stored, because it is personal and store rows end up in diagnostics and backups); a mismatch reads as *not cached*, so the commune re-resolves lazily. Exactly the schema-v6 detail-map fingerprint on a new surface.
- [2026-08-26 16:40] NOTED: the sabotage for it targets the FINGERPRINT, not the SQL. Removing the `WHERE … destination = :d` clause leaves `:d` bound and unused, so the suite would go red on a PDO error rather than on the guarantee — red for an unrelated reason proves nothing. A constant key is the same defect with no crash to hide behind.
- [2026-08-26 16:40] VERIFIED: the reference departure cannot go stale. `onePass()` builds the Pipeline — and therefore the planner and its `nextWeekdayAt('08:30')` — inside the watch loop's per-pass closure, so it is recomputed every pass rather than frozen at watcher startup.
