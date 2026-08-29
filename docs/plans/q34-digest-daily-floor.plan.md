# Q34 — the daily digest floor Plan

Q34 is `PARTIALLY BUILT`. Both emission paths exist and are tested: the automatic one at the end of
any pass that produced new digest entries, and `scout digest [--dry-run]` on demand, reading the
STORE rather than the pass. **What does not exist is the daily floor.** `digest_hour` is parsed into
`NotifyPolicy`, printed by `doctor`, and read by nothing else — there is no scheduler and no
comparison against a clock, so on a day with nothing new no rollup is emitted.

This plan closes that half only. It does not touch the two built paths.

## Decisions Log

- [2026-08-26 00:13] AGREED: the next two work items are Q34's daily floor and the plafonds tier-4
  half. VPS deployment and a Flutter client are both DROPPED from this round — the Flutter question
  was answered separately (a phone cannot be scheduled to poll every 15 minutes; the ntfy app
  already is the notification client).
- [2026-08-26 00:13] AGREED: on a day when the digest backlog is EMPTY the floor emits **nothing**.
  Rationale, and it is a correction to Q34's own stated rationale: the ruling justifies the floor as
  *"silence should be distinguishable from a stopped process"*, but `Core/Heartbeat` was built AFTER
  that sentence (2026-08-22) and already sends a daily liveness beat regardless of whether anything
  matched. An always-emit floor would therefore be a SECOND scheduled daily push carrying no
  information the beat did not already carry — and the fastest way to train the user to swipe the
  digest channel away, which is the failure `NotifyPolicy`'s own docblock exists to prevent.
  **To reverse:** emit unconditionally at the due check instead of returning early on an empty
  backlog.
- [2026-08-26 00:13] AGREED: certification for this item runs autonomously at `advisor()`-only.
  No reviewer panel this round. Executable evidence does the refuting between gates — failing test
  first, confirmed red for the stated reason, plus a sabotage case proving the suite would notice
  the guarantee breaking.

## Formal Plan

*(written at Phase 4)*

- [2026-08-26 00:20] AGREED, then CORRECTED at 01:05 — and the correction is the more useful record.
  The local hour is resolved from an EXPLICIT `\DateTimeZone` rather than from PHP's default zone.
  **The first justification written for that was wrong.** It read: `TZ=Europe/Paris` is set in the
  container and `date_default_timezone_get()` still answers `UTC`, so a floor computed from the
  default would fire at 10:00 Paris in summer. The measurement is real — `php -r` in that container
  does report UTC — and the conclusion does not follow, because `bin/scout:44` already calls
  `date_default_timezone_set()` from `TZ` before `Scout` is constructed. A true number attached to
  an invented cause, produced by measuring the runtime and never reading the entrypoint: this
  repo's own named failure, committed while building the feature. `compose.yaml`'s TZ comment was
  accused of the same error and is likewise CORRECT; it was not edited.
  **What the explicit zone actually buys, both measured:** an unusable `TZ` becomes a loud startup
  refusal, where `date_default_timezone_set('Europe/Pariss')` returns `false`, emits a Notice and
  leaves UTC standing — a compose typo moving the floor two hours all summer with only a log line;
  and the scheduling of §1's landing zone stops depending on process-wide mutable state.
- [2026-08-26 00:20] AGREED: off-hour emissions are a DESIGNED consequence, not a defect to be filed
  later. A cold start with no marker, and a failed send retried on the next pass, both emit whenever
  the pending bin is non-empty and no marker exists yet. That is the documented
  "one beat too many, never one suppressed" bias inherited from `Core/Heartbeat`.

## Formal Plan

### Files

1. **`src/php/Core/DigestSchedule.php`** (new) — pure policy, mirrors `Core/Heartbeat`.
   - `__construct(int $hour)`, refusing outside 0–23.
   - `static fromEnvZone(?string $tz): \DateTimeZone` — explicit resolution, loud refusal on an
     unusable value, `Europe/Paris` when absent (matching `.env.example`'s documented default).
   - `isDue(?string $lastEmittedIso, string $nowIso, \DateTimeZone $zone): bool` — due iff the marker
     is null / unreadable / in the future, or older than the most recent local `$hour:00` instant at
     or before `$nowIso`.
2. **`src/php/Cli/Scout.php`** — extract the drain from `digest()` into one shared private method
   taking `(Store, Notifier, string $nowIso)` and returning a result; it must NEVER throw, because
   the floor runs inside the loop's `finally` and a throw there would count the pass failed.
   `digest()` keeps `--dry-run`, its exit codes and its printed lines. The floor prints its own,
   returns void, and REUSES the loop's notifier (a second one re-prints `disabledReport()` every
   pass). Wire the due-check at the two call sites the heartbeat already occupies in `watch()`.
   Correct the `doctor` line that currently says `AUCUN planificateur ne le lit`, and print the
   RESOLVED local time there, per Q34's ruling, via the same resolution function.
3. **`compose.yaml`** — correct the TZ comment; keep the var (it is still right for the container's
   own clock and for anything non-PHP), but say what it does NOT do.
4. **Tests** — `tests/php/Core/DigestScheduleTest.php` (new) and additions to the Scout suite.
5. **`tests/sabotage-check.sh`** — new cases (below).
6. Docs: `CLAUDE.md`, `docs/OPEN-QUESTIONS.md` Q34 (the gap note becomes a built note), `README.md`.

### The tests that carry the guarantees

- **TZ-DISCRIMINATING, or it asserts nothing**: marker 07:00 Paris, now 08:30 Paris (= 06:30 UTC).
  Due in Paris, NOT due in UTC. A test whose two zones agree would pass against the bug.
- **The marker's one job**: seed `DIGEST_BATCH + 1` entries, one due window, assert exactly ONE
  emission and a remainder still pending. Without the marker the floor drains every pass.
- **The in-loop send path must actually execute.** Under a fixed clock the startup send writes the
  marker and every later `isDue()` is false — the documented trap that left `beat()`'s in-loop call
  site unexecuted by any test, where a `TypeError` in the closure's `use` list would have killed the
  watcher a day into an unattended run. The way in is the same one: make `state/digest.txt` a
  DIRECTORY, so the `@file_put_contents` write silently fails and `is_file()` reports no marker —
  every check is then due, and with a backlog over `DIGEST_BATCH` the startup drain sends batch 1
  and the in-loop check sends batch 2.
- **The existing `scout digest` tests must pass UNCHANGED.** If the extraction needs one edited, the
  extraction changed behaviour.
- **DST** (P2): one test pinning the due-instant for an hour inside the Paris spring-forward gap.

### Sabotage cases

- Marker write deleted → the one-batch-per-window test goes red.
- In-loop floor call deleted → the directory-marker test goes red.
- Zone resolution replaced by the PHP default → the TZ-discriminating test goes red.
- Empty-backlog early return removed → an empty-day emission test goes red.

### Rollback

Each item is its own commit; `git revert` is sufficient. Nothing migrates and nothing is written to
the store that was not written before — `markNotified` is the pre-existing call.

## Deployed 2026-08-26 05:44 UTC

One rebuild served both this and tier 4. Verified the way `CLAUDE.md`'s top gotcha prescribes —
a green tree says nothing about what the watcher is running:

```
built image: sha256:9cfe6d96ddb3208117c92fa9efd468b507506221f9de3f445ff502529c4e26ff
container:   sha256:9cfe6d96ddb3208117c92fa9efd468b507506221f9de3f445ff502529c4e26ff
```

The deployed banner reads *"plancher quotidien du récapitulatif « à vérifier » à 8h Europe/Paris
(Q34) — silencieux si rien n'est en attente"*, which also confirms the zone resolved to Paris rather
than to UTC in the real container.

**`state/digest.txt` does not exist after the deploy, and that is CORRECT.** The startup check ran,
found nothing pending, and returned without writing a marker — the ruled empty-day behaviour, and
the reason the window stays open. Verified against the database rather than inferred: 12 rows carry
`outcome = DIGEST` and all 12 already have `notified_at`, so the undelivered count is 0.

**The cheap production check for the first real firing** is `ls state/digest.txt` after 08:00 Paris.
Its absence before then is not a fault.

> **Checked 2026-08-29, three mornings later: still no marker, and still correct.** The undelivered
> backlog is 0 (`SELECT COUNT(*) FROM listings WHERE outcome='DIGEST' AND notified_at IS NULL`), and
> the watcher log shows why — the EVENT-driven path drains each digest row the pass it appears in
> (`[DIGEST] À vérifier : 1 annonce(s)` at 17:32 UTC that day), so 08:00 has never found anything
> pending. The floor has therefore not yet been observed firing in production; the evidence that it
> would is `DigestScheduleTest` plus the ledger, and the observable stays the marker. A floor that
> never needs to fire is the designed outcome, not a defect to chase.
