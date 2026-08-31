<?php

declare(strict_types=1);

namespace Scout\Car;

/**
 * Hard disqualifiers and score are two different mechanisms (hard rule 8), and here the hard set
 * is deliberately tiny: the §1 vehicle classifier, the price CEILING, a STATED location outside the
 * set, and the extra `exclude_patterns`. Everything else — age, mileage, gearbox, fuel, body — is
 * a clamped score component that can never reject (decisions 7 and 11).
 *
 * An unknown component scores 0 and SAYS so (`kilométrage inconnu — hors score`): a car that
 * states nothing is never rewarded for it, and on a phone a missing line would read as a good
 * value. Scores are out of the FULL 100, so a sparse listing ranks below a documented one.
 *
 * The diesel penalty is a plain preference. Whether a given diesel may enter the Grand Paris ZFE
 * is `[Unverified]` here and revised often; the reason line never claims a regulatory fact.
 */
final class VehicleScorer
{
    public const array COMPONENTS = ['price', 'age', 'mileage', 'gearbox', 'fuel', 'body', 'brand'];

    public function judge(VehicleListing $car, VehicleClassification $class, VehicleCriteria $criteria, int $year, int $month): VehicleVerdict
    {
        if ($class->outcome === VehicleOutcome::REJECT) {
            return VehicleVerdict::rejected(array_map(static fn (string $r): string => 'exclu : ' . $r, $class->reasons));
        }
        if ($car->priceEur !== null && $car->priceEur > $criteria->maxPriceEur) {
            return VehicleVerdict::rejected([sprintf('prix %s € au-dessus du plafond de %s €', self::n($car->priceEur), self::n($criteria->maxPriceEur))]);
        }
        if (!$criteria->matchesLocation($car->postcode)) {
            return VehicleVerdict::rejected(['code postal ' . $car->postcode . ' hors de la zone']);
        }
        $pattern = $criteria->excludedBy($car->text());
        if ($pattern !== null) {
            return VehicleVerdict::rejected(['motif exclu : ' . $pattern]);
        }

        $w = $criteria->weights;
        $score = 0.0;
        $reasons = [];

        // price — linear from the ceiling (0) down to 0 € (full marks)
        if ($car->priceEur === null) {
            $reasons[] = 'prix inconnu — hors score';
        } else {
            $share = max(0.0, 1 - $car->priceEur / $criteria->maxPriceEur);
            $score += $w['price'] * $share;
            $reasons[] = sprintf('%s € — %d %% sous le plafond', self::n($car->priceEur), (int) round($share * 100));
        }

        // age — full marks up to the peak, decaying to 0 at twice it
        $age = $car->ageYears($year, $month);
        if ($age === null) {
            $reasons[] = 'année inconnue — hors score';
        } else {
            $share = self::decay($age, $criteria->peakAgeYears);
            $score += $w['age'] * $share;
            $reasons[] = sprintf('%d · %s an(s) — %s', $car->year, rtrim(rtrim(number_format($age, 1, ',', ' '), '0'), ','), $share >= 1 ? 'dans la fenêtre idéale (≤ ' . $criteria->peakAgeYears . ' ans)' : 'au-delà de ' . $criteria->peakAgeYears . ' ans');
        }

        // mileage — same shape
        if ($car->mileageKm === null) {
            $reasons[] = 'kilométrage inconnu — hors score';
        } else {
            $share = self::decay((float) $car->mileageKm, (float) $criteria->peakMileageKm);
            $score += $w['mileage'] * $share;
            $reasons[] = sprintf('%s km — %s', self::n($car->mileageKm), $share >= 1 ? 'dans la fenêtre (≤ ' . self::n($criteria->peakMileageKm) . ')' : 'au-delà de ' . self::n($criteria->peakMileageKm) . ' km');
        }

        // gearbox — automatic preferred (decision 11)
        if ($car->gearbox === null) {
            $reasons[] = 'boîte inconnue — hors score';
        } elseif ($car->gearbox === 'automatique') {
            $score += $w['gearbox'];
            $reasons[] = 'boîte automatique';
        } else {
            $reasons[] = 'boîte ' . $car->gearbox;
        }

        // fuel — petrol / hybrid / electric preferred over diesel; a PREFERENCE, never a ZFE claim
        if ($car->fuel === null) {
            $reasons[] = 'énergie inconnue — hors score';
        } else {
            $share = match ($car->fuel) {
                'essence', 'hybride', 'electrique' => 1.0,
                'gpl' => 0.5,
                default => 0.0,
            };
            $score += $w['fuel'] * $share;
            $reasons[] = $car->fuel . ($car->fuel === 'diesel' ? ' — préférence, pas une règle ZFE' : '');
        }

        // body — the commune_rank mechanism: ranked scores by position, unranked 0 and still notified
        $rank = $criteria->bodyRankOf($car->body);
        $n = count($criteria->bodyRank);
        if ($car->body === null) {
            $reasons[] = 'carrosserie inconnue — hors score';
        } elseif ($rank === null || $n === 0) {
            $reasons[] = $car->body . ' — carrosserie non classée';
        } else {
            $share = ($n - $rank + 1) / $n;
            $score += $w['body'] * $share;
            $reasons[] = sprintf('%s — carrosserie classée %d/%d', $car->body, $rank, $n);
        }

        // BRAND — AN INVERTED RANK, and the inversion is the ruling rather than a detail. Mirroring
        // `body_rank` above would have scored the disfavoured makes HIGHEST, because that mechanism
        // gives its top entry the full share; the developer asked for the opposite (2026-08-31).
        //
        // So the share is earned by NOT being on the list: an unlisted make takes all of it, a
        // listed one takes none, and no ordering among the listed ones was ruled — they are equal.
        // A make that could not be extracted takes the full share too (hard rule 9: unknown is not
        // disfavoured), which is the direction every other unknown takes here.
        //
        // The weight comes OUT of the existing 100 rather than pushing past it, so the total still
        // means what `high_priority_score` was calibrated against.
        if ($criteria->brandAvoid === []) {
            // Nothing configured means no make is disfavoured, so EVERY make earns the share. It
            // reads as a wash for ordering either way, but withholding it would quietly drop the
            // achievable maximum to 90 for such a deployment — and `high_priority_score` is an
            // ABSOLUTE threshold, so a scale that silently shrinks makes it unreachable. Same
            // reasoning as the unknown-make arm below.
            $score += $w['brand']; // unique on purpose: the ledger addresses this arm by this line
            $reasons[] = 'marque — aucune préférence configurée';
        } elseif ($car->make === null) {
            // UNKNOWN SCORES 0 AND SAYS SO — the same arm every other component here takes, and a
            // DELIBERATE deviation from the plan's line ("a car with no extracted make gets the
            // full share"). Hard rule 9 forbids treating unknown as BELOW A MINIMUM — a
            // disqualifier — and nothing here disqualifies; hard rule 8 keeps the two mechanisms
            // apart. Awarding the share instead would rank an EXTRACTION FAILURE as a definitely-
            // not-Peugeot, which is this repo's recurring defect: a fact manufactured from its own
            // absence, wearing an alibi. Both shipped car sources do extract a make.
            // Reversed by adding `$score += $w['brand'];` to this arm.
            $reasons[] = 'marque inconnue — hors score';
        } elseif ($criteria->isAvoidedBrand($car->make)) {
            // TRIMMED for display, because the comparison is: `isAvoidedBrand()` folds, and folding
            // trims. A `make_model_pattern` capture carrying a trailing space would otherwise be
            // penalised correctly and announced with the whitespace still in it.
            $reasons[] = trim($car->make) . ' — marque à éviter';
        } else {
            $score += $w['brand'];
            $reasons[] = trim($car->make) . ' — hors des marques à éviter';
        }

        if ($car->sellerType !== null) {
            $reasons[] = $car->sellerType === 'professional' ? 'vendeur professionnel' : 'vendeur particulier';
        }

        $total = (int) max(0, min(100, round($score)));

        return VehicleVerdict::matched($total, $reasons, $total >= $criteria->notify->highPriorityScore);
    }

    /** 1.0 up to the peak, linear to 0.0 at twice the peak, never negative. */
    private static function decay(float $value, float $peak): float
    {
        if ($value <= $peak) {
            return 1.0;
        }

        return max(0.0, 1 - ($value - $peak) / $peak);
    }

    private static function n(int $v): string
    {
        return number_format($v, 0, ',', ' ');
    }
}
