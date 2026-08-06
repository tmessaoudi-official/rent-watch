# rent-watch

A self-hosted watcher for **rental listings in Île-de-France**. It polls institutional landlords,
ingests private-portal alert emails over IMAP, classifies every listing by French housing **tenure
type**, filters and scores it against personal criteria, and pushes a notification within minutes of
publication.

Single language, single user, single machine. CLI plus push notifications — **no web UI**, by design.

> **The one non-negotiable rule:** `logement social` (PLAI, PLUS) is never surfaced as a match. The
> target is **LLI** — *logement locatif intermédiaire*. This is an eligibility fact, not a filter
> preference, and it is enforced by a fail-closed classifier with its own test suite. See
> [`CLAUDE.md`](CLAUDE.md) §1.

## Status

**Specification and prototype only.** There is no implementation yet.

| Path | What it is |
|---|---|
| [`spec/PROJECT_BRIEF.md`](spec/PROJECT_BRIEF.md) | The full specification — the source of truth |
| [`docs/OPEN-QUESTIONS.md`](docs/OPEN-QUESTIONS.md) | **Start here.** Every filter enumerated, and 14 decisions still owed |
| `prototype/` | A pre-existing single-file prototype, kept as reference. **Not** the shipping implementation — it has no tenure classifier at all |
| [`CLAUDE.md`](CLAUDE.md) | How code gets delivered here: rules, gates, the eligibility boundary |
| `.claude/` · `scripts/claude-bootstrap/` | Claude Code configuration ([details](scripts/claude-bootstrap/README.md)) |

Nothing under `src/`, `config/` or `tests/` exists yet, and several open questions change the
architecture rather than a constant — so milestone 1 waits on the blocking ones in
`docs/OPEN-QUESTIONS.md`.

## Why this exists

Nothing on the market aggregates institutional LLI stock. In'li, CDC Habitat, Seqens, Immobilière 3F,
Vilogia, ICF and 1001 Vies each publish to their own site, several of them mixing social and
intermediate stock on the same page — and good LLI units are gone within hours. The private portals
(SeLoger, Leboncoin, Bien'ici, PAP, Logic-Immo) are well covered by their own alert emails, so this
tool ingests those rather than scraping them.

## Roadmap

Per `spec/PROJECT_BRIEF.md` §13. Each milestone ships working — no big-bang integration.

1. **Core skeleton** — models, SQLite store, config loading, CLI, one notification channel. Proven
   end-to-end with a fake source.
2. **Tenure classifier + tests.** Before any real source; everything depends on it.
3. **In'li adapter** — highest-value single source, and pure LLI, so it exercises the happy path.
4. **Health monitoring + `scout doctor`.** Before adding breadth, or breakage goes unnoticed.
5. **CDC Habitat** — first mixed-tenure source; validates the classifier against reality.
6. **Email-alert adapter + one private portal.**
7. **Remaining institutional sources.**
8. **Cross-portal dedup, transit enrichment, scoring refinement.**

## Planned CLI

```
scout doctor                  # health-check every source: status, timing, item counts
scout dump <source>           # raw payload of the first item — for building field maps
scout run --once [-v]         # single pass
scout run --watch             # loop with jitter
scout test-notify             # verify the notification channel
scout replay <fixture>        # re-run parsing against a saved fixture
```

`scout dump` is what makes onboarding a new source take five minutes instead of an hour, so it lands in
milestone 1.

## Adding a source

Adding a source is **config-only** in the common case — a block in `config/sources.yaml`, no code. The
[`/add-source`](.claude/skills/add-source/SKILL.md) skill walks the whole workflow: live-endpoint
discovery, field-map building with `scout dump`, fixture capture, tenure labelling, and the health
baseline.

Two rules that matter more than the mechanics:

- **Never write an endpoint from memory.** Verify it against the live site. Unverified URLs read
  `REMPLACER` and their source stays `enabled: false`.
- **`mixed_tenure: true` is the default.** Only a provably pure source (In'li) may be `false`. Getting
  it wrong disables the fail-closed rule for that source.

## Legal posture

- Email-alert (IMAP) ingestion is the **primary** path for private portals — within ToS, no bot to
  detect, faster than polling, immune to markup churn.
- Direct scraping of those portals is opt-in, disabled by default, `legal_risk: true`, and refuses to
  run without an explicit flag.
- No CAPTCHA solving, no proxy rotation, no fingerprint spoofing. `robots.txt` respected, honest
  User-Agent, low request rates.
- No auto-application to landlords.

## Secrets

IMAP credentials, the notification token, the IDFM/PRIM API key and the RFR income figure live in
`.env`, which is gitignored. `.env.example` is the committed template. Never commit personal financial
data; never log credentials; scrub any fixture captured from a live payload before committing it.

⚠️ **This repo is currently public.** `config/criteria.yaml` will carry target communes, budget and
household composition — personal, though not credentials. See `docs/OPEN-QUESTIONS.md` Q11; making it
private before that file lands is recommended.
