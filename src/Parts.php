<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpipdf;

use CommonDBTM;

/**
 * Which sections of a document the reader asked for.
 *
 * An audit-ready export and a readable one are not the same document. Everything
 * a ticket carries — the field history, every cost line, every linked asset,
 * the survey — is what somebody producing evidence needs and what somebody
 * emailing an entity a summary does not. Rather than choosing for them, a
 * document is made of named *parts* and the reader ticks the ones they want.
 *
 * A part is declared by the contributor that knows what it means, alongside the
 * offer it belongs to:
 *
 * ```php
 * 'parts' => [
 *     ['key' => 'history', 'label' => __('Field history'), 'default' => false],
 *     …
 * ]
 * ```
 *
 * and the builder is handed the resolved list.
 *
 * ### Defaults are per part, and "off" is a real choice
 *
 * The field history of a two-year-old ticket is hundreds of rows. Including it
 * by default would make the ordinary export — somebody wanting the record —
 * forty pages, and the people who need it are the minority who came for an
 * audit. So it ships off, and the tab makes it one tick away.
 *
 * ### An unknown part is kept, not dropped
 *
 * A selection is a list of keys that travelled through a form. A key this build
 * does not recognise is ignored, but a *recognised* key missing from the
 * request is only "unticked" if the request actually came from the form — which
 * is why {@see self::resolve()} distinguishes "no selection was made" (use the
 * defaults) from "a selection was made and this was not in it".
 */
final class Parts
{
    /**
     * The parts an offer declares, normalised.
     *
     * @return array<int,array{key:string,label:string,default:bool}>
     */
    public static function of(array $offer): array
    {
        $out = [];

        foreach ((array) ($offer['parts'] ?? []) as $part) {
            $part = (array) $part;
            $key  = trim((string) ($part['key'] ?? ''));

            if ($key === '') {
                continue;
            }

            $out[] = [
                'key'     => $key,
                'label'   => (string) ($part['label'] ?? $key),
                'default' => (bool) ($part['default'] ?? true),
            ];
        }

        return $out;
    }

    /** @return string[] the keys an offer turns on unless told otherwise */
    public static function defaults(array $offer): array
    {
        $out = [];
        foreach (self::of($offer) as $part) {
            if ($part['default']) {
                $out[] = $part['key'];
            }
        }

        return $out;
    }

    /**
     * What to build, given what the request asked for.
     *
     * `$requested` is null when nothing was asked — a plain export link, a bulk
     * action, an API caller — and that means the defaults. An *empty array* is
     * different and is honoured: it is a reader who unticked everything, and
     * answering that with the defaults would be the form ignoring them.
     *
     * @param string[]|null $requested
     * @return string[]
     */
    public static function resolve(array $offer, ?array $requested): array
    {
        $known = array_column(self::of($offer), 'key');

        if ($known === []) {
            return [];
        }

        if ($requested === null) {
            return self::defaults($offer);
        }

        return array_values(array_intersect($known, array_map('strval', $requested)));
    }

    /**
     * Everything selectable for an itemtype: one document's parts, plus each
     * appendix as a part of its own.
     *
     * Appendices are presented the same way deliberately. To a reader ticking
     * boxes, "the SOP checklist" and "the correspondence" are two things that
     * may or may not be in the file; which plugin produces them is not a
     * distinction they should have to hold.
     *
     * @return array<int,array{key:string,label:string,default:bool,group:string}>
     */
    public static function selectable(array $offer, string $itemtype, ?CommonDBTM $item = null): array
    {
        $out = [];

        foreach (self::of($offer) as $part) {
            $out[] = $part + ['group' => (string) $offer['label']];
        }

        foreach (Registry::appendicesFor($itemtype) as $appendix) {
            if ($item !== null && !Registry::available($appendix, $item)) {
                continue;
            }

            $out[] = [
                'key'     => self::APPENDIX_PREFIX . $appendix['key'],
                'label'   => (string) $appendix['label'],
                'default' => true,
                'group'   => __('From other plugins', 'glpipdf'),
            ];
        }

        return $out;
    }

    /**
     * Namespaced so an appendix can never collide with a document's own part
     * key — two plugins both calling something `summary` is not a bug either of
     * them could have avoided.
     */
    public const APPENDIX_PREFIX = 'appendix:';

    /** Is this appendix wanted? Null selection means every one of them. */
    public static function wantsAppendix(array $appendix, ?array $requested): bool
    {
        return $requested === null
            || in_array(self::APPENDIX_PREFIX . $appendix['key'], $requested, true);
    }
}
