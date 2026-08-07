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

**The pure core is built. Nothing else is.**

`src/php/Core/` holds a PHP 8.5 implementation of the domain models and the tenure classifier —
the part that needs no mailbox, no reverse-engineered endpoint and no credential, and the part
everything else depends on. There is no adapter, no store, no notification channel and no CLI yet.

| Path | What it is |
|---|---|
| [`spec/PROJECT_BRIEF.md`](spec/PROJECT_BRIEF.md) | The full specification — the source of truth |
| [`docs/OPEN-QUESTIONS.md`](docs/OPEN-QUESTIONS.md) | **Start here.** Every filter enumerated, and the decisions still owed |
| `src/php/Core/` | The tenure classifier and the models it works on |
| `tests/fixtures/tenure/corpus.json` | 90 hand-labelled listing texts — the classifier's ground truth, and language-neutral so the phorj port reads the same file. **All 90 are synthetic**: `spec/PROJECT_BRIEF.md` §4 asks for *real* texts, and capturing those needs a source endpoint that does not exist yet. Every case declares its `provenance` and a test asserts the counts, so the gap is data rather than a promise |
| `prototype/` | A pre-existing single-file prototype, kept as reference. **Not** the shipping implementation — it has no tenure classifier at all |
| [`CLAUDE.md`](CLAUDE.md) | How code gets delivered here: rules, gates, the eligibility boundary |
| `.claude/` · `scripts/claude-bootstrap/` | Claude Code configuration ([details](scripts/claude-bootstrap/README.md)) |

## Getting started

```bash
composer install            # generates the PSR-4 autoloader. There are no runtime dependencies.
php tools/phpunit.phar      # the core suite
```

`tools/phpunit.phar` is gitignored; fetch it once with:

```bash
bash tools/fetch-phpunit.sh    # downloads and checks a pinned SHA-256; refuses to install on a mismatch
```

**Why a PHAR rather than a Composer dev dependency.** This project runs in a container whose egress
policy blocks GitHub dist downloads (`codeload.github.com` → 403), so Composer falls back to full
`git clone`s. Installing PHPUnit that way produced a **2.6 GB `vendor/`** for a test runner. With the
PHAR, `vendor/` is 56 KB of generated autoloader and the runner is one 6 MB file. The project has
**zero Composer dependencies** by design.

`fetch-phpunit.sh` pins the SHA-256 and refuses to install on a mismatch. It ALSO pins the signing
key and verifies the published PGP signature — **but only where a keyserver is reachable, which it is
not from this container**, so in practice the SHA-256 pin is what runs and the script says so on
stdout. Read its header before trusting it: the pin was established trust-on-first-use, making it a
continuity check rather than a root of trust.

Two checks that are not the test suite, and matter more than it does here:

```bash
bash tests/sabotage-check.sh       # breaks the classifier many ways; the suite must catch every one
bash tests/test-tenure-guard.sh    # the §1 tripwire still fires, and stays quiet on ordinary PHP
```

Every failure mode in the tenure module is *silent* — a classifier that over-rejects is
indistinguishable from a quiet rental market. A green suite proves the code passes the tests; only
the sabotage run proves the tests would notice if the code stopped working.

## Why this exists

Nothing on the market aggregates institutional LLI stock. In'li, CDC Habitat, Seqens, Immobilière 3F,
Vilogia, ICF and 1001 Vies each publish to their own site, several of them mixing social and
intermediate stock on the same page — and good LLI units are gone within hours. The private portals
(SeLoger, Leboncoin, Bien'ici, PAP, Logic-Immo) are well covered by their own alert emails, so this
tool ingests those rather than scraping them.

## Roadmap

Per `spec/PROJECT_BRIEF.md` §13. Each milestone ships working — no big-bang integration.

Built out of order on purpose: the classifier needs nothing from anyone, while every other milestone
is blocked on a mailbox, an endpoint capture or a phorj module that does not exist yet.

1. **Core skeleton** — models ✅, SQLite store, config loading, CLI, one notification channel. Proven
   end-to-end with a fake source.
2. **Tenure classifier + tests.** ✅ **Done in PHP**, against a 90-case synthetic corpus — spec §4's
   *real* listing texts are still outstanding and are blocked on capturing a payload. The phorj port
   waits on `Core.Imap`, an HTML parser and `sleep` (see `docs/PHORJ-REQUIREMENTS.md`). Before any
   real source; everything depends on it.
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
