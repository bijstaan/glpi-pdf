<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpipdf;

/**
 * A document, described as data.
 *
 * This is the whole contract a contributing plugin sees. It says *what* the
 * document contains — a heading, a table, a checklist, a callout — and says
 * nothing about how any of it is drawn. {@see Engine} owns that, and owns it
 * alone.
 *
 * The alternative was letting each plugin hand over a string of HTML for TCPDF
 * to render, which is faster to write exactly once. It was rejected because of
 * what happens after that: TCPDF supports a narrow, undocumented subset of CSS
 * — no flex, no grid, no float, no classes — so every plugin would separately
 * discover the same limits, and every plugin's document would drift into its
 * own dialect of them. A suite whose six exports look like six products is the
 * failure this plugin exists to prevent, given that the whole point is handing
 * someone a document with your own name on it.
 *
 * The blocks are deliberately few and none of them is a layout primitive.
 * There is no column, no box, no width. A contributor chooses what to say; the
 * engine decides what it looks like, which is the only arrangement where
 * changing the house style is one edit rather than six.
 *
 * Everything here is plain text. Nothing is escaped on the way in and nothing
 * may contain markup — {@see Engine} escapes at the point it builds markup, so
 * a contributor that pre-escaped would produce visible `&amp;` in an entity's
 * document.
 */
final class Doc
{
    /** Checklist item states, in the order they read on a page. */
    public const DONE    = 'done';
    public const SKIPPED = 'skipped';
    public const PENDING = 'pending';
    public const NA      = 'na';

    /** Callout tones. */
    public const INFO   = 'info';
    public const WARN   = 'warn';
    public const DANGER = 'danger';

    public string $subtitle = '';

    /**
     * What this document is *of* — "Ticket #1164", "SOP r12".
     *
     * Printed under the title and used in the filename. Separate from the
     * subtitle because it is an identifier rather than a description, and it is
     * the thing somebody reads out on the phone.
     */
    public string $reference = '';

    /** @var array<string,string> shown as a block under the masthead */
    public array $meta = [];

    /** @var array<int,array<string,mixed>> */
    public array $blocks = [];

    /** Printed small at the very end, after the last block. */
    public string $footnote = '';

    private function __construct(public string $title)
    {
    }

    public static function make(string $title): self
    {
        return new self($title);
    }

    // ------------------------------------------------------------ masthead

    public function subtitle(string $text): self
    {
        $this->subtitle = $text;

        return $this;
    }

    public function reference(string $text): self
    {
        $this->reference = $text;

        return $this;
    }

    /**
     * The facts block under the title — entity, owner, dates, status.
     *
     * Distinct from {@see self::kv()}, which is a block anywhere in the body.
     * This one is the document's identity, and the engine gives it the same
     * position and weight in every document so a reader knows where to look.
     *
     * @param array<string,string> $pairs
     */
    public function meta(array $pairs): self
    {
        foreach ($pairs as $label => $value) {
            // A blank value is left out rather than printed as an empty row. A
            // document full of "Resolved: —" reads as a broken export; a
            // shorter list reads as a shorter list.
            if (trim((string) $value) !== '') {
                $this->meta[(string) $label] = (string) $value;
            }
        }

        return $this;
    }

    public function footnote(string $text): self
    {
        $this->footnote = $text;

        return $this;
    }

    // -------------------------------------------------------------- blocks

    /** A numbered top-level division. Starts on a fresh run of the page. */
    public function section(string $title): self
    {
        return $this->push(['type' => 'heading', 'level' => 1, 'text' => $title]);
    }

    /** A subdivision inside a section. */
    public function subsection(string $title): self
    {
        return $this->push(['type' => 'heading', 'level' => 2, 'text' => $title]);
    }

    public function text(string $body): self
    {
        return trim($body) === '' ? $this : $this->push(['type' => 'text', 'body' => $body]);
    }

    /** The same, in the quieter grey used for guidance and asides. */
    public function muted(string $body): self
    {
        return trim($body) === ''
            ? $this
            : $this->push(['type' => 'text', 'body' => $body, 'muted' => true]);
    }

    /**
     * Label/value pairs as a two-column block.
     *
     * @param array<string,string> $pairs
     */
    public function kv(array $pairs): self
    {
        $clean = [];
        foreach ($pairs as $label => $value) {
            if (trim((string) $value) !== '') {
                $clean[(string) $label] = (string) $value;
            }
        }

        return $clean === [] ? $this : $this->push(['type' => 'kv', 'pairs' => $clean]);
    }

    /**
     * A table.
     *
     * `$widths` are percentages and are a hint the engine may normalise; a
     * contributor that gets them wrong gets an evenly divided table rather than
     * a broken one. Omitting them entirely is the normal case.
     *
     * @param array<int,string>            $headers
     * @param array<int,array<int,string>> $rows
     * @param array<int,int>               $widths
     */
    public function table(array $headers, array $rows, array $widths = []): self
    {
        return $rows === []
            ? $this
            : $this->push([
                'type'    => 'table',
                'headers' => array_values($headers),
                'rows'    => array_values($rows),
                'widths'  => array_values($widths),
            ]);
    }

    /**
     * A procedure, with each item's state as a glyph.
     *
     * The one block that is not a general-purpose shape, because the thing it
     * renders is not a general-purpose shape: "was this step done, skipped or
     * never asked, who says so, and when" is the question a checklist exists to
     * answer, and a table of it loses the glyph column that makes a page of
     * thirty steps scannable.
     *
     * Each item: `number`, `label`, and optionally `help`, `state` (one of the
     * class constants), `answer`, `meta`.
     *
     * @param array<int,array<string,mixed>> $items
     */
    public function checklist(array $items): self
    {
        return $items === [] ? $this : $this->push(['type' => 'checklist', 'items' => $items]);
    }

    /** @param array<int,string> $items */
    public function bullets(array $items): self
    {
        $items = array_values(array_filter($items, static fn($i): bool => trim((string) $i) !== ''));

        return $items === [] ? $this : $this->push(['type' => 'bullets', 'items' => $items]);
    }

    /**
     * Entries in time order — a ticket's followups, an incident's updates.
     *
     * Each entry: `when`, `who`, and optionally `kind` and `body`.
     *
     * @param array<int,array<string,mixed>> $entries
     */
    public function timeline(array $entries): self
    {
        return $entries === [] ? $this : $this->push(['type' => 'timeline', 'entries' => $entries]);
    }

    /**
     * A proportion, drawn as a bar.
     *
     * `$percent` is what the bar shows and `$caption` is what it says
     * underneath; the two are separate because "94%" and "312 of 332 within
     * target" are both wanted and neither can be derived from the other without
     * the engine knowing what is being measured.
     */
    public function meter(string $label, float $percent, string $caption = ''): self
    {
        return $this->push([
            'type'    => 'meter',
            'label'   => $label,
            'percent' => max(0.0, min(100.0, $percent)),
            'caption' => $caption,
        ]);
    }

    /** A boxed aside. Use sparingly; three in a row is a list. */
    public function note(string $body, string $tone = self::INFO, string $title = ''): self
    {
        return trim($body) === '' ? $this : $this->push([
            'type'  => 'note',
            'body'  => $body,
            'tone'  => in_array($tone, [self::INFO, self::WARN, self::DANGER], true) ? $tone : self::INFO,
            'title' => $title,
        ]);
    }

    public function pageBreak(): self
    {
        return $this->push(['type' => 'pagebreak']);
    }

    public function spacer(): self
    {
        return $this->push(['type' => 'spacer']);
    }

    /**
     * Is there nothing here at all?
     *
     * The masthead counts. A reader who ticked only "Summary and fields" asked
     * for a document that is a title and a facts block, and answering that with
     * "there is nothing to put in that document" — which an earlier version did
     * — is the export refusing a legitimate request because of how it happened
     * to be represented.
     */
    public function isEmpty(): bool
    {
        return $this->blocks === [] && $this->meta === [] && trim($this->footnote) === '';
    }

    /**
     * Is there a body, as opposed to only a masthead?
     *
     * The question an *appendix* is asked. An appendix contributes blocks and
     * nothing else — its title becomes a heading and its meta is never read —
     * so one with no blocks would add a heading with nothing under it.
     */
    public function hasBody(): bool
    {
        return $this->blocks !== [];
    }

    /**
     * Append another document's body to this one.
     *
     * How an appendix arrives: a plugin that has something to say about
     * somebody else's itemtype — an SOP checklist on a ticket — contributes a
     * Doc, and the ticket's own document absorbs its blocks under a heading.
     * The appendix's title becomes that heading, which is why an appendix's
     * title should read as a section rather than as a document name.
     */
    public function append(self $other, bool $asSection = true): self
    {
        if (!$other->hasBody()) {
            return $this;
        }

        if ($asSection && trim($other->title) !== '') {
            $this->section($other->title);
            if ($other->subtitle !== '') {
                $this->muted($other->subtitle);
            }
        }

        foreach ($other->blocks as $block) {
            $this->blocks[] = $block;
        }

        return $this;
    }

    /**
     * A filename stem, safe on every filesystem and readable in a downloads
     * folder.
     *
     * Built from the reference and the title rather than from an id, because
     * the file leaves the instance: `ticket-1164-printer-wont-clear.pdf` means
     * something in an entity's mail client and `export-1164.pdf` does not.
     */
    public function filename(): string
    {
        $stem = trim($this->reference . ' ' . $this->title);
        $stem = (string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $stem);
        $stem = trim((string) preg_replace('/-+/', '-', $stem), '-');

        if ($stem === '') {
            $stem = 'document';
        }

        return mb_strtolower(mb_substr($stem, 0, 80)) . '.pdf';
    }

    /** @param array<string,mixed> $block */
    private function push(array $block): self
    {
        $this->blocks[] = $block;

        return $this;
    }
}
