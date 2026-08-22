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

**The pure core and the store are built. Nothing else is.**

`src/php/Core/` holds a PHP 8.5 implementation of the domain models and the tenure classifier —
the part that needs no mailbox, no reverse-engineered endpoint and no credential, and the part
everything else depends on. `src/php/Store/` holds the SQLite seen-set, price history and run log,
which is what makes "notify once" and "tell me when a rent drops" possible at all. There is no
adapter, no notification channel and no CLI yet.

| Path | What it is |
|---|---|
| [`spec/PROJECT_BRIEF.md`](spec/PROJECT_BRIEF.md) | The full specification — the source of truth |
| [`docs/OPEN-QUESTIONS.md`](docs/OPEN-QUESTIONS.md) | **Start here.** Every filter enumerated, and the decisions still owed |
| `src/php/Core/` | The tenure classifier, the models it works on, and the source-health verdict |
| `src/php/Store/` | The SQLite seen-set, price history and run log. Deleting the database re-notifies the entire market on the next run, and the price history cannot be reconstructed — a listing only ever shows its *current* rent |
| `tests/fixtures/tenure/corpus.json` | 120 hand-labelled listing texts — the classifier's ground truth, and language-neutral so the phorj port reads the same file. **114 synthetic + 6 captured**: `spec/PROJECT_BRIEF.md` §4 asks for *real* texts, and they arrived from 2026-08-20 with the live sources (CDC Habitat card text, Cityloger detail prose — including the corpus's first captured SOCIAL case — and Logirep, whose second capture is the site's own FILTER FACET STRIP, containing `PLAI` inside `Plain-pied`, `LLI` inside `Ce·lli·er` and `PLUS` inside `plusieurs`). Every case declares its `provenance` and a test asserts the counts, so the remaining gap is data rather than a promise |
| `prototype/` | A pre-existing single-file prototype, kept as reference. **Not** the shipping implementation — it has no tenure classifier at all |
| [`CLAUDE.md`](CLAUDE.md) | How code gets delivered here: rules, gates, the eligibility boundary |
| `.claude/` | Claude Code configuration ([details](CLAUDE.md#claude-config-in-this-repo)) |

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
bash tests/sabotage-check.sh       # breaks the classifier and the store many ways; the suite must catch every one
bash tests/test-tenure-guard.sh    # the §1 tripwire still fires, and stays quiet on ordinary PHP
```

Every failure mode in the tenure module is *silent* — a classifier that over-rejects is
indistinguishable from a quiet rental market. The store is the same shape: a seen-set that stops
persisting, a price history that stops recording, a run log that reports a dead source as calm. A
green suite proves the code passes the tests; only the sabotage run proves the tests would notice if
the code stopped working. It has earned that twice over — a three-lens review panel found 25 defects
in the store's first cut, and five of its guarantees were shown untested by running this very script
against them.

## Deploying it

Ruled by Q8: **Docker on a VPS, `state/` on a mounted volume, `scout run --watch` owning its own
schedule.** Not cron — the process keeps its jitter and a continuous run log. Not GitHub Actions,
explicitly: no persistent disk means no seen-set, which means re-notifying the entire market on
every run.

```bash
cp .env.example .env && $EDITOR .env      # a push channel — or nothing ever reaches you
docker compose run --rm scout doctor      # sources reachable? journal WAL? channels usable?
docker compose run --rm scout run --once --seed
docker compose up -d
```

**The seed step is not optional.** An empty seen-set makes `run` refuse (Q36), because an empty
seen-set is exactly what a forgotten volume mount looks like and the alternative is notifying the
entire back catalogue at once. With `restart: unless-stopped` that refusal becomes a restart loop —
visible, since Q27 records it to `state/last-refusal.txt` and reports it on the next successful
start, but still a loop. Seed first and it starts clean.

**File ownership is the one thing that bites on a first deploy.** `state/` is bind-mounted from the
host, so it belongs to whoever created it, while the container runs as its own uid. Compose defaults
to `1000:1000` — the ordinary first user on a Debian/Ubuntu VPS. If yours differs:

```bash
RW_UID=$(id -u) RW_GID=$(id -g) docker compose up -d
```

Get it wrong and the refusal now says so by name (`base de données inutilisable (…) : le volume
est-il monté et accessible en écriture…`) instead of dying with a stack trace, which is what it did
before this was measured against a real container.

**What is in the image, and what is not.** No test suite, no `tools/phpunit.phar`, no `.env` — an
image layer is a distributable artifact and a baked-in credential survives any later layer that
deletes it. The demo fixture *is* shipped, and it lets a fresh VPS prove the whole
pipeline before a single landlord is polled — but it is `enabled: false` as of 2026-08-22, so it
takes an explicit `scout doctor --source=fixture_demo` to run. It shipped ENABLED for two weeks,
from before any real endpoint existed, and a real pass therefore counted ten flats that do not
exist in its totals, its `doctor` table and its heartbeat.

**There is deliberately no Docker `HEALTHCHECK`.** Q27's heartbeat is the liveness mechanism and it
reports through a channel you actually read; a container healthcheck polling the marker file would
fight the 24-hour interval and report to a dashboard nobody is watching.

**Back up `state/rent-watch.sqlite3`.** Deleting it re-notifies everything, and the price history
cannot be reconstructed — a listing only ever shows its *current* rent.

## Why this exists

Nothing on the market aggregates institutional LLI stock, and most of these landlords do not even
aggregate their own. In'li, CDC Habitat and Cityloger (the Immobilière 3F group's lettings platform)
publish real feeds, two of the three mixing social and intermediate stock on the same page — and good
LLI units are gone within hours. Seqens, 3F's corporate site, ICF Novedis, 1001 Vies and Batigère
publish **no availability at all** on their own domains: they route applicants to AL'in or to the SNE.
`docs/SOURCES.md` carries the measurement behind each row. The private portals
(SeLoger, Leboncoin, Bien'ici, PAP, Logic-Immo) are well covered by their own alert emails, so this
tool ingests those rather than scraping them.

## Roadmap

Per `spec/PROJECT_BRIEF.md` §13. Each milestone ships working — no big-bang integration.

Built out of order on purpose: the classifier needs nothing from anyone, while every other milestone
is blocked on a mailbox, an endpoint capture or a phorj module that does not exist yet.

1. **Core skeleton** — models ✅, SQLite store ✅ (seen-set, price history, run log, source health),
   config loading, CLI, one notification channel. Proven end-to-end with a fake source.
2. **Tenure classifier + tests.** ✅ **Done in PHP**, against a 120-case corpus (114 synthetic, 6 captured) — spec §4's
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
scout run --watch [-v]        # loop: every 15 min ± 5 of jitter, paced per host (Q37)
scout test-notify             # verify the notification channel
scout replay <fixture>        # re-run parsing against a saved fixture
```

`scout dump` is what makes onboarding a new source take five minutes instead of an hour, so it lands in
milestone 1.

Two obligations on `doctor`, both of which are silent when forgotten: it must pass the current time
to `Store::health()` — without a clock the `STALE` verdict cannot fire at all — and it must print
`Store::journalMode()`, because WAL can be refused on a network mount and a store in rollback-journal
mode makes two processes contend instead of share.

## Adding a source

Adding a source is **config-only** in the common case — a block in `config/sources.json`, no code. The
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

⚠️ **This repo is currently public**, and `config/criteria.json` is now committed. Making the repo
private is still recommended and is the developer's action; the mitigation that makes it survivable
either way ships regardless (Q11, ruled 2026-08-07):

- The committed `config/criteria.json` carries the 78/95 prefixes, `min_rooms: 3`,
  `min_surface_m2: 75`, `max_rent_cc: 1800`, and an EMPTY `communes` list — region mode, ruled
  2026-08-22, where the prefixes are the whole location filter. **No name, no employer, no income
  figure, no address**, which is the part of this that matters.

  Two corrections to what this bullet used to claim, both dated 2026-08-22. It said *"only values
  that were already public in `prototype/sources.yaml`"*, and that stopped being true the moment
  `min_rooms` went from the prototype's `4` to a measured `3` — one committed value is now the
  developer's own decision rather than an inherited default. It is a preference about flat size, not
  personal data, so the Q11 mitigation still holds; but "already public" was load-bearing wording
  and is no longer accurate, and a privacy claim that quietly drifts is worse than a narrower one
  stated plainly. The ten communes are also no longer a filter — they survive as `commune_rank`
  (which orders results) and as a note in the file recording what to restore.
- **`config/criteria.local.json` is gitignored** and overrides the committed file field by field, so
  real budget and real preferences never have to enter git. That claim was written in four documents
  before anything enforced it; `/config/*.local.json` is now in `.gitignore`, and
  `git check-ignore -v config/criteria.local.json` is how to confirm it rather than believe it.
