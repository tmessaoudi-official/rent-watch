<?php

declare(strict_types=1);

namespace Scout\Rent\Core;

use Scout\Core\Text;

/**
 * WHO is advertising this flat, and what tenure posture that implies.
 *
 * Tenure is a property of the LISTING, never of the source — `Tenure`'s own docblock says so, and
 * this class does not weaken it. What it fixes is narrower and was measured on 2026-09-01 over the
 * live store: **23 SeLoger rows whose advertiser is an institutional landlord were judged `LIBRE`
 * at the source default of 50bp, and 21 were pushed as a MATCH.** The same flat on that landlord's
 * own site is judged under `mixed_tenure: true` and goes to the *à vérifier* digest.
 *
 * **The verdict depended on the ROUTE, not on the flat.** That is a §1 breach whichever way it is
 * read: CDC Habitat publishes social and intermediate stock on the same pages, so a CDC card
 * stating no tenure is exactly the fail-closed case, and arriving through SeLoger it inherited
 * `mixed_tenure: false` + `default_tenure: LIBRE` and was notified.
 *
 * `CLAUDE.md` records a residual on SeLoger, and its stated grounds are these: the two
 * commission-allocated schemes are not advertised on commercial portals, while the third
 * occasionally is. That reasoning holds for a card from an ANONYMOUS advertiser. It does not
 * survive a card whose advertiser names itself a bailleur in the subject line — and In'li, which
 * every document here called pure LLI, was itself found on the detail page to carry stock under the
 * third scheme.
 *
 * ## What this class is NOT
 *
 * It is **not a classifier signal**, and that separation is deliberate. {@see TenureClassifier}
 * reasons about *tenure* — labels, procedural tells, ceilings — and the one adjacent decision in
 * its history went the other way for exactly this reason: a bare `sans commission` was REMOVED from
 * tier 3 because it is a fee disclaimer *"which bailleurs sociaux advertise too"*. Names are not
 * tenure. So a recognised advertiser does not emit a signal, does not add confidence and cannot
 * out-rank a label: it substitutes the SOURCE PROFILE for the one that landlord's own listings are
 * judged under, and every tier then runs unchanged. Tiers 1–3 still win outright.
 *
 * ## Why an explicit registry and not a derivation from `sources.json`
 *
 * Deriving the table from the configured institutional sources was the first design, and the 23
 * rows refute it three separate ways:
 *
 * - **RIVP has no source block at all** — measured out of scope (`docs/SOURCES.md` A14) — so a
 *   derived table leaves an RIVP-advertised card at the LIBRE default, which is the hole being
 *   closed.
 * - ***Immobilière 3F*** advertises through a block named `cityloger`. No rule folds one to the
 *   other.
 * - ***IN'LI*** does not fold to the key `inli` by any normalisation this project has.
 *
 * The real concern behind "no second list" is DRIFT — the `Criteria::communeLabels` scar, where a
 * vocabulary built from one source went empty and a whole extraction silently stopped. That is
 * answered by **pinning**, not by derivation: `LandlordRegistryTest` asserts every institutional
 * block in `config/rent/sources.json` appears here, so a new landlord source cannot be added
 * without this table being updated. A derived map would have covered only what happens to be
 * enabled, which is worse and looks better.
 *
 * ## Matching is on the FOLDED NAME, and only against a name the caller extracted structurally
 *
 * This class never scans a body. It is handed an advertiser that a per-source `advertiser_pattern`
 * pulled out of a field the portal lays out — SeLoger's subject line, `<ADVERTISER> vous adresse
 * ses dernières exclusivités`, ~201 messages of it. A vocabulary scan over prose is refused
 * outright: `au plus près`, `Ce·lli·er`, `En savoir plus`, `Plain-pied` and `?c=plai_plus` are five
 * paid-for instances of an identifier matched in text that merely contains it, and the audit that
 * found THIS defect committed a sixth — the alias `i3f` matching inside a base64url JWT signature.
 *
 * Hence {@see NAMES} holds whole folded names, matched by CONTAINMENT of the registry entry inside
 * the extracted advertiser (so `IN'LI PARIS EST` matches `in'li`) but never the reverse, and every
 * entry is long enough that containment cannot fire on an acronym buried in machine text. The
 * shortest is 4 characters and it is checked by test.
 */
final class LandlordRegistry
{
    /**
     * Folded advertiser name → the source-block key whose posture it inherits, or `null` for a
     * landlord this project has no source for.
     *
     * The VALUE is a key rather than a posture on purpose: a landlord that also has a source block
     * must be judged identically whichever route its listing arrives by, and that is only
     * structurally true if both read the same line of `config/rent/sources.json`. Writing the
     * posture here as well would be two declarations of one fact, and they would drift the first
     * time a source's `mixed_tenure` was revised.
     *
     * @var array<string, string|null>
     */
    private const array NAMES = [
        "in'li" => 'inli',
        'inli' => 'inli',
        'cdc habitat' => 'cdc_habitat',
        'cdc-habitat' => 'cdc_habitat',
        'cityloger' => 'cityloger',
        'immobiliere 3f' => 'cityloger',
        'logirep' => 'logirep',
        'polylogis' => 'logirep',
        // Landlords with NO source block. Every one of these is an institutional bailleur this
        // project measured and did not build (`docs/SOURCES.md`), and every one can still reach the
        // tool through a private portal — which is the whole point of this class.
        'rivp' => null,
        'paris habitat' => null,
        'elogie-siemp' => null,
        'seqens' => null,
        'batigere' => null,
        'vilogia' => null,
        'erilia' => null,
        'toit et joie' => null,
        'antin residences' => null,
        '1001 vies habitat' => null,
        'icf habitat' => null,
        'novedis' => null,
    ];

    /**
     * The posture a recognised landlord with NO source block is judged under.
     *
     * FAIL-CLOSED, and it is the only defensible default: these are landlords nobody has measured
     * the stock of. RIVP is predominantly social; guessing `LIBRE` for an unmeasured bailleur is
     * the §1-dangerous direction, and guessing `LLI` would be worse still. `mixedTenure: true` with
     * no default hint means an explicit label decides, and absent one the listing digests — which
     * is what the *à vérifier* bin exists for.
     */
    public static function unknownLandlordProfile(string $name): SourceProfile
    {
        return new SourceProfile(
            name: $name,
            family: 'institutional',
            defaultTenure: null,
            mixedTenure: true,
        );
    }

    /**
     * The profile this listing should actually be judged under.
     *
     * Returns `$source` unchanged in every case but one, which keeps the blast radius honest: no
     * advertiser extracted, an advertiser nobody recognises, or an advertiser that IS this source
     * (a landlord's own site naming itself) all leave the caller exactly where it was.
     *
     * @param callable(string): ?SourceProfile $profileFor resolves a `sources.json` key to its
     *                                                     profile; injected so this class stays
     *                                                     pure and testable without the loader
     */
    public static function effectiveProfile(
        SourceProfile $source,
        ?string $advertiser,
        callable $profileFor,
    ): SourceProfile {
        $key = self::match($advertiser);

        if ($key === false) {
            return $source;
        }

        if ($key === null) {
            return self::unknownLandlordProfile((string) $advertiser);
        }

        // The landlord's own source, naming itself. Substituting here would be a no-op at best and
        // at worst would replace a profile the caller deliberately built (a force-run, a fixture)
        // with one read from config.
        if ($key === $source->name) {
            return $source;
        }

        return self::stricterOf($source, $profileFor($key) ?? self::unknownLandlordProfile((string) $advertiser));
    }

    /**
     * The MORE RESTRICTIVE of two profiles, field by field — so a substitution can only ever tighten.
     *
     * **This is what makes `profileFor` safe to inject at all**, and it was demanded by
     * `TenureClassifierTest::testExcludedSetIsNotReachableThroughAnyConstructorArgument`, which
     * fired the moment a second constructor argument appeared. `CLAUDE.md` §1 calls a key that can
     * re-admit the excluded set a P0 "even if nothing currently sets it" — and without this method
     * a resolver returning `mixedTenure: false, defaultTenure: LIBRE` would turn a fail-closed
     * source into a matching one. No configured block does that today; the guarantee must not
     * depend on that staying true.
     *
     * `mixedTenure` merges by OR because `true` is the strict value — it is what arms the
     * fail-closed rule, and `SourceProfile` already defaults it to `true` for the same reason.
     *
     * `defaultTenure` merges on the ordering **excluded > unknown > eligible**, which is the
     * §1 ordering rather than the obvious one: a default that REJECTS is safer than one that
     * digests, which is safer than one that matches. Between two eligible hints (a portal's `LIBRE`
     * and In'li's `LLI`) neither is stricter, so the landlord's wins on accuracy — it is the party
     * that actually knows.
     */
    private static function stricterOf(SourceProfile $source, SourceProfile $landlord): SourceProfile
    {
        $rank = static fn (?Tenure $t): int => match (true) {
            $t === null => 1,
            $t->isExcluded() => 2,
            default => 0,
        };

        $default = $rank($landlord->defaultTenure) >= $rank($source->defaultTenure)
            ? $landlord->defaultTenure
            : $source->defaultTenure;

        return new SourceProfile(
            // The LANDLORD's name, because every reason line and every stored signal that quotes a
            // profile name should say who the verdict was actually formed about.
            name: $landlord->name,
            // `institutional` wins for the same reason `mixedTenure` does: it is the family whose
            // stock can be excluded, and `Dedup` reads family only for the twin/duplicate split,
            // which runs on the raw listing rather than on this substituted profile.
            family: $source->family === 'institutional' || $landlord->family === 'institutional'
                ? 'institutional'
                : 'private',
            defaultTenure: $default,
            mixedTenure: $source->mixedTenure || $landlord->mixedTenure,
        );
    }

    /**
     * Which registry entry does this advertiser name?
     *
     * Returns the source key, `null` for a recognised landlord with no source block, and `false`
     * for "not a landlord we know" — three outcomes, because the middle one is a real answer and
     * collapsing it into the last would silently drop every bailleur this project never built a
     * source for.
     */
    public static function match(?string $advertiser): string|false|null
    {
        if ($advertiser === null) {
            return false;
        }

        $folded = Text::fold($advertiser);

        if ($folded === '') {
            return false;
        }

        $best = false;
        $bestLength = 0;

        foreach (self::NAMES as $name => $key) {
            // CONTAINMENT of the registry entry inside the advertiser, never the reverse. SeLoger
            // writes `IN'LI PARIS EST` and `CDC HABITAT ILE DE FRANCE`, so an equality test would
            // recognise neither; the reverse containment would let a one-word advertiser match a
            // long entry and is the over-match this class exists to avoid.
            if (!str_contains($folded, $name)) {
                continue;
            }

            // LONGEST WINS. `cdc habitat` and a hypothetical `cdc` would both fire on the same
            // string, and first-match-wins would make the answer depend on array order — the same
            // shape as the rent reader that returned a 100 € reduction because it stopped at the
            // first figure.
            if (strlen($name) > $bestLength) {
                $best = $key;
                $bestLength = strlen($name);
            }
        }

        return $best;
    }

    /** Every folded name this registry knows — read by the pinning test, never by the classifier. */
    public static function names(): array
    {
        return array_keys(self::NAMES);
    }

    /** Every source key this registry maps onto — read by the pinning test. */
    public static function sourceKeys(): array
    {
        return array_values(array_unique(array_filter(self::NAMES, static fn ($k) => $k !== null)));
    }
}
