<?php

declare(strict_types=1);

namespace Scout\Adapters\Html;

use Dom\Element;
use Dom\HTMLElement;
use Scout\Core\Prose;

/**
 * The micro-syntax a `type: html` field map is written in, and the resolver that reads it.
 *
 * A JSON field map names a dotted path; an HTML one names a **CSS selector**, optionally an
 * attribute, optionally a capture. Three forms, composable in that order:
 *
 * | Written                                | Yields                                                |
 * |----------------------------------------|-------------------------------------------------------|
 * | `.featured-price .demi-condensed`      | the first match's text, whitespace normalised          |
 * | `a@href`                               | the first match's `href` attribute                     |
 * | `@data-ref`                            | an attribute of the ITEM element itself (empty selector)|
 * | `.details span => ([\d.,]+)\s*m²`      | capture group 1 of that pattern, applied to the text   |
 * | `a@href => /([^/]+)$`                  | all three at once                                      |
 *
 * **Why this lives here and not in `Config\FieldMap`.** `FieldMap` validates that each entry is a
 * non-empty string and nothing more — dotted-path *semantics* already live adapter-side, in
 * {@see \Scout\Adapters\Payload::at()}. So HTML semantics belong adapter-side too, and the
 * config layer needs no schema change, no new key and no second dialect to validate. The one thing
 * the loader does enforce is that a `type: html` source names an `item_selector`.
 *
 * **Why a capture group is necessary rather than a convenience.** Real cards pack two quantities
 * into one text node — In'li's is `3 pièces · 55.32 m²`. Without a capture, the surface would have
 * to be read out of that string by a general-purpose number parser, and the one this project has
 * deliberately returns the FIRST token (see `Payload::number()`, and the fusion bug that taught it
 * to). A capture says which quantity is wanted instead of hoping the order never changes.
 *
 * **Hard rule 9 governs every failure here.** No match, no such attribute, an empty capture, an
 * absent element — all of them are `null`, meaning *unknown*. None of them is `''` and none is `0`.
 * A field that silently became zero would be compared against a minimum and reject the listing,
 * and a silent over-rejection is invisible because nothing arrives.
 */
final readonly class Selector
{
    /** Separates the selector from the capture pattern. `=>` cannot occur in a CSS selector. */
    private const string CAPTURE = '=>';

    /**
     * An attribute name, per the HTML spec's practical subset.
     *
     * This is what makes the `@` split safe. A CSS selector may legitimately contain an `@` inside
     * an attribute VALUE — `[href="mailto:contact@example.fr"]` — and splitting on it blindly would
     * turn a working selector into a broken one plus a nonsense attribute name. Requiring the tail
     * to be a bare attribute name rejects `example.fr"]` and leaves that selector intact.
     */
    private const string ATTR_NAME = '~^[A-Za-z_:][-A-Za-z0-9_:.]*$~';

    private function __construct(
        public string $css,
        public ?string $attribute,
        public ?string $capture,
    ) {}

    /** Parse one field-map entry. Never throws: a malformed entry resolves to `null` at use. */
    public static function parse(string $entry): self
    {
        $capture = null;
        $head = $entry;

        $split = strpos($entry, self::CAPTURE);
        if ($split !== false) {
            $head = substr($entry, 0, $split);
            $pattern = trim(substr($entry, $split + strlen(self::CAPTURE)));
            $capture = $pattern === '' ? null : $pattern;
        }

        $head = trim($head);
        $attribute = null;

        $at = strrpos($head, '@');
        if ($at !== false) {
            $tail = substr($head, $at + 1);
            if (preg_match(self::ATTR_NAME, $tail) === 1) {
                $attribute = $tail;
                $head = rtrim(substr($head, 0, $at));
            }
        }

        return new self($head, $attribute, $capture);
    }

    /**
     * Resolve against one listing element.
     *
     * @return string|null the value, or `null` for *unknown* — never `''`, never `'0'`
     */
    public function resolve(Element $item): ?string
    {
        $target = $this->target($item);
        if ($target === null) {
            return null;
        }

        $raw = $this->attribute === null
            ? self::normalise($target->textContent)
            : ($target->hasAttribute($this->attribute) ? trim($target->getAttribute($this->attribute)) : null);

        if ($raw === null || $raw === '') {
            return null;
        }

        if ($this->capture === null) {
            return $raw;
        }

        // A RESERVED capture is a named reader, not a pattern. `'0'` is a real floor, so this
        // returns the reader's answer as-is rather than passing it through an emptiness check that
        // would delete the rez-de-chaussée (hard rule 9).
        $reader = Prose::readerIn($this->capture);

        return $reader === null
            ? self::captureFrom($raw, $this->capture)
            : Prose::read($reader, $raw);
    }

    /**
     * The element this entry points at — the item itself when the selector half is empty.
     *
     * An invalid selector is `null` rather than an exception. It is not silence: a field map whose
     * selectors have rotted produces listings with no `ref`, and `ListingMapper` refuses those
     * loudly by name. The genuinely fatal case — the ITEM selector matching nothing — is caught in
     * {@see \Scout\Adapters\HtmlSource}, where it belongs, because that one is indistinguishable
     * from a quiet market and is what hard rule 2 exists for.
     */
    private function target(Element $item): ?Element
    {
        if ($this->css === '') {
            return $item;
        }

        try {
            $found = $item->querySelector($this->css);
        } catch (\Throwable) {
            return null;
        }

        return $found instanceof Element || $found instanceof HTMLElement ? $found : null;
    }

    /** Group 1 if the pattern has one, otherwise the whole match. `null` when it does not match. */
    private static function captureFrom(string $subject, string $pattern): ?string
    {
        $delimited = '~' . str_replace('~', '\~', $pattern) . '~u';

        // A pattern that does not compile is a broken field map, not a broken listing. It resolves
        // to null for every item, so `ref` goes missing and `ListingMapper` names the source.
        if (@preg_match($delimited, $subject, $m) !== 1) {
            return null;
        }

        $value = trim($m[1] ?? $m[0]);

        return $value === '' ? null : $value;
    }

    /**
     * Collapse every run of whitespace to one space.
     *
     * HTML text is full of newlines and indentation that carry no meaning, and a listing's own text
     * is compared, hashed and shown to a human. `\s` is not enough on its own here: the pages this
     * reads use U+00A0 between a figure and its unit, which survives `\s` in a non-unicode pattern
     * and would leave `1 005 €` looking equal to itself but hashing differently across a redesign.
     */
    public static function normalise(string $text): string
    {
        return trim((string) preg_replace('~[\s\x{00A0}\x{202F}\x{2009}]+~u', ' ', $text));
    }
}
