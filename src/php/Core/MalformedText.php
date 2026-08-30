<?php

declare(strict_types=1);

namespace Scout\Core;

/**
 * The listing's text is not valid UTF-8, so no claim can be made about its tenure.
 *
 * THIS EXISTS BECAUSE THE SILENT VERSION WAS A §1 BREACH. `preg_replace('/\s+/u', …)` returns
 * `null` on malformed UTF-8, and the `(string)` cast that used to sit in front of it turned that
 * `null` into `''`. `Text::fold()` then reported *"this listing has no text"* when the truth was
 * *"PCRE could not read this listing"*. Every tier found nothing, the source default fired at 50,
 * and on a pure source `route()` returned MATCH — with the reason line *"aucun signal dans
 * l'annonce"*, which is not a hedge but a false statement about the listing, on the developer's
 * phone. A body carrying `conventionné PLAI … numéro unique d'enregistrement` in cp1252 classified
 * as LLI/MATCH instead of CONVENTIONNE/REJECT.
 *
 * That is `CLAUDE.md` hard rule 3 in its purest form: an error became an absence. Both real causes
 * are ordinary — a French institutional site serving cp1252 under a `utf-8` declaration, and a
 * description truncated mid-multibyte-character.
 *
 * Callers must not convert this back into an empty result. {@see \Scout\Rent\Core\TenureClassifier::classify()}
 * turns it into `UNKNOWN` → digest with a reason that names the encoding, so the listing is visible
 * and unclassified rather than invisible or wrongly matched.
 */
final class MalformedText extends \RuntimeException
{
    /**
     * The text still carries HTML entities, so an adapter did not finish decoding it.
     *
     * Decoding is the ADAPTER's job — a classifier that decodes entities hides a broken adapter,
     * which is the failure shape `CLAUDE.md` hard rule 2 exists to prevent. But the previous
     * behaviour was worse than either: an entity inside a label deleted that label and left the
     * others intact, and the direction that broke was not symmetric.
     * `Ce logement&nbsp;social a loyer intermediaire.` lost `logement social` and kept
     * `loyer intermediaire`, classifying an explicitly social listing as LLI/MATCH.
     *
     * So an entity is now treated as evidence of an upstream bug rather than as text: the listing
     * is undetermined, it goes to the digest, and the reason says which adapter to go and fix.
     */
    public static function undecodedEntities(string $sample): self
    {
        return new self(sprintf(
            'the text still contains undecoded HTML entities (e.g. "%s"), so the adapter did not '
            . 'finish its job. Decoding is the adapter\'s responsibility; an entity inside a label '
            . 'deletes that label while leaving others intact, which has already turned an '
            . 'explicitly social listing into an eligible one',
            $sample,
        ));
    }

    public static function notUtf8(string $where): self
    {
        return new self(sprintf(
            'text is not valid UTF-8 (%s) — refusing to classify it, because folding it would '
            . 'silently produce an empty string, and an empty string reads as a listing that '
            . 'named no financing scheme at all',
            $where,
        ));
    }
}
