<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpipdf;

use CommonDBTM;
use Session;

/**
 * One export: from an itemtype and an id to bytes on their way to a browser.
 *
 * The permission model is the only interesting thing here, and it is
 * deliberately not a right of its own.
 *
 * A PDF of a ticket contains the ticket. Anyone who can read the record can
 * read the export, and nobody else — so the check is `canViewItem()` on the
 * item itself, re-derived from the database on every request rather than taken
 * from whatever the browser sent. Inventing a `plugin_glpipdf_export` right
 * would create the possibility of an instance where somebody can read a ticket
 * on screen but not on paper, or worse, the reverse: a plugin right granted to
 * a profile becomes a way to read every ticket in the estate through a URL with
 * an id in it.
 *
 * Appendices are held to the same rule by construction — they are handed the
 * same already-authorised item.
 */
final class Export
{
    /**
     * Build the document for one item.
     *
     * `$key` picks between offers when an itemtype has more than one — a
     * procedure and a filled-in run are different documents of the same SOP.
     * Omitted, the first offer wins, which is what a bulk export gets.
     */
    public static function document(CommonDBTM $item, string $key = '', ?array $parts = null): ?Doc
    {
        $offers = Registry::documentsFor($item::getType());

        if ($offers === []) {
            return null;
        }

        $offer = null;
        if ($key !== '') {
            foreach ($offers as $candidate) {
                if ($candidate['key'] === $key) {
                    $offer = $candidate;
                    break;
                }
            }
        }

        $offer ??= $offers[0];

        $doc = Registry::build($offer, $item, $parts);
        if ($doc === null) {
            return null;
        }

        foreach (Registry::appendicesFor($item::getType()) as $appendix) {
            if (!Parts::wantsAppendix($appendix, $parts)) {
                continue;
            }

            // An appendix gets the same selection. Its own parts, if it
            // declares any, are resolved against it by Registry::build().
            // hasBody() rather than isEmpty(): an appendix is its blocks, so
            // one that produced only a masthead would add a heading with
            // nothing under it.
            $extra = Registry::build($appendix, $item, $parts);
            if ($extra !== null && $extra->hasBody()) {
                $doc->append($extra);
            }
        }

        $note = trim(Settings::get('footer_note'));
        if ($note !== '' && $doc->footnote === '') {
            $doc->footnote = $note;
        }

        return $doc;
    }

    /**
     * Load an item, refusing anything the caller may not read.
     *
     * @return CommonDBTM|null
     */
    public static function load(string $itemtype, int $items_id): ?CommonDBTM
    {
        if ($items_id <= 0 || !class_exists($itemtype) || !is_a($itemtype, CommonDBTM::class, true)) {
            return null;
        }

        if (!Registry::exportable($itemtype)) {
            return null;
        }

        /** @var CommonDBTM $item */
        $item = new $itemtype();

        // getFromDB first, canViewItem second: the second reads the row the
        // first loaded, and asking in the other order would test the rights of
        // an empty object.
        if (!$item->getFromDB($items_id) || !$item->canViewItem()) {
            return null;
        }

        return $item;
    }

    /** Render one item, or null if it cannot be exported or read. */
    public static function one(string $itemtype, int $items_id, string $key = '', ?array $parts = null): ?array
    {
        $item = self::load($itemtype, $items_id);
        if ($item === null) {
            return null;
        }

        $doc = self::document($item, $key, $parts);
        if ($doc === null) {
            return null;
        }

        return ['filename' => $doc->filename(), 'bytes' => Engine::render($doc)];
    }

    /**
     * Render many items into one file.
     *
     * One file rather than a zip of many, because the thing somebody asked for
     * when they selected twelve tickets and pressed export is a document about
     * twelve tickets — a folder of twelve PDFs is the same work done by hand
     * afterwards. Each item starts on a fresh page.
     *
     * Items the caller cannot read are skipped silently rather than refused:
     * a search can legitimately return rows across entities, and failing the
     * whole export because one of fifty was out of scope helps nobody. The
     * count that comes back is what actually went in.
     *
     * @param int[] $ids
     * @return array{filename:string,bytes:string,rendered:int,skipped:int}|null
     */
    public static function many(string $itemtype, array $ids, string $key = '', ?array $parts = null): ?array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return null;
        }

        $limit   = Settings::bulkLimit();
        $dropped = max(0, count($ids) - $limit);
        $ids     = array_slice($ids, 0, $limit);

        $docs    = [];
        $skipped = 0;

        foreach ($ids as $items_id) {
            $item = self::load($itemtype, $items_id);
            $doc  = $item !== null ? self::document($item, $key, $parts) : null;

            if ($doc === null) {
                $skipped++;
                continue;
            }

            $docs[] = $doc;
        }

        if ($docs === []) {
            return null;
        }

        // A selection of one is that one document, not a collection with a
        // single member: the reader asked for a ticket and should get the
        // ticket's own title on it.
        if (count($docs) === 1 && $dropped === 0 && $skipped === 0) {
            $only = $docs[0];

            return [
                'filename' => $only->filename(),
                'bytes'    => Engine::render($only),
                'rendered' => 1,
                'skipped'  => 0,
            ];
        }

        /** @var class-string<CommonDBTM> $itemtype */
        $combined = Doc::make($itemtype::getTypeName(count($docs)))
            ->subtitle(sprintf(_n('%d item', '%d items', count($docs), 'glpipdf'), count($docs)));

        foreach ($docs as $index => $doc) {
            // Every item opens on its own page and re-states who it is —
            // including the first. Folding the first one into the cover would
            // save a page and lose the one line that says which ticket the
            // reader is looking at.
            if ($index > 0) {
                $combined->pageBreak();
            }

            $combined->section(trim($doc->reference . ' — ' . $doc->title, " —\t"));

            if ($doc->subtitle !== '') {
                $combined->muted($doc->subtitle);
            }
            if ($doc->meta !== []) {
                $combined->kv($doc->meta);
            }

            $combined->append($doc, false);
        }

        // Said on the document rather than in a flash message the reader will
        // not still have when they open the file. A silently truncated export
        // reads as a complete one.
        $left_out = $dropped + $skipped;
        if ($left_out > 0) {
            $combined->footnote(sprintf(
                _n(
                    '%d selected item is not in this document: it was beyond the export limit, '
                        . 'or you are not entitled to read it.',
                    '%d selected items are not in this document: they were beyond the export '
                        . 'limit, or you are not entitled to read them.',
                    $left_out,
                    'glpipdf'
                ),
                $left_out
            ));
        }

        return [
            'filename' => $combined->filename(),
            'bytes'    => Engine::render($combined),
            'rendered' => count($docs),
            'skipped'  => $left_out,
        ];
    }

    /**
     * Send the file.
     *
     * `Content-Length` is set because without it a browser cannot show progress
     * on a slow connection, and `X-Content-Type-Options` because a PDF served
     * without it is a file some browsers will happily re-interpret.
     */
    public static function stream(string $filename, string $bytes): never
    {
        // Anything already buffered — a stray notice, a BOM from an edited file
        // — corrupts the file rather than appearing anywhere a person can see
        // it. This is the difference between a readable PDF and one that opens
        // to "damaged file" with no clue why.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . self::headerSafe($filename) . '"');
        header('Content-Length: ' . strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');

        echo $bytes;
        exit;
    }

    /**
     * A filename that cannot break out of the header.
     *
     * Doc::filename() already reduces to ASCII-safe characters, so this is the
     * second line rather than the first — but a header value is exactly the
     * wrong place to find out that an assumption changed.
     */
    private static function headerSafe(string $filename): string
    {
        $filename = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename);

        return $filename === '' ? 'document.pdf' : $filename;
    }

    /** Central interface only: an export is technician output, like the rest. */
    public static function permitted(): bool
    {
        return (int) Session::getLoginUserID() > 0
            && (Session::getCurrentInterface() === 'central');
    }
}
