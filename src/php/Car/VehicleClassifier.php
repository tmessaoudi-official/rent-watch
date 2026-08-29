<?php

declare(strict_types=1);

namespace Scout\Car;

use Scout\Core\MalformedText;
use Scout\Core\Text;

/**
 * The car domain's §1: a used car that must NEVER be surfaced as a match.
 *
 * The excluded set is a developer ruling (2026-08-26, decision 9) and is NOT config: `VEI`
 * (économiquement irréparable), `VGE` / procédure VE, gagé / opposition, pour pièces, épave, sans
 * carte grise, CT non fourni / non roulant — and `accidenté`, réparé or not, which is a
 * risk-appetite ruling rather than a safety fact and therefore the first line to relax, by a
 * commit here and never by a config key. Do not add a key, flag or env var that re-admits any of
 * these.
 *
 * **EVERY TERM ARRIVES NEGATED IN ORDINARY COPY.** *Jamais accidenté*, *non gagé*, *aucun
 * accident*, *véhicule non accidenté* are the honest listing's own reassurance block — a bare scan
 * rejects the good ads and keeps the silent ones, and over-rejection is invisible by definition
 * (the rent side's lift-negation lesson, on a set where the negated form is the COMMON one). So a
 * negation window is read before every term that can be negated, and the corpus carries both
 * forms of each. The exclusion phrases that are themselves negations — *sans carte grise*,
 * *CT non fourni*, *non roulant* — are matched literally and never run through that window.
 *
 * Unreadable text is REJECTED, not admitted: a breakage is never an absence (hard rule 3), and in
 * this domain the fail-closed direction is not to push.
 */
final class VehicleClassifier
{
    /**
     * Terms that ordinary copy negates. Matched on FOLDED text (lowercase, accents stripped).
     *
     * @var array<string, string> reason label => pattern
     */
    private const array NEGATABLE = [
        'accidenté' => '~\baccident(?:e|ee|es|ees|s)?\b~u',
        'gagé' => '~\bgage(?:e|ee|es|ees)?\b~u',
        'opposition' => '~\bopposition\b~u',
        'épave' => '~\bepaves?\b~u',
        'VEI' => '~\bvei\b~u',
        'VGE' => '~\bvge\b~u',
        'procédure VE' => '~\bprocedure ve\b~u',
        'économiquement irréparable' => '~\beconomiquement irreparable\b~u',
        'pour pièces' => '~\bpour pieces?\b~u',
    ];

    /**
     * Exclusion phrases that are NEGATIONS THEMSELVES — the absence of a paper or of a function.
     * Literal, never passed through the negation window (a window would read `sans carte grise`
     * as a reassurance about `carte grise`).
     *
     * @var array<string, string>
     */
    private const array LITERAL = [
        'sans carte grise' => '~\bsans carte grise\b|\bpas de carte grise\b|\bcarte grise (?:absente|manquante)\b~u',
        'CT non fourni' => '~\b(?:ct|controle technique) (?:non|pas) (?:fourni|fait|ok)\b|\bsans (?:ct|controle technique)\b|\bpas de (?:ct|controle technique)\b~u',
        'non roulant' => '~\bnon roulant\b|\bne roule (?:pas|plus)\b|\bne demarre (?:pas|plus)\b~u',
    ];

    /**
     * What a negation looks like just before a term: up to two words back, on folded text.
     * `0 accident` and `zero accident` are how dealers write "aucun".
     */
    private const string NEGATION_BEFORE = '~(?:\b(?:jamais|non|pas|aucun|aucune|sans|ni|zero|0)\b)\s*(?:\S+\s+){0,2}$~u';

    /** What a negation looks like just after: `accidenté : non`, `gagé ? non`. */
    private const string NEGATION_AFTER = '~^\s*[:?]?\s*(?:non|jamais)\b~u';

    public function classify(VehicleListing $listing): VehicleClassification
    {
        try {
            $text = Text::fold($listing->text());
        } catch (MalformedText $e) {
            return new VehicleClassification(VehicleOutcome::REJECT, ['texte illisible : ' . $e->getMessage()]);
        }

        $reasons = [];

        foreach (self::LITERAL as $label => $pattern) {
            if (preg_match($pattern, $text, $m) === 1) {
                $reasons[] = $label . ' — « ' . $m[0] . ' »';
            }
        }

        foreach (self::NEGATABLE as $label => $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }
            foreach ($matches[0] as [$hit, $offset]) {
                $before = substr($text, max(0, $offset - 40), min(40, $offset));
                $after = substr($text, $offset + strlen($hit), 12);
                if (preg_match(self::NEGATION_BEFORE, $before) === 1 || preg_match(self::NEGATION_AFTER, $after) === 1) {
                    continue; // the reassurance form — "jamais accidenté" is what a good ad says
                }
                $reasons[] = $label . ' — « ' . trim($hit) . ' »';
                break;
            }
        }

        return $reasons === []
            ? new VehicleClassification(VehicleOutcome::MATCH, [])
            : new VehicleClassification(VehicleOutcome::REJECT, array_values(array_unique($reasons)));
    }
}
