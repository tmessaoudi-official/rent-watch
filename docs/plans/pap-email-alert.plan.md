# PAP email-alert source Plan

> **Source #8, live 2026-08-26.** De Particulier à Particulier — the first DIRECT-FROM-OWNER portal
> in the tree, so its inventory does not overlap the agency portals that supply every other private
> source. `scout doctor --source=pap` returns **2 annonces, `ok`, 483 ms**.
>
> Prove a change offline with
> `MAILBOX_DIR=tests/fixtures/pap scout doctor --source=pap`.

## The shape

The simplest alert in the tree: a real `text/plain` part, **one listing per message** — so no
`card_separator` at all — and a real ad id in the URL (`/annonces/-r458301723`), so identity is the
link and none of SeLoger's content-addressing is needed. Chosen before the source was first enabled,
deliberately: nothing migrates a stored row from one key scheme to another, so switching later
re-notifies the whole backlog.

## What it cost — two defects a naive config walks straight into

Both were **measured on the real capture**, not predicted, by running the real extractors against it
before any config was written.

### 1. The alert quotes the subscriber's own search criteria ABOVE the listing

```
Une annonce correspond à votre recherche … jusqu'à 1.200 EUR à partir de 45 m².

Location appartement 3 pièces
Milly-la-Forêt (91490)
50 m²
800 EUR / mois
```

Every generic reader in `EmailAlertSource` is a first-match-wins `preg_match`. Measured:

| | extracted | actual | |
|---|---|---|---|
| surface | **45.0** | 50 m² | ❌ the search FLOOR |
| rooms | 3 | 3 | ✅ **coincidence** — the criteria line also says 3 |
| rent | 800 | 800 EUR/mois | ✅ the periodic-figure rule earns its keep |
| commune | **null** | Milly-la-Forêt | ❌ unranked, so the vocabulary scan is blind |

45 is below `min_surface_m2`, so **the first PAP alert ever sent would have been rejected for being
too small — silently**, with nothing anywhere reading as a fault. That is Bien'ici's defect a second
time, **down to the same number 45**, because the same saved-search criteria are used on every
portal.

The fix is four **POSITIONAL** anchors keyed on the `(NNNNN)` postcode line — the one structural
landmark the template guarantees — and explicitly *not* on vocabulary. *A title is a position, never
a vocabulary* is a lesson already paid for on SeLoger; this is its numeric twin.

### 2. `link_host` must carry the PATH

The message has **two** links and both are on `www.pap.fr`: the annonce, and the unsubscribe page at
`/utilisateur/alertes` — whose wording matches **none** of the noise words `looksLikeAListing()`
rejects (`unsubscribe`, `desinscription`, `preferences`…). A non-segmented source builds one listing
**per accepted link**, each given the whole message as its body, so a host-only value yielded a
phantom second listing carrying the real flat's rent, commune, surface and rooms under its own
identity — notified as a separate flat, and **never delisted**, because an unsubscribe page never
goes away. `leboncoin` already sets `link_host: "leboncoin.fr/vi/"` for exactly this reason.

## Two code changes, and one of them was a dead feature

- **`surface_pattern` and `rooms_pattern`** are new per-source params, joining the compile-checked
  list beside `title_pattern` / `commune_pattern` / `residence_pattern`. **A configured pattern that
  MISSES yields `null`, never the generic scan** — the `cardTitle()` rule, for the same reason:
  falling back would restore the defect *and* give it an alibi, since the row then reads as a small
  flat rather than as a broken extraction. A source configuring neither is bit-for-bit unchanged.
- **`title_pattern` was INERT on every non-segmented source.** `listingsIn()` hardcoded
  `$message->subject()`; only the segmented path ever consulted `cardTitle()`. A configured pattern
  doing nothing — and on this source it makes `exclude_title_patterns` structurally unreachable,
  which is the In'li and SeLoger lesson a third time. Sources configuring no pattern keep subject
  semantics exactly, so `seloger`, `bienici` and `leboncoin` are untouched.

**No fixture reaches the miss branch**, because with the anchors in place every frozen card
extracts. `EmailAlertSegmentationTest` enters it on purpose, with a counterweight proving an
unconfigured source still uses the generic scan. That is the dead-safety-code trap the SeLoger title
walked into on 2026-08-26, avoided by having been walked into once already.

## n=1 lasted three hours

The anchors were measured on ONE message and the n=1 risk was stated — this repo has twice paid for
generalising from a single capture. A **second alert arrived the same afternoon** (Meulan-en-Yvelines,
78250) and is frozen beside the first. It confirms every anchor on a different commune and adds the
case the first lacked: a rent written `1.150 EUR / mois`, where the dot is a **thousands separator**
and *"the rightmost separator is the decimal point"* would read it as 1 €.

## Stated costs

- **The rent ceiling is not checkable for this source.** The payload mentions charges **nowhere** —
  zero occurrences of `charges`, `CC` or `HC` — so the Logirep and leboncoin precedent applies:
  `charges_included: false`, the figure lands in `rentHc`, `max_rent_cc` never fires on it, and the
  score line says so.
- **§1 residual, same as every `mixed_tenure: false` portal.** A card stating no tenure takes the
  `LIBRE` default and matches. What still holds and is asserted: the tier-2 label rules never consult
  the flag, so an explicit `PLS`/`conventionné` injected into a real card REJECTS. PAP is
  particulier-to-particulier, so social stock on it is implausible rather than merely unobserved —
  which is a stronger argument than SeLoger has.
- **The tracking link is never followed at ingest**, per the standing rule. The unresolved URL goes
  in the notification, where a human clicks it.

## Decisions Log

- [2026-08-26 13:30] AGREED (developer, asked as the second of the ask-list items): onboard PAP properly — fix the measured surface defect first with a failing test, capture the alert as a scrubbed fixture, then enable.
- [2026-08-26 13:40] AGREED: identity is the LINK, not content-addressing. PAP publishes a real ad id, so the content key's two stated costs do not apply. Chosen BEFORE the first enabled pass, because nothing migrates a stored row between key schemes.
- [2026-08-26 13:45] AGREED: `surface_pattern` and `rooms_pattern` are new per-source params rather than a change to the generic readers. Making the generic reader examine every match and pick by plausibility was REFUSED: 45 and 50 are both plausible surfaces, so no band separates them, and "prefer the later one" is arbitrary and would silently change every other source.
- [2026-08-26 13:45] AGREED: a configured numeric pattern that MISSES yields `null`, never the generic scan — the `cardTitle()` rule. The counterweight (an unconfigured source still uses the scan) is asserted, because without it the guarantee is satisfied by removing the feature.
- [2026-08-26 13:50] FIXED, found by the failing test rather than by review: `title_pattern` was inert on every non-segmented email source. Pre-existing, and it would have made `exclude_title_patterns` unreachable on PAP exactly as it was on SeLoger for a month.
- [2026-08-26 14:05] NOTED: the n=1 risk lasted about three hours. The second alert is frozen as `002` and pins the dot-as-thousands rent case. Append a third; never renumber.
- [2026-08-26 14:10] NOTED: `communeIn()` still FALLS BACK to the vocabulary scan when a configured `commune_pattern` misses, unlike the title and the two new numeric readers. Pre-existing and deliberately left alone — changing it touches `seloger`, `bienici` and `leboncoin` at once, and the compile-check is the guard the loader documents for it. Worth revisiting as its own task.
