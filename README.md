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

**It runs. Four institutional sources are live in production, and the first private portal is built.**

> That paragraph replaced one saying *"the pure core and the store are built, nothing else is —
> there is no adapter, no notification channel and no CLI yet"*, which had been false for weeks.
> `.env.example`'s own header carries the ruling: a stale *"this is only a sketch"* notice on a live
> thing is worse than no notice at all.

`scout run --watch` polls **In'li, CDC Habitat, Cityloger and Logirep** on the Q37 cadence, hydrates
detail pages behind a novelty gate, classifies every listing by tenure, scores it, and pushes what
matches. `doctor`, `dump`, `run --once/--seed/--watch`, `test-notify`, `digest` and `reclassify` all
work end to end. The store is at schema v8.

**SeLoger is built and proven offline** — the first Tier B portal, ingesting alert emails rather than
scraping (hard rule 4). It ships `enabled: false` because the IMAP credentials are the one thing
missing; `MAILBOX_DIR=tests/fixtures/seloger scout doctor --source=seloger` runs it against two real
frozen alerts.

**What is genuinely absent:** the transit/geo enrichment layer (`src/php/Enrich/`, no code at all),
AL'in (needs an authenticated capture), classifier tier 4 (needs the `plafonds` figures), and
`src/phorj/` (on indefinite hold).

| Path | What it is |
|---|---|
| [`spec/PROJECT_BRIEF.md`](spec/PROJECT_BRIEF.md) | The full specification — the source of truth |
| [`docs/OPEN-QUESTIONS.md`](docs/OPEN-QUESTIONS.md) | **Start here.** Every filter enumerated, and the decisions still owed |
| `src/php/Core/` | The tenure classifier, the models it works on, and the source-health verdict |
| `src/php/Store/` | The SQLite seen-set, price history and run log. Deleting the database re-notifies the entire market on the next run, and the price history cannot be reconstructed — a listing only ever shows its *current* rent |
| `tests/fixtures/tenure/corpus.json` | 123 hand-labelled listing texts — the classifier's ground truth, and language-neutral so the phorj port reads the same file. **115 synthetic + 8 captured**: `spec/PROJECT_BRIEF.md` §4 asks for *real* texts, and they arrived from 2026-08-20 with the live sources (CDC Habitat card text, Cityloger detail prose — including the corpus's first captured SOCIAL case — and Logirep, whose second capture is the site's own FILTER FACET STRIP, containing `PLAI` inside `Plain-pied`, `LLI` inside `Ce·lli·er` and `PLUS` inside `plusieurs`). Every case declares its `provenance` and a test asserts the counts, so the remaining gap is data rather than a promise |
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

**Redeploying after a code change — `src/` is baked into the image, `config/` is not.** The compose
file mounts `./config` read-only and `./state` read-write; everything else the watcher runs comes
from the image. So a `git pull` on the host changes the criteria and the source definitions
immediately, and changes **no code at all** until the image is rebuilt. Measured 2026-08-23: a
watcher was still running the previous day's build seventeen hours after its replacement was
committed and pushed, and the tree was clean and green throughout. Green, pushed and deployed are
three different things.

```bash
docker tag rent-watch:local rent-watch:pre-<what-you-are-leaving>   # rollback, one retag away
docker compose build                                                # BEFORE stopping: a failed
                                                                    #   build must not leave you down
sqlite3 state/rent-watch.sqlite3 ".backup /tmp/mig-rehearse.sqlite3"
php -r 'require "vendor/autoload.php"; $s=RentWatch\Store\Store::open("/tmp/mig-rehearse.sqlite3");
        echo $s->schemaVersion()," ",$s->journalMode(),PHP_EOL;'    # rehearse the migration
docker compose stop                                                 # graceful; finishes the pass
sqlite3 state/rent-watch.sqlite3 ".backup state/rent-watch.sqlite3.pre-<v>.$(date +%s).bak"
docker compose up -d
```

The rehearsal step is there because the seen-set is the one file this project documents as
unrecoverable, and a schema migration is the only routine operation that rewrites it. `.backup` is
safe against a running writer and checkpoints the WAL, so the copy is a real one; opening it with
the new code offline proves the migration chain runs against *this* data rather than against a test
fixture. Compare row counts on both sides — a migration that silently drops the price history looks
exactly like a successful one.

**A redeploy that adds a hydration gate reads as a collapse in matches, and that is the gate
working.** The same 478 listings went from `83 correspondance(s), 9 à vérifier` to
`29 correspondance(s), 63 à vérifier` across the 2026-08-23 upgrade, because a source-default match
is now withheld until the listing's own page has been read. The backlog drains at
`detail_budget_per_pass` per source per pass; matches climb back over the following passes. Nothing
already notified is re-notified — that is the seen-set's job and it survives the migration.

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

## Notifications — turning a channel on

Q9 rules every channel optional and `console` always available, so the stack starts and says what it
can and cannot reach rather than refusing to parse. A channel is turned on in **two** places and
neither alone is enough: it is listed under `notify.channels`, and its credentials are in `.env`. A
channel listed without its credentials is **disabled loudly** at startup (`⚠ canal ntfy désactivé :
NTFY_TOPIC is not set…`) — never silently, because hard rule 2 counts an alert computed and never
sent as worse than no alert at all.

> **⚠ `console` is not a channel, and neither is `email` over `SMTP_TRANSPORT=file`.** Both write
> to this machine and reach nobody, so **neither counts as a delivery**: a run whose only channels
> are those announces every match to the terminal and marks NOTHING notified, which means it
> re-announces the same listings on every pass and never writes the heartbeat marker. It is not
> refused — `run --once` at a terminal is exactly that shape — but it warns at startup, `doctor`
> says `AUCUN canal n'atteint de destinataire`, and **`scout test-notify` exits 1**.
>
> This matters because the SHIPPED `config/criteria.json` is `channels: ["console"]`. A deployment
> that never adds a real channel in `criteria.local.json` looks healthy and delivers nothing. Run
> `scout doctor` after any deploy: it now lists every channel, what it is, and whether it counts.
>
> Two review rounds were spent on this. Round 7 found `console` satisfying every "did it reach the
> user" gate; the fix filtered it out **by name**, and round 8 found `email` over a file transport
> walking through the same hole. It is a capability now — `Channel::reachesRecipient()` — asked
> once, on the interface.

Put the channel list in **`config/criteria.local.json`**, which is gitignored, rather than in the
committed `config/criteria.json`. Compose mounts `./config` into the container, so it reaches the
deployment; and the committed file stays free of anything that would make a fresh clone, a CI job or
the test suite try to push somewhere real.

```json
{
  "notify": { "channels": ["console", "ntfy", "email"] }
}
```

### ntfy — push to a phone, no account

Install the ntfy app, subscribe to a topic, and put the same topic in `.env`:

```bash
NTFY_TOPIC=rw-<something long and random>     # openssl rand -hex 16
NTFY_SERVER=https://ntfy.sh
```

**The topic IS the credential.** ntfy has no accounts: anyone who knows the string reads every
notification, so generate it rather than choosing it, and keep it out of anything committed.
`Redact` masks it in error text on the way to the store.

### email — the SMTP keys, and what they mean

`SMTP_TRANSPORT` chooses how mail leaves, and the interesting value is the one that sends nothing:

| value | behaviour |
|---|---|
| *(empty)* | `smtp` when `SMTP_HOST` is set, `sendmail` otherwise |
| `smtp` | speak SMTP directly, with the credentials below |
| `file` | write real `.eml` files to `MAIL_OUTBOX` and send NOTHING |

`file` is not a stub. The messages are complete and readable, which is how `scout test-notify`
proves the whole email path offline with no server and no credential — and it is the right setting
to leave configured while you have not got round to the rest.

**With Gmail** (any provider works; Gmail is the one with an extra step). Google refuses a plain
account password over SMTP, so it needs an *app password*, and an app password needs 2-step
verification switched on first:

1. **myaccount.google.com** → **Sécurité** → turn on **Validation en deux étapes**.
2. Same page, or `myaccount.google.com/apppasswords` → create one, name it `rent-watch`.
3. Google shows a **16-character password once**. Copy it before closing the box — it is not
   retrievable afterwards, only replaceable.

Then in `.env`:

```bash
SMTP_TRANSPORT=smtp
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_SECURITY=starttls
SMTP_USER=you@gmail.com
SMTP_PASSWORD=<the 16 characters, spaces removed>
SMTP_FROM=you@gmail.com
SMTP_TO=you@gmail.com
```

`SMTP_SECURITY=starttls` is not a preference: the transport **refuses to authenticate on a
connection the server did not upgrade**, and that refusal is proven by a sabotage case against a
scripted loopback server and its wire transcript. Sending yourself mail from your own address is
normal and Gmail delivers it.

Then verify. **The exit code is now the guarantee**, and this section said the opposite for as
long as it existed — *"verify the delivery, not the exit code"* was written when a console print
satisfied `test-notify`, so the exit code meant nothing. It means something now: 0 only when a
channel that can actually reach you accepted the message.

```bash
scout test-notify              # 0 = a real channel accepted it. 1 = nothing that counts did.
scout doctor                   # lists every channel, what it is, and whether it counts
ls -t var/outbox | head -1     # `file` transport: the message that was written and NOT sent
```

> **`bin/scout` loads `.env` itself** (2026-08-22), so the host and the container read the same
> file the same way. Do **not** use `set -a; . ./.env; set +a`, which this file recommended for one
> afternoon: that is the SHELL, and it executes the file rather than parsing it. A Gmail app
> password pasted with the spaces Google displays it with — `abcd efgh ijkl mnop` — is not an
> assignment to bash but a one-command environment prefix, so the variable is never exported *and*
> bash runs the rest as a command. Observed: the CLI reported `SMTP_PASSWORD is empty` while the
> file plainly held one, and four characters of a live credential were printed to the terminal by
> `command not found`. A value containing backticks would have been executed.
>
> The parser takes values literally — no expansion, no substitution — and **the real environment
> still wins**, so `RENT_WATCH_DB=/tmp/throwaway bin/scout run` works as before. A line that is not
> `KEY=VALUE` is a startup refusal naming the line NUMBER and never the line, because this file
> holds credentials.

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
2. **Tenure classifier + tests.** ✅ **Done in PHP**, against a 122-case corpus (115 synthetic, 7 captured) — spec §4's
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
scout digest [--dry-run]      # emit the pending "à vérifier" rollup, on demand
scout reclassify [--dry-run]  # re-judge stored UNKNOWN verdicts against today's classifier
scout replay <source>         # alias of `dump` — see the amendment below
```

`scout replay` is an ALIAS OF `dump`, and takes a SOURCE NAME — not a fixture path. This line said
`<fixture>` for as long as the verb existed, and `scout replay tests/fixtures/inli/search.html`
answers *"source inconnue"* and exits 2. A three-way disagreement, since the verb was also absent
from `scout help` entirely: the spec asked for one thing, the code did another, and the tool's own
help denied the verb existed. Documented as it behaves rather than quietly dropped — for a
`type: fixture` source `dump` IS a replay against a saved payload, which is most of what the spec
asked for. Building the fixture-path form is recorded as outstanding in
`spec/PROJECT_BRIEF.md`.

`scout digest` reads the STORE, not the last pass, and that is the difference that makes it worth
having: the pipeline already re-offers an undelivered digest entry next run, but only while the ad
is still published, so an entry whose listing is delisted in between is lost with nothing saying so.

`scout reclassify` re-runs the classifier AND the criteria engine over stored `UNKNOWN` verdicts,
using the schema-v7 snapshot of the listing the verdict was formed from. A row stored before v7 has
no snapshot and is **skipped, not judged on whatever text remains** — re-judging on less evidence
than the original saw is how a social listing becomes a match. `--since` is deliberately refused;
see `docs/plans/finish-everything.plan.md` for why.

A listing whose dedup CLUSTER holds an excluded tenure is skipped for the same reason and counted
out loud. The pipeline judges a cluster on its most restrictive member but stores each member's own
tenure and own snapshot, so a vetoed survivor looks merely undetermined — and re-judging it on its
own snapshot alone is exactly the `⊂` this command forbids.

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

### Adding an EMAIL source

Same idea, different pre-checks — and an HTTP status tells you nothing here, because the route is a
mailbox rather than a page. Subscribe a dedicated mailbox to the portal's own alert, save one
message, and capture it:

```bash
php tools/scrub-eml.php captured.eml tests/fixtures/<portal>/alert.eml you@example.com
MAILBOX_DIR=tests/fixtures/<portal> php bin/scout doctor --source=<portal>   # force-runs a disabled source
```

`tools/scrub-eml.php` removes the subscriber's identity — the address, the bounce and reply tokens,
the ESP feedback ids, the one-click unsubscribe token, every `qs=` tracking value — and **refuses to
write** while any of them survives. It deliberately keeps the message *ugly*: the MIME preamble, the
awkward boundary, the folded headers and the RFC 2047 subject split mid-word are the ground truth,
and three of them were live parser defects until a real alert exposed them.

Then check four things, in this order, before writing a field map:

1. **Does the alert carry a real listing URL?** SeLoger's does not — every link is a tracking
   redirect with an opaque per-recipient token, and stripping the query collapses every listing in
   the message onto one identity. That answer decides the whole block (`id_from: content` vs the
   default `link`).
2. **Does the `text/plain` part carry undecoded HTML entities?** If so the parser must decode them,
   or `logement conventionn&eacute;` folds to `logement conventionn` and the label is destroyed.
3. **Does each card end in a consistent call to action?** That string is `card_separator`. Without
   one, every link becomes a listing carrying the first rent in the whole message.
4. **Do not follow the tracking redirect to recover a URL.** It is a request per listing on a
   subscriber-bound token, and it registers a click nobody made. Carry the link unresolved.

Worked example with all four answered: `docs/SOURCES.md` § *B1 SeLoger*.

## Legal posture

- Email-alert (IMAP) ingestion is the **primary** path for private portals — within ToS, no bot to
  detect, faster than polling, and far steadier than a search page — though NOT "immune to markup
  churn", which this file claimed until 2026-08-25. An email template is markup too, and a real
  SeLoger alert broke the parser four ways the first time it was fed one. The honest claim: email
  templates change far less often than search pages, and the zero-cards guard makes a break loud
  instead of silent.
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

- The committed `config/criteria.json` carries the eight Île-de-France prefixes (75, 77, 78, 91, 92,
  93, 94, 95), `min_rooms: 3`, `min_surface_m2: 50`, `max_rent_cc: 1200`, and an EMPTY `communes`
  list — region mode, ruled 2026-08-22, where the prefixes are the whole location filter. Those
  numbers moved twice on that day; if this list and the file ever disagree, the file is right and
  this bullet is stale. **No name, no employer, no income
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
