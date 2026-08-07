---
name: add-source
description: >
  Use when onboarding a new landlord or portal into config/sources.json. Walks live-endpoint
  discovery, field-map building, fixture capture, tenure labelling and the health baseline so that
  adding a source stays config-only.
user-invocable: true
disallowed-tools: AskUserQuestion
---

<!-- ═══════════════════════════════════════════════════════════════════════════════════
  rent-watch-native skill (2026-08-06) — not ported from the bundle. It exists because
  `spec/PROJECT_BRIEF.md` §6 makes "adding a source is config-only" an architectural
  requirement, and §14 makes "verify every endpoint live" a hard rule. Both are easy to
  violate one convenient shortcut at a time, so the workflow is written down.

  Questions here follow `.claude/skills/ask-human/SKILL.md`: plain text, numbered options,
  recommended first. `AskUserQuestion` is forbidden project-wide.
═══════════════════════════════════════════════════════════════════════════════════ -->

## --help

> If ARGUMENTS contains `--help`: output the text below verbatim, then STOP.
>
> ```
> /add-source <name> — Onboard a new listing source into config/sources.json, config-only.
>
> Flags:
>   --url <url>     Search page URL to start discovery from
>   --family <f>    institutional | private   (default: institutional)
>   --email-alert   Use the IMAP alert-ingestion path instead of HTTP polling
> ```

---

# /add-source — onboard a listing source

Adding a source must be **config-only** in the common case. A bespoke adapter under
`src/php/Adapters/sites/` is the fallback, not the default path. If you find yourself writing PHP here,
stop and say why config was not enough — a contract every source bypasses is not a contract.

---

## Step 0 — Which path?

| Family | Path | Why |
|---|---|---|
| **Institutional** (In'li, CDC Habitat, Seqens, 3F, Vilogia, ICF, 1001 Vies, AL'in…) | HTTP JSON endpoint → `type: json` | These sites have real XHR search endpoints and no serious anti-bot. This is where the project earns its keep — nothing on the market aggregates them. |
| **Private portal** (SeLoger, Leboncoin, Bien'ici, PAP, Logic-Immo) | `type: email_alert` | **Primary path, not a workaround.** Within ToS, defeats DataDome entirely because there is no bot, *faster* than polling (alerts fire on publication), and immune to markup churn. |

Direct HTTP scraping of a private portal is opt-in only: `legal_risk: true`, disabled by default, and it
must **refuse to run** without an explicit flag. No CAPTCHA solving, proxy rotation, fingerprint
spoofing, or a dishonestly-impersonating User-Agent — ever (`CLAUDE.md` hard rule 5).

**Out of scope entirely:** `demande-logement-social.gouv.fr`, and Bienvéo unless its intermediate-stock
filter can be applied reliably at the source. Both are social-housing channels and violate §1.

---

## Step 1 — Find the real endpoint. Never write it from memory.

Hard rule 1. Placeholder URLs must read `REMPLACER` so they cannot be mistaken for verified ones, and a
source stays `enabled: false` until its URL has been confirmed against the live site.

Ask the developer to do the DevTools capture — they have a browser, you do not:

> Open the site's search page, set the filters you actually want (78/95, T4+, the communes), then
> DevTools → Network → Fetch/XHR → re-run the search. Copy the request as cURL and paste it here.

From the cURL, extract: method, URL, query params or JSON body, and the **minimum** headers that make it
work. Drop cookies and tracking headers; keep `Referer` and `Content-Type` if required. If the endpoint
needs an authenticated session (AL'in typically does), say so explicitly rather than half-building it.

## Step 2 — Write a minimal block, then iterate with `scout dump`

Start with `enabled: false`, an `items_path`, and no `map:`. Run `scout dump <name>` to print one raw
item, then fill `map:` from the real payload shape. `scout dump` is what makes this take five minutes
instead of an hour — if it does not exist yet, building it comes first.

Config is **JSON**, not YAML — ruled 2026-08-07 (Q22): this container has no `ext-yaml` and cannot
install a parser. `sources.json` is an **object keyed by source name**, so a duplicate name is
impossible to write rather than merely discouraged. JSON has no comments, so any key beginning with `_`
is ignored by the loader (`_comment` by convention); **every other unknown key is a hard error**, which
is what keeps that convention from doubling as a typo swallower.

```json
{
  "sources": {
    "<name>": {
      "_comment": "why this endpoint, and anything the next reader would ask",
      "enabled": false,
      "family": "institutional",
      "type": "json",
      "default_tenure": null,
      "mixed_tenure": true,
      "url": "REMPLACER",
      "method": "GET",
      "headers": { "Referer": "https://…/" },
      "items_path": "results.items",
      "map": {
        "ref": "id",
        "title": ["title", "name"],
        "url": "url",
        "commune": ["city", "address.city"],
        "cp": ["zipCode", "address.postalCode"],
        "rent": ["rent.total", "price"],
        "charges_included": true,
        "surface": "surface",
        "rooms": ["rooms", "nbRooms"],
        "floor": "floor",
        "elevator": "hasElevator",
        "description": "description",
        "tenure_field": "financement"
      }
    }
  }
}
```

- `default_tenure` — hint only, the classifier still runs. See Step 4.
- `mixed_tenure` — the fail-closed switch. **Required**, never defaulted in the file. See Step 4.
- `charges_included` — `true` or `false`. Sources are inconsistent; normalise, never assume.
- `tenure_field` — the highest-value mapping. Look hard for it.
- `ref` — must be STABLE across runs. See Step 5.

A path may be a list — the first non-empty one wins.

## Step 3 — Capture a fixture. No network in CI.

```bash
scout dump <name> --raw > tests/fixtures/<name>/search.json
```

**Scrub anything personal from the payload before committing it** — agent names, phone numbers, internal
IDs. Then write a parser test that asserts field-by-field against the fixture. The test must fail if the
field map drifts. Verify every `map:` path actually exists in the fixture: a mapping no fixture exercises
fails silently at runtime instead of loudly in a test.

## Step 4 — Label the tenure. This is the step people skip.

`default_tenure` is a **hint of last resort** (signal priority 5). The classifier always runs. What
matters here is telling the classifier where to look and whether the source mixes stock:

- Find the structured tenure field (`financement`, `typeProduit`, `categorie`) and map it to
  `tenure_field`. Signal priority 1 — highest confidence, worth real effort to find.
- **`mixed_tenure: true` is the default and the fail-closed direction.** Set it for any source that
  publishes social **and** intermediate stock on the same pages: CDC Habitat, Vilogia, Immobilière 3F,
  Seqens, 1001 Vies, ICF. On these, confidence `< 0.6` means `UNKNOWN` → *"à vérifier"* digest, never a
  match.
- `mixed_tenure: false` is **only** for a source that is provably pure one tenure (In'li = pure LLI).
  Getting this wrong disables the fail-closed rule for that source entirely, which is how a PLAI listing
  reaches a notification. When unsure, leave it `true`.

Then add at least **two** hand-labelled entries from this source to the classifier corpus: one clear
case and one that was hard to judge. Label them by reading the listing text, not by trusting the
source's own field.

## Step 5 — Stability and health baseline

- **Check the `ref` is stable.** Re-run `scout dump <name>` twice and compare the `ref` of the same
  listing. A ref that changes between runs re-notifies forever; so does a fallback hash over a title
  the site A/B-tests.
- A new source starts with **no baseline**, so breakage detection is blind for its first runs — say so
  rather than implying it is covered. Run it a few times, confirm the item count is stable, then:

```bash
scout doctor            # status, timing, item count per source
```

Only flip `enabled: true` once `doctor` is green, the parser test passes offline, and the URL was
verified live.

## Step 6 — Report

State plainly: endpoint verified live (yes/no, and how), fields mapped, fields unavailable at this
source, whether rent is charges comprises, the `mixed_tenure` verdict **and why**, fixture path, item
count observed, and whether the `ref` was confirmed stable across two runs.

If the source turned out to be technically or legally impractical, say so and propose the email-alert
route instead of quietly building something fragile (`spec/PROJECT_BRIEF.md` §14).

$ARGUMENTS
