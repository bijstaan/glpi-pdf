<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpipdf;

use CommonDBTM;

/**
 * Who can produce a document, and for what.
 *
 * A plugin says so by registering a callback under `glpipdf_documents` — the
 * same shape glpi-sop already uses to offer its procedures to glpi-ai, so the
 * pattern is one an author of these plugins has met before:
 *
 * ```php
 * $PLUGIN_HOOKS['glpipdf_documents']['glpisop'] = [SopPdf::class, 'offers'];
 * ```
 *
 * and that callback returns a flat list of offers:
 *
 * ```php
 * [
 *     [
 *         'key'      => 'glpisop.procedure',
 *         'itemtype' => Sop::class,
 *         'label'    => __('Procedure'),
 *         'build'    => static fn(CommonDBTM $item): ?Doc => …,
 *     ],
 * ]
 * ```
 *
 * ### Two kinds of offer, and why the second one exists
 *
 * A `document` offer says "I can render this whole itemtype". An `appendix`
 * offer says "when *anybody* renders this itemtype, I have something to add" —
 * and that is the offer that makes the suite hang together rather than merely
 * coexist.
 *
 * The case that forced it: a ticket carrying an SOP run. Without appendices the
 * technician gets two files, one of which is a checklist with no context and
 * the other a ticket that does not mention the procedure it was worked under —
 * and the person who needs both is the auditor, who now has to staple them
 * together and be trusted to have picked the right pair. With appendices there
 * is one file, and the checklist is a section of it.
 *
 * ### Failure is per-offer
 *
 * A contributor that throws is dropped and logged; the rest of the document is
 * produced. An export is something a person pressed a button for, and losing
 * the whole ticket because one plugin's appendix hit an unexpected null is a
 * bad trade against losing that appendix.
 */
final class Registry
{
    /** @var array<int,array<string,mixed>>|null */
    private static ?array $cache = null;

    /**
     * Everything every plugin has offered, validated.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function offers(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        /** @var array $PLUGIN_HOOKS */
        global $PLUGIN_HOOKS;

        $out = [];

        foreach ((array) ($PLUGIN_HOOKS['glpipdf_documents'] ?? []) as $plugin => $callback) {
            if (!is_callable($callback)) {
                continue;
            }

            try {
                $offered = (array) $callback();
            } catch (\Throwable $e) {
                trigger_error(
                    sprintf('glpipdf: %s could not list its documents: %s', $plugin, $e->getMessage()),
                    E_USER_WARNING
                );
                continue;
            }

            foreach ($offered as $offer) {
                $offer = self::normalise((array) $offer, (string) $plugin);
                if ($offer !== null) {
                    $out[] = $offer;
                }
            }
        }

        return self::$cache = $out;
    }

    /**
     * @param array<string,mixed> $offer
     * @return array<string,mixed>|null
     */
    private static function normalise(array $offer, string $plugin): ?array
    {
        $itemtype = (string) ($offer['itemtype'] ?? '');
        $key      = (string) ($offer['key'] ?? '');

        // An offer for a class that is not loaded is not an error — it is a
        // plugin naming an itemtype from a plugin that is not installed here,
        // which is the normal state of a suite where each part ships alone.
        if ($key === '' || $itemtype === '' || !class_exists($itemtype)) {
            return null;
        }

        if (!isset($offer['build']) || !is_callable($offer['build'])) {
            return null;
        }

        return [
            'key'      => $key,
            'plugin'   => $plugin,
            'itemtype' => $itemtype,
            'label'    => (string) ($offer['label'] ?? $key),
            'kind'     => (string) ($offer['kind'] ?? 'document') === 'appendix' ? 'appendix' : 'document',
            'build'    => $offer['build'],
            // The named sections a reader can tick. Empty means the document
            // is all-or-nothing, which is what an appendix usually is.
            'parts'    => (array) ($offer['parts'] ?? []),
            // Optional, and cheap by contract: asked once per offer every time
            // an item's PDF tab is rendered, to decide whether to offer the
            // document at all. See self::available().
            'available' => isset($offer['available']) && is_callable($offer['available'])
                ? $offer['available']
                : null,
            // Ordering only matters among appendices, where it decides the
            // order the sections land in. Documents are chosen one at a time.
            'weight'   => (int) ($offer['weight'] ?? 100),
        ];
    }

    /**
     * The whole-document offers for an itemtype.
     *
     * Given an actual item, offers that say they have nothing for *that* item
     * are left out. This is what stops "Post-incident review" appearing on the
     * PDF tab of an incident nobody has reviewed yet — it would be offered,
     * chosen, and answered with an error, which teaches people that half the
     * controls in the suite do not work.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function documentsFor(string $itemtype, ?CommonDBTM $item = null): array
    {
        $out = [];
        foreach (self::offers() as $offer) {
            if ($offer['kind'] !== 'document' || $offer['itemtype'] !== $itemtype) {
                continue;
            }
            if ($item !== null && !self::available($offer, $item)) {
                continue;
            }
            $out[] = $offer;
        }

        usort($out, static fn(array $a, array $b): int => $a['weight'] <=> $b['weight']);

        return $out;
    }

    /**
     * Does this offer have anything for this item?
     *
     * Contained like {@see self::build()} is, and errs the same way: an offer
     * whose availability check throws is *offered*. Hiding a document because a
     * plugin's check hit an unexpected null would make the export quietly
     * disappear, and one that errors is easier to diagnose than one that was
     * never drawn.
     */
    public static function available(array $offer, CommonDBTM $item): bool
    {
        if ($offer['available'] === null) {
            return true;
        }

        try {
            return (bool) ($offer['available'])($item);
        } catch (\Throwable $e) {
            trigger_error(
                sprintf(
                    'glpipdf: %s could not say whether %s applies to %s %d: %s',
                    $offer['plugin'],
                    $offer['key'],
                    $item::getType(),
                    (int) $item->getID(),
                    $e->getMessage()
                ),
                E_USER_WARNING
            );

            return true;
        }
    }

    /**
     * The appendix offers for an itemtype, in the order they should be added.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function appendicesFor(string $itemtype): array
    {
        if (!Settings::flag('appendices')) {
            return [];
        }

        $out = [];
        foreach (self::offers() as $offer) {
            if ($offer['kind'] === 'appendix' && $offer['itemtype'] === $itemtype) {
                $out[] = $offer;
            }
        }

        usort($out, static fn(array $a, array $b): int => $a['weight'] <=> $b['weight']);

        return $out;
    }

    /** Can anything at all be exported for this itemtype? */
    public static function exportable(string $itemtype): bool
    {
        return self::documentsFor($itemtype) !== [];
    }

    /**
     * Every itemtype something can be exported for.
     *
     * @return array<string,string> itemtype => its own type name
     */
    public static function itemtypes(): array
    {
        $out = [];
        foreach (self::offers() as $offer) {
            if ($offer['kind'] !== 'document') {
                continue;
            }
            /** @var class-string<CommonDBTM> $itemtype */
            $itemtype       = $offer['itemtype'];
            $out[$itemtype] = $itemtype::getTypeName(1);
        }

        asort($out);

        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function offer(string $key): ?array
    {
        foreach (self::offers() as $offer) {
            if ($offer['key'] === $key) {
                return $offer;
            }
        }

        return null;
    }

    /**
     * Run one offer's builder.
     *
     * Contained on purpose — see the class comment. The warning goes to the
     * error log rather than to the reader, because a technician exporting a
     * ticket cannot act on "glpichange's appendix threw a TypeError" and the
     * document is still worth having.
     */
    public static function build(array $offer, CommonDBTM $item, ?array $requested = null): ?Doc
    {
        try {
            // Passed positionally rather than as an option bag, and safe for a
            // contributor that declares one parameter: PHP ignores surplus
            // arguments to a user-defined function, so an offer with no parts
            // needs no change to keep working.
            $doc = ($offer['build'])($item, Parts::resolve($offer, $requested));
        } catch (\Throwable $e) {
            trigger_error(
                sprintf(
                    'glpipdf: %s could not build %s for %s %d: %s',
                    $offer['plugin'],
                    $offer['key'],
                    $item::getType(),
                    (int) $item->getID(),
                    $e->getMessage()
                ),
                E_USER_WARNING
            );

            return null;
        }

        return $doc instanceof Doc && !$doc->isEmpty() ? $doc : null;
    }

    /** Tests and long-running processes; the hook array does not change mid-request. */
    public static function forget(): void
    {
        self::$cache = null;
    }
}
