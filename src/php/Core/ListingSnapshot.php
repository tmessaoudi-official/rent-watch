<?php

declare(strict_types=1);

namespace RentWatch\Core;

/**
 * The evidence a verdict was formed from, frozen so it can be re-judged later. Schema v7.
 *
 * **Why this exists at all.** `scout reclassify` re-runs an improved classifier over listings whose
 * verdict was `UNKNOWN`, because the seen-set guarantees a listing is new exactly once — so a
 * listing digested under an old classifier is a PERMANENT silent miss unless something re-examines
 * it. But `listings` stores the verdict and not the text that produced it, and re-running the
 * classifier on less evidence than the original saw is not a smaller improvement, it is a §1
 * BREACH: a card whose field says `PLS` while its title says *logement intermédiaire* classifies
 * `UNKNOWN` today by conflict, and re-run on the title alone it becomes a MATCH. The feature meant
 * to reduce misses would introduce the one outcome §1 forbids.
 *
 * Hence the invariant this class serves: **reclassify runs on evidence ⊇ original, never ⊂.**
 *
 * **What is frozen is the `RawListing` the classifier consumed** — after mapping and after any
 * detail merge — and never the pre-map payload. Re-reading a raw payload would make a stored
 * verdict's evidence depend on `ListingMapper` code that has since changed, which is the same class
 * of drift the `map_fingerprint` column exists to catch one layer down.
 *
 * **Pre-v7 rows are not backfilled.** There is no honest value to write, and an invented snapshot
 * would be indistinguishable from a real one — exactly the distinction reclassify has to make in
 * order to skip a row rather than degrade it. Same precedent as `tenure` and `group_key`.
 */
final class ListingSnapshot
{
    /**
     * Freeze the listing exactly as the classifier saw it.
     *
     * Every constructor parameter of {@see RawListing} is written, and a reflection test asserts
     * that — a field added to the model and forgotten here is dropped from every snapshot written
     * afterwards, silently, because decode would still succeed and the gap would read as "the
     * source did not say".
     */
    public static function encode(RawListing $listing): string
    {
        return json_encode([
            'sourceName' => $listing->sourceName,
            'externalId' => $listing->externalId,
            'title' => $listing->title,
            'description' => $listing->description,
            'fields' => (object) $listing->fields,
            'url' => $listing->url,
            'commune' => $listing->commune,
            'postcode' => $listing->postcode,
            'rentCc' => $listing->rentCc,
            'rentHc' => $listing->rentHc,
            'charges' => $listing->charges,
            'surfaceM2' => $listing->surfaceM2,
            'rooms' => $listing->rooms,
            'bedrooms' => $listing->bedrooms,
            'floor' => $listing->floor,
            'hasElevator' => $listing->hasElevator,
            'detailRead' => $listing->detailRead,
            'commuteMinutes' => $listing->commuteMinutes,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Thaw a snapshot. Loud on anything it cannot read.
     *
     * Hard rule 3 in its natural habitat: degrading a corrupt snapshot to a bare listing would
     * classify as `UNKNOWN` and read as an honest doubt rather than as a row nobody can judge.
     * The caller's job is to SKIP an unreadable row and count it out loud, which it can only do if
     * this throws.
     *
     * @throws \JsonException           the payload is not JSON
     * @throws \InvalidArgumentException the payload is JSON but is not a snapshot
     */
    public static function decode(string $json): RawListing
    {
        /** @var mixed $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new \InvalidArgumentException('listing snapshot is not an object');
        }

        // Identity is the one part with no defensible default. Everything else may legitimately be
        // absent — these two absent means the row is corrupt, not sparse.
        foreach (['sourceName', 'externalId'] as $required) {
            if (!isset($data[$required]) || !is_string($data[$required])) {
                throw new \InvalidArgumentException('listing snapshot is missing ' . $required);
            }
        }

        return new RawListing(
            sourceName: $data['sourceName'],
            externalId: $data['externalId'],
            title: self::str($data['title'] ?? ''),
            description: self::str($data['description'] ?? ''),
            fields: self::fields($data['fields'] ?? []),
            url: self::nullableStr($data['url'] ?? null),
            commune: self::nullableStr($data['commune'] ?? null),
            postcode: self::nullableStr($data['postcode'] ?? null),
            // Each cast is guarded on `!== null` rather than applied to the value, because `(int) null`
            // is 0 and that is the exact hard-rule-9 confusion this whole class is defending against.
            rentCc: self::nullableInt($data['rentCc'] ?? null),
            rentHc: self::nullableInt($data['rentHc'] ?? null),
            charges: self::nullableInt($data['charges'] ?? null),
            // JSON has ONE number type, so a surface of 88.0 comes back as an int and would reach an
            // `?float` parameter as one. Cast explicitly rather than relying on PHP's coercion,
            // which strict_types refuses anyway.
            surfaceM2: self::nullableFloat($data['surfaceM2'] ?? null),
            rooms: self::nullableInt($data['rooms'] ?? null),
            bedrooms: self::nullableInt($data['bedrooms'] ?? null),
            floor: self::nullableInt($data['floor'] ?? null),
            hasElevator: self::nullableBool($data['hasElevator'] ?? null),
            detailRead: (bool) ($data['detailRead'] ?? false),
            // A snapshot written BEFORE this field existed is not corrupt — it is simply older, so
            // it decodes to null (unknown) rather than being refused. Same treatment as detailRead,
            // and the reason is the same: an absent key is absence of evidence, never evidence of
            // a long commute (hard rule 9).
            commuteMinutes: isset($data['commuteMinutes']) ? (int) $data['commuteMinutes'] : null,
        );
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function nullableStr(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
            ? (int) $value
            : null;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
            ? (float) $value
            : null;
    }

    /**
     * `false` and `null` are DIFFERENT FACTS here (hard rule 9) — "there is no lift" versus "the
     * listing did not mention a lift" — and only the explicit `false` may drive the high-floor
     * penalty. So anything that is not literally a boolean decodes as `null`, never as `false`.
     */
    private static function nullableBool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /**
     * The structured fields, PRESERVED rather than filtered.
     *
     * **This method used to keep a key only `if (is_scalar($item))`, and that one condition made
     * the class's whole invariant false.** `encode()` writes `fields` verbatim, so an array-, `null`-
     * or object-valued field was written and then silently dropped on the way back — and those are
     * exactly the values {@see TenureClassifier} raises its tier-1 DOUBT on, the doubt being the
     * only thing withholding a match. A review panel proved it end to end on 2026-08-24: a listing
     * with `fields: ['gamme' => ['PLAI','PLUS']]` and an intermediate-sounding title classified
     * `UNKNOWN`/`DIGEST`, and after one snapshot round trip `scout reclassify` promoted it to
     * `LLI`/`MATCH` and pushed it. That is the literal scenario `listings.evidence_json` exists to
     * prevent, and the loss was irreversible — reclassify writes the decoded listing back.
     *
     * So: **every key survives, and every value survives as faithfully as JSON allows.** A scalar
     * becomes its string form (which is what an adapter would have produced); `null` stays `null`;
     * an array stays an array. All three then reach the classifier — and for a non-scalar it doubts,
     * which digests.
     *
     * **`null` is the exception, and this used to claim otherwise.** The classifier's unreadable
     * doubt excludes `null` deliberately (`$value !== null` in `structuredFieldSignals()`), so a
     * `null`-valued field contributes its NAME and nothing else. Preserving it is still right —
     * `numeroUnique` and `demandeLogementSocial` are literal `PROCEDURAL` entries, so the key alone
     * is the strongest social discriminator the domain offers — but the reason is the key, not a
     * doubt that never fires.
     *
     * The field NAME is evidence in its own right, which is the second reason nothing may be
     * dropped: `numeroUnique` and `demandeLogementSocial` are literal `PROCEDURAL` entries, so a
     * key whose value is `null` still carries the strongest social discriminator the domain offers.
     *
     * A `fields` that is not an array at all is CORRUPTION, not sparseness, and throws — the caller
     * must be able to skip the row rather than judge it on a silently emptied map.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException
     */
    private static function fields(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('listing snapshot has a non-object `fields`');
        }

        $fields = [];
        foreach ($value as $key => $item) {
            $fields[(string) $key] = is_scalar($item) ? (string) $item : $item;
        }

        return $fields;
    }
}
