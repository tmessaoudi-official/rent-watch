> 📦 **SUPERSEDED AND ARCHIVED 2026-09-02 (Track 6-C1).** This file was a SECOND live plan holding a
> ruling — `prose_absent` — outside `docs/plans/scout-unified-execution.plan.md`, which that file
> declares the single source of truth. Audit finding N7 flagged it; C1 discharges it. The ruling
> itself is unchanged and is summarised in the unified plan's Decisions Log, with `CLAUDE.md`
> § "PAP" carrying the operational version. **Nothing here is a live task.** Kept verbatim because
> its measurements — the three spaced Cloudflare probes, and the rent-per-room distribution that
> closed the last numeric route — are the evidence for a REFUSAL, and a refusal without its
> measurements invites the next session to retry it.

# PAP detail hydration — giving an email source the words its alert omits

> ⛔ **REFUSED BY HARD RULE 5 (2026-09-01, after the plan was written and step 1 was built).**
> PAP's detail pages sit behind a **Cloudflare bot challenge**. Everything below about *why* PAP
> needs hydration stands and was re-verified; the REMEDY does not. Do not retry it. What was
> actually built and ruled is in § "What happened instead" at the foot of this file.

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

---

## What happened instead — the measurement that refused the plan (2026-09-01)

**The plan's posture paragraph was half right, and the wrong half was the load-bearing one.** It
said `www.pap.fr/robots.txt` "allows `/annonces/` for a generic agent (its disallows name specific
scraper bots)". Reading the file rather than remembering it: the wildcard group is real, it is 30+
lines long, and its FIRST rule is

```
User-agent: *
Disallow: /*?*
```

**Every URL carrying a query string is refused**, and all 57 stored PAP listing URLs carry one —
`?a=…&email=<the subscriber, percent-encoded>&md5=…&utm_source=alerte_location`. Proven through the
project's own `Robots`, which does implement `*` wildcards:

| path | verdict |
|---|---|
| `/annonces/-r465002950?a=62367795&email=…%40gmail.com&md5=…` | **REFUSED** |
| `/annonces/-r465002950` | ALLOWED |

That alone was survivable — the query had to be stripped anyway, for a second and independent
reason: **hydrating the URL verbatim would send the subscriber's address to pap.fr on every detail
fetch.** That is the SeLoger per-recipient-redirect refusal in a different shape. The strip is a
provable no-op for the sources that hydrate today and mandatory for every email source:

```
card urls carrying a query   inli 0/519   cityloger 0/61   cdc 0/477   logirep 0/123
                             seloger 585/585   bienici 274/274   pap 57/57
```

**What refused the plan is the page itself.** With the query stripped:

```
t=0    GET /annonces/-r465002950                      301 → /annonces/appartement-yerres-91330-r465002950
       follow the redirect                            403  <title>Just a moment...</title>
                                                           script-src https://challenges.cloudflare.com
t=+45  GET the slug directly                          403  Just a moment...
t=+90  GET the slug directly                          403  Just a moment...
```

Three spaced probes, including the bare-id URL that had returned a clean `301` four minutes
earlier — so it is reputation-based rather than transient, and it is **this machine's IP**, the one
the watcher runs on. Hard rule 5 refuses to solve or evade a bot challenge; this is the **A15 Val
d'Oise Habitat precedent** (`/shield?u=…`) on a second source. It is a RULING, not a capability
limit. Do not revisit it with a headless browser, a different User-Agent, or a wait-and-retry.

### The numeric fallback is dead too, and this time it was measured

The plan already recorded that price-per-m² cannot separate a colocation here (Massy 7.74 €/m² sits
above genuinely cheap rural Logirep stock at 3.65–4.41). **Rent-per-ROOM was checked as the
remaining candidate and fails the same way.** Across the four private-market sources — where cheap
institutional stock does not exist, so the confounder the price-per-m² attempt hit is absent — the
low tail is a smooth continuum with no gap:

```
63, 71, 78, 84, 85, 90, 90, 91, 92, 92, 93, 94, 97×3, 98×3, 99, 100×4, 102×4, 103×4, …
```

The Massy colocation sits at 130 €/room, inside the densest part of that curve. Any threshold
catching it cuts through several dozen genuine listings. **Same negative as Track 1f, reached
independently on a different statistic** — which is worth more than the first one, because it says
the problem is the evidence rather than the choice of ratio.

### And the alert genuinely carries nothing else

Decoded end to end rather than taken from the plan's summary: header, the subscriber's own search
criteria line, four structured facts, the link, the legal footer. `description` is the string
`PAP.fr  De Particulier à Particulier ____` on **every** stored row, and `title` is `Location
appartement` or `Location maison` on every one. There is no listing prose anywhere in the payload.

## Developer ruling, 2026-09-01

- AGREED: the colocation problem is answered with a **CAVEAT LINE**, not a filter — PAP pushes say
  in as many words that the check could not be made. Nothing available can stop the colocations
  arriving; what was refused is pretending otherwise.
- AGREED: the extraction built as step 1 (`Adapters/DetailHydrator`) is **kept**, framed honestly as
  a standalone refactor rather than as step 1 of a feature. Its motivating consumer is refused.
- OFFERED AND NOT TAKEN (recorded so it is not mistaken for an oversight): folding two side findings
  into the same milestone. Both stay OPEN:
  1. **`Redact` misses a percent-encoded address.** `email=x@y.com` masks; `email=x%40y.com` does
     not — and `%40` is exactly the form PAP's URLs use. Latent today (no adapter error currently
     carries such a URL), a hard-rule-7 hole the day one does.
  2. **A followed redirect is never re-checked against `robots.txt`.** `HttpClient` follows up to
     three same-host redirects; only the ORIGINAL path is checked. Pre-existing in `HtmlSource`,
     not introduced by the extraction.

## Rules this episode adds

- **`robots.txt` must be READ, never recalled** — the plan's own posture paragraph paraphrased a
  file it had seen and inverted its first rule. Hard rule 1 is written about endpoints; it applies
  to the robots file with the same force, because a wrong reading here authorises the request rather
  than merely misaddressing it.
- **A single successful fetch does not establish that a source is fetchable.** The plan's evidence
  block cites one real listing retrieved earlier the same day. That request very likely carried the
  query string (and so was robots-refused as well as address-leaking), and the site's challenge had
  not yet fired. One 200 is n=1, and this repo has now paid for that four times.
- **A source's own URL can be the disclosure.** The check is not only *"do I follow a redirect that
  identifies the subscriber"* but *"does the URL I am about to request contain them"*. PAP puts the
  address in plaintext in the link it emails.

## The caveat line — design (ruled 2026-09-01, to build)

**Shape: a payload declaration on the SOURCE, carried on the LISTING, read by the engine.** Not a
hardcoded source name, and not a derived guess.

- `config/rent/sources.json` — a TOP-LEVEL source key `"prose_absent": true`, beside `mixed_tenure`
  and in the same class as it: a declaration about what the payload MEANS. Deliberately **not** a
  `params` entry, because `params` is a `stringMap` and a boolean does not belong in one.
- `SourceDefinition::$proseAbsent` (bool, default false). The loader reads it with `optBool`, so
  `Reader::done()`'s unknown-key refusal accepts it with no further change.
- **A guard, because a declaration that contradicts the config is worse than none:** `prose_absent:
  true` on a source whose `map.description` is non-empty is REFUSED at load. An email source maps no
  description (it is parsed), so the check binds on `html`/`json` — which is exactly where somebody
  could set it while a selector is quietly filling the field.
- `RawListing::$proseAbsent` (bool, default false), set by `ListingMapper` for the polling adapters
  and by `EmailAlertSource::cardListing()` for the email ones. On the LISTING rather than passed to
  `judge()`, for the reason `commuteMinutes` documents: `scout reclassify` re-judges from the v7
  snapshot, and a value passed alongside would be absent on every re-judge. The reflection-covered
  encoder picks the new field up automatically; a pre-v7 row reads `false`, which says nothing.
- `CriteriaEngine` appends a reason beside the existing unverifiable-ceiling one:
  `annonce sans texte — colocation/meublé non vérifiables`. Never a disqualifier and never a score
  component (hard rule 8) — it changes no verdict, it states what the verdict could not check.

**Stated cost, and it is the whole point of preferring this to a filter:** the colocations keep
arriving. Nothing available can stop them, and the two candidates that looked like they could —
detail hydration and a rent-per-room threshold — are refused and disproven respectively, above.

**Sabotage:** delete the caveat and a PAP push claims a clean bill of health it never had. The
counterweight matters as much: a source that maps a real description must NOT get the line, or it
becomes furniture on every push and stops being read.
