# Milestone 1 — end-to-end pipeline Plan

Milestone 1 is the first run that goes all the way through: one source fetched, classified, filtered,
stored, and pushed. It is built bottom-up, because the layers that carry state are the ones whose
failures are silent, and they are cheapest to get right before anything depends on them.

## Status

| Piece | State |
|---|---|
| `Store` — seen-set, price history, run log, health derivation | **done**, `src/php/Store/Store.php` |
| `SourceHealth` / `SourceStatus` | **done**, `src/php/Core/` |
| Config loading (`config/criteria.yaml`, `config/sources.yaml`) | **blocked** — see the open decision below |
| `Source` adapter contract + first adapter | not started |
| `criteria` (score + hard disqualifiers) | not started |
| Notification formatter + one channel | not started |
| `scout` CLI (`doctor`, `dump <source>`, `run --once`) | not started |

## Open decision — the config file format

`spec/PROJECT_BRIEF.md` §9 and `CLAUDE.md`'s architecture table both specify `config/criteria.yaml`
and `config/sources.yaml`. **This container has no `ext-yaml`** and Composer cannot install anything
(the egress policy returns 403 on `codeload.github.com`, see `CLAUDE.md` § Gotchas), so a `.yaml`
file has nothing to parse it. The decision is recorded in `docs/OPEN-QUESTIONS.md`; it is a product
decision because it changes a file the developer edits by hand.

The reason it cannot be resolved by writing a small YAML parser without asking: `sources.yaml`
carries `mixed_tenure`, the flag that arms the §1 fail-closed rule. A subset-parser that
mis-reads one boolean disarms the one non-negotiable guarantee in the project, silently.

## Decisions Log

- [2026-08-07 14:20] AGREED: the store takes ISO-8601 timestamps as arguments rather than reading the
  clock. Health is a function of run history over time, and a store that calls `now()` internally can
  only be tested by waiting.
- [2026-08-07 14:20] AGREED: `rent_cc` is updated with `COALESCE(:rent, rent_cc)` — a source that
  stops publishing the rent does not erase the last known figure. Overwriting with null would make a
  later republication at a lower price read as a first sighting, silently swallowing the drop.
- [2026-08-07 14:20] AGREED: a FAILED run terminates the consecutive-empty streak rather than
  extending it. "The source did not answer" and "the source answered nothing" are different
  diagnoses, and the failure has its own louder rule.
- [2026-08-07 14:20] AGREED: the empty-run baseline is measured over the window ending BEFORE the
  streak began. Including the streak dilutes the very mean that is supposed to prove it abnormal.
- [2026-08-07 14:20] AGREED: `NEVER_RUN` is a distinct status and alerts. A source configured months
  ago that has never once fired is a configuration bug, invisible precisely because it never fails.
- [2026-08-07 14:20] AGREED: in the fallback dedup key, the URL host is folded and the path is not.
  RFC 3986 makes the path case-sensitive, and over-merging hides a flat completely while
  under-merging only costs a duplicate notification — so the tie breaks toward under-merging.
- [2026-08-07 14:20] AGREED: tracking parameters are NOT stripped from the fallback dedup URL. No
  source has been observed emitting one, and a guessed strip-list is a rule invented against a
  failure nobody has seen. Revisit when a real capture shows one.
- [2026-08-07 14:20] AGREED: timestamps are parsed strictly and refused when unreadable.
  `new DateTimeImmutable('')` silently means "now"; since the timestamp orders the price history and
  defines the health window, a reinterpreted one corrupts both with no visible symptom.
- [2026-08-07 14:20] AGREED: `tests/sabotage-check.sh` is extended beyond the classifier to the
  store. Same argument, same failure shape — none of the store's failure modes raise anything.
