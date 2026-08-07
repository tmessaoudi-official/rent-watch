<?php

declare(strict_types=1);

namespace RentWatch\Core;

/**
 * THE classifier. `spec/PROJECT_BRIEF.md` §4, and the module that carries `CLAUDE.md` §1.
 *
 * Three ideas, in order of how much trouble they save:
 *
 * 1. **The ladder.** Five signal tiers. The highest tier that fires decides the tenure; lower tiers
 *    may only move the confidence. That is `CLAUDE.md`'s "a lower-priority signal must never
 *    override a higher one", implemented rather than restated.
 *
 * 2. **The collocation guard, and its third answer.** `PLUS` is a financing scheme and `plus` is one
 *    of the commonest words in French. Word boundaries alone are not enough (`plus de 3 chambres`),
 *    and uppercase alone is not enough (portal titles are SHOUTED). An uppercase acronym in a
 *    financing collocation is read three ways, per occurrence:
 *      - a financing label ENDS the phrase (punctuation, end of text, another acronym) → the label;
 *      - a known French comparative follows (`PLUS GRAND`) → the adverb, no signal;
 *      - any other word follows (`LOGEMENT PLUS MODERNE`) → **indécidable**, and it says so.
 *    The third answer exists because the alternative was a denylist of French adjectives, which
 *    cannot be completed: every adjective missing from it turned an emphatic title into a silent
 *    REJECT, and nothing arriving looks exactly like a quiet market.
 *
 * 3. **The conflict rule, and doubts.** The ladder on its own is not fail-closed. A structured field
 *    saying `LLI` and a body text saying `PLAI` leaves the ladder at LLI/0.97, sailing past the 0.6
 *    floor into a notification. So an eligible verdict contradicted by any excluded-tenure signal
 *    collapses to UNKNOWN. It does not assert the listing is social — it withholds. The reverse is
 *    deliberately NOT symmetric: an excluded verdict contradicted by an eligible signal stays
 *    excluded. Softening an exclusion is the one direction that costs a wasted application.
 *
 *    An *indécidable* marker from (2) is a **doubt**, not a tenure claim, so it is held apart from
 *    the ladder entirely: it withholds an otherwise-eligible verdict, and it never competes with,
 *    masks or is masked by a real label. It used to be resolved positionally against them, which
 *    made the answer depend on sentence order.
 */
final readonly class TenureClassifier
{
    /** The fail-closed floor: `CLAUDE.md` §1's 0.6, in whole points. */
    public const int FLOOR_BP = 60;

    /**
     * Base confidence per tier.
     *
     * Tier 5 sits BELOW {@see FLOOR_BP} on purpose. A mixed source emitting a listing that carries
     * zero tenure evidence therefore lands in the digest, never in a notification — `CLAUDE.md`'s "an
     * absent signal must lower confidence, never silently inherit `default_tenure` at full
     * confidence", made structural instead of aspirational.
     */
    private const array TIER_BASE = [1 => 97, 2 => 90, 3 => 80, 4 => 70, 5 => 50];

    private const int CORROBORATION_BP = 3;
    private const int CONTRADICTION_BP = 15;
    private const int CEILING_BP = 99;
    private const int BASEMENT_BP = 10;

    /** Structured fields worth treating as tier 1. Compared as {@see Text::fieldKey()} output. */
    private const array TENURE_FIELDS = [
        'financement', 'typefinancement', 'financementlogement',
        'typeproduit', 'produit',
        'categorie', 'category', 'categorielogement',
        'typelogement', 'dispositif', 'regime', 'conventionnement', 'agrement',
    ];

    /**
     * Tier 2 — explicit labels, matched whole-token on folded text.
     *
     * Multi-word entries are more specific than their parts, and the resolver prefers the longest
     * match at the earliest position, so `conventionne anah` beats a bare `conventionne` and
     * `logement intermediaire` beats a bare `lli` that appears later in the same sentence.
     */
    private const array LABELS = [
        'logement locatif intermediaire' => Tenure::LLI,
        'logement intermediaire' => Tenure::LLI,
        'loyer intermediaire' => Tenure::LLI,
        'lli' => Tenure::LLI,
        // `loyer libre` is the tenure term. `logement libre` is NOT — it is the standard French
        // VACANCY phrase (`libre au 1er août`, `libre de suite`), and it was in this table until
        // 2026-08-06, when a review showed it firing tier 2 at 90 on a move-in date. Worse, the
        // spurious LIBRE satisfied the conventionné exception below, so a listing whose own title
        // said `conventionné` reached MATCH. Do not add vacancy vocabulary here.
        'loyer libre' => Tenure::LIBRE,
        'pret locatif intermediaire' => Tenure::LLI,
        'conventionne anah' => Tenure::ANAH,
        'conventionne anru' => Tenure::ANRU,
        'pret locatif a usage social' => Tenure::PLUS,
        "pret locatif aide d'integration" => Tenure::PLAI,
        'pret locatif social' => Tenure::PLS,
        'logement locatif social' => Tenure::SOCIAL,
        'logement social' => Tenure::SOCIAL,
        'habitation a loyer modere' => Tenure::SOCIAL,
        'hlm' => Tenure::SOCIAL,
        'plai' => Tenure::PLAI,
        'anru' => Tenure::ANRU,
        'anah' => Tenure::ANAH,
        'conventionne' => Tenure::CONVENTIONNE,
    ];

    /**
     * Tier 2, but only under the collocation guard. See idea 2 in the class docblock.
     *
     * `PLAI` is NOT here: no French word is spelled `plai`, so word boundaries alone are enough
     * (they still matter — as a substring it hits *plaine*, *plaisant*, *plaisir*).
     */
    private const array AMBIGUOUS_LABELS = [
        'PLUS' => Tenure::PLUS,
        'PLS' => Tenure::PLS,
    ];

    /** Words that make an adjacent uppercase acronym a financing label rather than a coincidence. */
    private const string COLLOCATION = 'logements?|financements?|categories?|types?|typologie|produits?|agrement|dispositif|programme|regime|conventionnement|financement';

    /** An acronym next to another acronym is a financing enumeration: `PLUS / PLAI`, `PLS ou PLUS`. */
    private const string ACRONYMS = 'PLAI|PLUS|PLS|LLI';

    /**
     * If the acronym is followed by one of these, it was the French adverb after all.
     *
     * `PLUS DE 70 M2` in a shouted title is the case that makes this necessary: it satisfies
     * uppercase, and a title like `LOGEMENT PLUS GRAND` would satisfy collocation too.
     */
    private const string COMPARATIVE_TAIL = "de|des|du|d'|que|qu'|grand|grande|grands|grandes|petit|petite|spacieux|spacieuse|lumineux|lumineuse|recent|recente|calme|proche|proches|cher|chere|tard|tot|haut|haute|bas|basse|pres|loin|rapide|facile|eleve|elevee|beau|belle|joli|jolie|vaste|clair|claire|grand-e|important|importante";

    /**
     * Tier 3 — procedural tells. `CLAUDE.md` calls these "the cheapest reliable discriminator the
     * domain offers", and it is right: a listing can avoid every financing acronym and still give
     * itself away by demanding an SNE number.
     *
     * The intermediate-side tells are listed first for readability only; resolution is by position
     * and length, as everywhere else.
     */
    private const array PROCEDURAL = [
        // Intermediate: allocated by the landlord, no commission d'attribution.
        // Both literals must carry the ATTRIBUTION sense explicitly. A bare `sans commission` was
        // here until 2026-08-06 and is a fee disclaimer — in the wild it is almost always
        // `sans commission d'agence`, which says nothing about how the flat is allocated and which
        // bailleurs sociaux advertise too. Tier 3 clears the floor unaided, so it turned a
        // fail-closed digest into a notification on a phrase orthogonal to tenure.
        'sans condition de commission' => Tenure::LLI,
        "sans commission d'attribution" => Tenure::LLI,
        'attribution directe' => Tenure::LLI,
        // Social: allocated through the national register and a commission.
        "numero unique d'enregistrement" => Tenure::SOCIAL,
        'numero unique' => Tenure::SOCIAL,
        "systeme national d'enregistrement" => Tenure::SOCIAL,
        'sne' => Tenure::SOCIAL,
        'demande de logement social' => Tenure::SOCIAL,
        "commission d'attribution" => Tenure::SOCIAL,
        'commission attribution' => Tenure::SOCIAL,
    ];

    /**
     * Social procedural tells that are negated by an immediately preceding `sans`.
     *
     * This is NOT general negation parsing — the classifier does not do that, and the `trap-009`
     * fixture records the consequence honestly. It is one targeted lookbehind on the one phrase
     * where the negated form is itself a documented intermediate tell, so both readings of
     * "sans commission d'attribution" cannot fire at once and trigger a spurious conflict.
     */
    private const array NEGATED_BY_SANS = ["commission d'attribution", 'commission attribution'];

    public function __construct(private PlafondBands $bands = new PlafondBands()) {}

    public function classify(RawListing $listing, SourceProfile $source): Classification
    {
        try {
            $signals = [
                1 => $this->structuredFieldSignals($listing),
                2 => $this->labelSignals($listing),
                3 => $this->proceduralSignals($listing),
                4 => $this->plafondSignals(),
            ];
        } catch (MalformedText $e) {
            // NOT swallowed into an empty result — that is the defect this replaces. The listing
            // becomes visibly undetermined, with a reason naming the encoding, so it surfaces in
            // the "à vérifier" digest instead of vanishing or matching on the source default.
            return $this->verdict(
                Tenure::UNKNOWN,
                0,
                [new TenureSignal(
                    tier: 0,
                    tenure: Tenure::UNKNOWN,
                    reason: 'texte illisible : ' . $e->getMessage(),
                    evidence: 'malformed-text',
                )],
                $source,
            );
        }

        $signals[2] = $this->dropConventionneWhenIntermediateIsStated($signals[1], $signals[2]);

        // ── Doubts are separated from evidence before anything competes ──────────────────────────
        // An "indécidable" acronym marker (an uppercase PLUS/PLS in a financing collocation that is
        // followed by an ordinary word) is NOT a tenure claim. It used to be emitted as a tier-2
        // signal and then resolved positionally against real labels, which was wrong in both
        // directions and neither was visible to the suite:
        //
        //   - when it LOST the position race it vanished entirely — not an objection (UNKNOWN is
        //     not excluded) and not a contradiction (score() skips same-tier signals) — so
        //     `Loyer intermédiaire … LOGEMENT PLS MODERNE` was notified while the same two
        //     sentences in the opposite order digested. Identical facts, different word order.
        //   - when it WON it masked a determinate label, downgrading an explicit PLAI from REJECT
        //     to a user-visible digest entry.
        //
        // A doubt now competes with nothing. It cannot beat evidence and it cannot be beaten by it;
        // it simply withholds a verdict that would otherwise be a match.
        $doubts = [];

        foreach ($signals as $tier => $tierSignals) {
            $signals[$tier] = array_values(array_filter(
                $tierSignals,
                static function (TenureSignal $s) use (&$doubts): bool {
                    if ($s->tenure === Tenure::UNKNOWN) {
                        $doubts[] = $s;

                        return false;
                    }

                    return true;
                },
            ));
        }

        // Tier 5 is consulted ONLY when nothing else fired — `CLAUDE.md`: "Source default — lowest
        // confidence, used only when nothing else fires." A doubt counts as something having
        // fired: falling back to the source default there would notify the very listing the doubt
        // was raised about.
        $signals[5] = (array_filter($signals) === [] && $doubts === [])
            ? $this->sourceDefaultSignals($source)
            : [];

        /** @var list<TenureSignal> $evidence */
        $evidence = array_merge(...array_values($signals));
        /** @var list<TenureSignal> $flat */
        $flat = [...$evidence, ...$doubts];

        if ($evidence === []) {
            // Either nothing at all, or nothing but doubt. Both are undetermined.
            return $this->verdict(Tenure::UNKNOWN, 0, $flat, $source);
        }

        $winningTier = min(array_keys(array_filter($signals)));
        $winner = $this->resolve($signals[$winningTier]);

        $confidence = $this->score($winningTier, $winner->tenure, $evidence);

        // ── The conflict rule ────────────────────────────────────────────────────────────────────
        // Asymmetric on purpose: it can only ever move a verdict toward withholding. An EXCLUDED
        // winner is never softened — not by a contradicting eligible signal, and not by a doubt.
        if ($winner->tenure->isEligible()) {
            $objections = array_values(array_filter(
                $evidence,
                static fn (TenureSignal $s): bool => $s->tenure->isExcluded(),
            ));

            if ($objections !== [] || $doubts !== []) {
                $against = array_map(
                    static fn (TenureSignal $s): string => $s->evidence,
                    [...$objections, ...$doubts],
                );

                $flat = [...$flat, new TenureSignal(
                    tier: $winningTier,
                    tenure: Tenure::UNKNOWN,
                    reason: sprintf(
                        'conflit : verdict %s contredit ou fragilisé par %d signal(s) (%s) — mis en attente de vérification',
                        $winner->tenure->value,
                        count($against),
                        implode(', ', $against),
                    ),
                    evidence: 'conflict-rule',
                    position: $winner->position,
                )];

                return $this->verdict(Tenure::UNKNOWN, 0, $flat, $source);
            }
        }

        return $this->verdict($winner->tenure, $confidence, $flat, $source);
    }

    /**
     * Every exit from {@see classify()} goes through here, and that is the point.
     *
     * Until 2026-08-06 the two fail-closed paths — no evidence at all, and the conflict rule —
     * constructed their own `Classification` with a hard-coded DIGEST, which made {@see route()}'s
     * undetermined-tenure arm unreachable. `tests/sabotage-check.sh` caught it: that arm could be
     * deleted outright with the suite staying green, because nothing ever reached it. Dead safety
     * code is worse than none — it reads as protection while protecting nothing.
     *
     * @param list<TenureSignal> $signals
     */
    private function verdict(Tenure $tenure, int $confidenceBp, array $signals, SourceProfile $source): Classification
    {
        // The §1 fail-closed rule, applied to the TENURE and not merely to the routing.
        // `spec/PROJECT_BRIEF.md` §4 requires the verdict itself to become UNKNOWN, withholding
        // the notification rather than merely downgrading its priority — so a caller
        // reading `$classification->tenure` has to see UNKNOWN, or the two halves of the object
        // disagree and the next module to be written will believe the eligible one.
        if ($tenure->isEligible() && $confidenceBp < self::FLOOR_BP && $source->mixedTenure) {
            $signals = [...$signals, new TenureSignal(
                tier: 0,
                tenure: Tenure::UNKNOWN,
                reason: sprintf(
                    'confiance %d < %d sur « %s », source à parc mixte (social et intermédiaire) — '
                    . 'tenure non établie, mise en attente de vérification',
                    $confidenceBp,
                    self::FLOOR_BP,
                    $source->name,
                ),
                evidence: 'fail-closed',
            )];

            $tenure = Tenure::UNKNOWN;
            $confidenceBp = 0;
        }

        return new Classification($tenure, $confidenceBp, $signals, $this->route($tenure, $confidenceBp, $source));
    }

    /**
     * Tenure + confidence + source → what the pipeline does with it.
     *
     * The one place the fail-closed rule is applied, so no caller can re-derive it slightly
     * differently.
     */
    private function route(Tenure $tenure, int $confidenceBp, SourceProfile $source): Outcome
    {
        if ($tenure->isExcluded()) {
            return Outcome::REJECT;
        }

        if ($tenure === Tenure::UNKNOWN) {
            return Outcome::DIGEST;
        }

        if ($confidenceBp >= self::FLOOR_BP) {
            return Outcome::MATCH;
        }

        // `CLAUDE.md` §1, verbatim: below the floor AND a source known to mix social with
        // intermediate stock means the tenure is not established. It goes to the "à vérifier"
        // digest. On a source that publishes no social stock at all there is nothing to confuse it
        // with, so a thin signal is still good enough.
        return $source->mixedTenure ? Outcome::DIGEST : Outcome::MATCH;
    }

    /**
     * Confidence: the winning tier's base, adjusted by every other signal.
     *
     * Integer arithmetic throughout — see {@see Classification}.
     *
     * @param list<TenureSignal> $all
     */
    private function score(int $winningTier, Tenure $winner, array $all): int
    {
        $bp = self::TIER_BASE[$winningTier];

        foreach ($all as $signal) {
            if ($signal->tier === $winningTier) {
                continue;
            }

            $bp += $signal->tenure->agreesWith($winner) ? self::CORROBORATION_BP : -self::CONTRADICTION_BP;
        }

        return max(self::BASEMENT_BP, min(self::CEILING_BP, $bp));
    }

    /**
     * Pick one signal from a tier: earliest position wins; on a tie, the longer (more specific)
     * evidence wins.
     *
     * Deterministic by construction — never dependent on the iteration order of a pattern table —
     * because the phorj implementation has to reach the same answer on the same fixture.
     *
     * @param non-empty-list<TenureSignal> $tierSignals
     */
    private function resolve(array $tierSignals): TenureSignal
    {
        // The strlen terms were transposed until 2026-08-06, so the SHORTER evidence won a tie —
        // the opposite of what this docblock says, and reachable: two structured fields each match
        // at offset 0 within their own value, so `{financement: LLI, categorie: PLAI}` was decided
        // by `lli` being three characters. A phorj port written from the docblock would have
        // disagreed with this implementation on exactly that input.
        usort($tierSignals, static fn (TenureSignal $a, TenureSignal $b): int
            => [$a->position, -strlen($a->evidence)] <=> [$b->position, -strlen($b->evidence)]);

        return $tierSignals[0];
    }

    /** @return list<TenureSignal> */
    private function structuredFieldSignals(RawListing $listing): array
    {
        $out = [];

        foreach ($listing->fields as $name => $value) {
            if (!in_array(Text::fieldKey((string) $name), self::TENURE_FIELDS, true)) {
                continue;
            }

            // An EMPTY field value is an absent signal, not a verdict. Sources that emit the key
            // unconditionally would otherwise fire tier 1 on every listing they publish.
            $folded = Text::fold((string) $value);
            if ($folded === '') {
                continue;
            }

            // Inside a financing field, an acronym is unambiguous: the field is not French prose,
            // so `PLUS` there can only mean the financing scheme. The collocation guard is a
            // prose-only rule and would reject a perfectly good `financement: PLUS`.
            foreach ($this->matchLabels($folded) + $this->matchFieldAcronyms($folded) as $position => $hit) {
                $out[] = new TenureSignal(
                    tier: 1,
                    tenure: $hit['tenure'],
                    reason: sprintf('champ structuré %s = « %s »', (string) $name, trim((string) $value)),
                    evidence: $hit['literal'],
                    position: $position,
                );
            }
        }

        return $out;
    }

    /** @return list<TenureSignal> */
    private function labelSignals(RawListing $listing): array
    {
        $folded = Text::fold($listing->text());
        $cased = Text::foldPreserveCase($listing->text());
        $out = [];

        foreach ($this->matchLabels($folded) as $position => $hit) {
            $out[] = new TenureSignal(
                tier: 2,
                tenure: $hit['tenure'],
                reason: sprintf('mention explicite « %s » dans le texte', $hit['literal']),
                evidence: $hit['literal'],
                position: $position,
            );
        }

        foreach (self::AMBIGUOUS_LABELS as $acronym => $tenure) {
            $hit = $this->financingAcronymPosition($cased, $acronym);

            if ($hit === null) {
                continue;
            }

            [$position, $confident] = $hit;

            $out[] = $confident
                ? new TenureSignal(
                    tier: 2,
                    tenure: $tenure,
                    reason: sprintf('acronyme de financement « %s » en contexte', $acronym),
                    evidence: $acronym,
                    position: $position,
                )
                : new TenureSignal(
                    tier: 2,
                    tenure: Tenure::UNKNOWN,
                    reason: sprintf(
                        '« %s » en majuscules après un mot de financement, mais suivi d\'un mot : '
                        . 'label de financement ou adverbe, indécidable — à vérifier',
                        $acronym,
                    ),
                    evidence: $acronym . '?',
                    position: $position,
                );
        }

        return $out;
    }

    /**
     * `CLAUDE.md` glossary: conventionné is treated as social "unless explicitly labelled
     * **intermediate**". When such a label is present, a bare `conventionne` stops being evidence —
     * LLI stock genuinely is conventionné, and leaving the signal in would send a
     * correctly-identified LLI listing to the digest through the conflict rule.
     *
     * TWO CORRECTIONS FROM THE 2026-08-06 REVIEW, both of which this got wrong:
     *
     * 1. It tested `isEligible()`, i.e. LLI **or LIBRE**. LIBRE is not an intermediate label, so a
     *    spurious LIBRE signal disarmed the exclusion — and combined with `logement libre` then
     *    being in the label table, a listing whose own title read *"Logement conventionné"* reached
     *    MATCH with the word absent from its reasons. Only an LLI label qualifies now.
     * 2. It saw tier 2 only, so a `financement: LLI` structured field — the STRONGEST evidence the
     *    ladder has — did not count as "explicitly labelled intermediate" while the weaker text
     *    label did. Both tiers are considered.
     *
     * `conventionne anah` / `conventionne anru` are named regimes; this exception never applies to them.
     *
     * @param list<TenureSignal> $fieldSignals
     * @param list<TenureSignal> $labelSignals
     *
     * @return list<TenureSignal>
     */
    private function dropConventionneWhenIntermediateIsStated(array $fieldSignals, array $labelSignals): array
    {
        $statesIntermediate = array_any(
            [...$fieldSignals, ...$labelSignals],
            static fn (TenureSignal $s): bool => $s->tenure === Tenure::LLI,
        );

        if (!$statesIntermediate) {
            return $labelSignals;
        }

        return array_values(array_filter(
            $labelSignals,
            static fn (TenureSignal $s): bool
                => !($s->tenure === Tenure::CONVENTIONNE && $s->evidence === 'conventionne'),
        ));
    }

    /** @return list<TenureSignal> */
    private function proceduralSignals(RawListing $listing): array
    {
        $folded = Text::fold($listing->text());
        $out = [];

        foreach (self::PROCEDURAL as $literal => $tenure) {
            // Inflected for the same reason as the labels: `commissions d'attribution`,
            // `numéros uniques` and `attributions directes` are all ordinary phrasings.
            $hit = Text::inflectedTokenPosition($folded, $literal);

            if ($hit === null) {
                continue;
            }

            [$position, $matched] = $hit;

            if (in_array($literal, self::NEGATED_BY_SANS, true) && $this->isPrecededBySans($folded, $position)) {
                continue;
            }

            $out[] = new TenureSignal(
                tier: 3,
                tenure: $tenure,
                reason: sprintf('indice de procédure « %s »', $matched),
                evidence: $literal,
                position: $position,
            );
        }

        return $out;
    }

    private function isPrecededBySans(string $folded, int $position): bool
    {
        return preg_match('/\bsans\s+$/u', substr($folded, 0, $position)) === 1;
    }

    /**
     * Tier 4 — inert by construction. See {@see PlafondBands}.
     *
     * Two things must exist before this rung can fire: the ceiling figures per zone and household
     * size, and an extractor for the ceiling a listing quotes. Neither is in this repo, and
     * `CLAUDE.md` hard rule 1 forbids writing the figures from memory. The rung stays in the ladder
     * so the documented priority order is complete and auditable, and it emits nothing rather than
     * guessing — a tier that appears to work and is wrong would drop eligible listings in silence.
     *
     * @return list<TenureSignal>
     */
    private function plafondSignals(): array
    {
        return [];
    }

    /** Exposed so the suite can assert the tier is still inert. */
    public function plafondBands(): PlafondBands
    {
        return $this->bands;
    }

    /** @return list<TenureSignal> */
    private function sourceDefaultSignals(SourceProfile $source): array
    {
        if ($source->defaultTenure === null) {
            return [];
        }

        return [new TenureSignal(
            tier: 5,
            tenure: $source->defaultTenure,
            reason: sprintf(
                'aucun signal dans l\'annonce — défaut de la source « %s » (%s), confiance volontairement sous le seuil',
                $source->name,
                $source->defaultTenure->value,
            ),
            evidence: 'source-default',
        )];
    }

    /**
     * Every {@see LABELS} literal present, keyed by position.
     *
     * Keyed by position so that a longer literal starting at the same offset as a shorter one
     * replaces it — `conventionne anah` overwrites `conventionne` at the same index, which is the
     * longest-match-wins rule with no extra bookkeeping.
     *
     * @return array<int, array{tenure: Tenure, literal: string}>
     */
    private function matchLabels(string $folded): array
    {
        $hits = [];

        foreach (self::LABELS as $literal => $tenure) {
            // Inflected, not exact: French agreement and plurals are not optional decoration.
            // `conventionnée`, `logements sociaux` and `prêts locatifs sociaux` were all silent
            // non-matches while their singular masculine forms matched. See Text.
            $hit = Text::inflectedTokenPosition($folded, $literal);

            if ($hit === null) {
                continue;
            }

            [$position, $matched] = $hit;
            $existing = $hits[$position] ?? null;

            if ($existing === null || strlen($literal) > strlen($existing['literal'])) {
                $hits[$position] = ['tenure' => $tenure, 'literal' => $literal, 'matched' => $matched];
            }
        }

        return $hits;
    }

    /**
     * Acronyms inside a structured field value, where no collocation guard applies.
     *
     * @return array<int, array{tenure: Tenure, literal: string}>
     */
    private function matchFieldAcronyms(string $folded): array
    {
        // ONLY when the whole value is financing vocabulary. The docblock at the call site argues
        // that a structured field "is not French prose" and so needs no collocation guard — true
        // of `financement: PLUS`, false of the generic keys in TENURE_FIELDS. `categorie`,
        // `dispositif` and `typelogement` carry prose in real feeds, and `Pinel Plus` is a real
        // 2023 scheme name: unguarded, that value was tenure PLUS at confidence 97 and a silent
        // REJECT. So a value is treated as a code only if it contains nothing but financing tokens
        // and separators; anything else falls through to the prose path and its collocation guard.
        if (preg_match('/^(?:plus|pls|plai|lli|ou|et|[\s\/\-,:;()])+$/u', $folded) !== 1) {
            return [];
        }

        $hits = [];

        foreach (self::AMBIGUOUS_LABELS as $acronym => $tenure) {
            $position = Text::tokenPosition($folded, mb_strtolower($acronym, 'UTF-8'));

            if ($position !== null) {
                $hits[$position] = ['tenure' => $tenure, 'literal' => $acronym];
            }
        }

        return $hits;
    }

    /**
     * The collocation guard: position of the first occurrence of `$acronym` that is genuinely a
     * financing label, or null if every occurrence is the French adverb.
     *
     * TESTED PER OCCURRENCE, NOT PER DOCUMENT, and the difference is a real hole rather than a
     * refinement. A document-level check asks "is this text in a financing context anywhere?" and
     * "is any occurrence adverbial anywhere?" — so `Logement PLUS. Plus grand que la moyenne.`
     * would have the genuine financing label suppressed by an unrelated adverb later in the
     * description, and a social listing would reach a notification. Each occurrence now carries its
     * own verdict.
     *
     * Both conditions must hold for the SAME occurrence:
     *   1. it sits in a financing collocation — after a financing noun, or beside another acronym;
     *   2. it is not followed by a French comparative (`LOGEMENT PLUS GRAND` satisfies 1).
     *
     * Uppercase is enforced by the pattern itself: `$acronym` is matched literally against
     * case-preserving text, so `logement plus grand` never reaches either condition.
     *
     * @param string $cased accent-folded but case-PRESERVING text — case is the evidence here.
     *                      Its byte offsets align with {@see Text::fold()}'s output, because
     *                      folding removes accents first and lowercasing ASCII preserves length.
     */
    private function financingAcronymPosition(string $cased, string $acronym): ?array
    {
        $a = preg_quote($acronym, '/');
        $colloc = self::COLLOCATION;
        $acros = self::ACRONYMS;
        $sep = '[\s\/\-,:;()]{1,3}';
        $ambiguousAt = null;

        // `=== 0` alone would let a PCRE error (`false`) fall through into the foreach and silently
        // lose every PLUS/PLS detection in the listing. An error is not an absence of matches.
        $found = preg_match_all('/(?<![A-Za-z0-9])' . $a . '(?![A-Za-z0-9])/u', $cased, $matches, PREG_OFFSET_CAPTURE);

        if ($found === false) {
            throw MalformedText::notUtf8('TenureClassifier::financingAcronymPosition (' . preg_last_error_msg() . ')');
        }

        if ($found === 0) {
            return null;
        }

        foreach ($matches[0] as [$literal, $offset]) {
            $before = substr($cased, 0, $offset);
            $after = substr($cased, $offset + strlen($literal));

            $inContext =
                // …after a financing noun: `Logement PLUS`, `Financement / PLS`
                preg_match('/(?:^|[^A-Za-z0-9])(?i:' . $colloc . ')' . $sep . '$/u', $before) === 1
                // …before another financing acronym: `PLUS / PLAI`, `PLS ou PLUS`
                || preg_match('/^' . $sep . '(?:(?i:ou|et)' . $sep . ')?(?:' . $acros . ')(?![A-Za-z0-9])/u', $after) === 1
                // …after another financing acronym: `PLAI / PLUS`
                || preg_match('/(?:^|[^A-Za-z0-9])(?:' . $acros . ')' . $sep . '(?:(?i:ou|et)' . $sep . ')?$/u', $before) === 1;

            if (!$inContext) {
                continue;
            }

            // A financing label ENDS a noun phrase: `Logement PLUS.`, `Logement PLUS - …`,
            // `Logement PLUS, …`, `PLUS / PLAI`, `PLS ou PLUS`. That is a small closed set, unlike
            // the set of French adjectives an adverbial `PLUS` could modify.
            if (preg_match('/^\s*$|^\s*[,;:.!?)\]\/\-–—]|^\s*(?:(?i:ou|et)\s+)?(?:' . $acros . ')(?![A-Za-z0-9])/u', $after) === 1) {
                return [$offset, true];
            }

            // A word follows. If it is a comparative we know it was the adverb, so drop it.
            if (preg_match('/^\s*(?i:' . self::COMPARATIVE_TAIL . ')(?![A-Za-z0-9])/u', $after) === 1) {
                continue;
            }

            // Otherwise it is genuinely ambiguous — `LOGEMENT PLUS MODERNE` in a shouted title.
            // Do NOT guess. See the method docblock for why this becomes a digest entry.
            $ambiguousAt ??= $offset;
        }

        return $ambiguousAt === null ? null : [$ambiguousAt, false];
    }
}
