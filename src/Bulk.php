<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpipdf;

use CommonDBTM;
use Html;
use MassiveAction;
use Session;

/**
 * Exporting a selection from a search.
 *
 * A massive action cannot hand back a file. It runs over ajax and answers with
 * counts — how many succeeded, how many the caller had no right to — which is
 * the right shape for "delete these forty" and the wrong shape for "give me a
 * document". So this follows the pattern core itself uses for transfers: the
 * action records what was selected and asks for a redirect, and the page it
 * redirects to is the one that produces the file.
 *
 * The selection goes in the session rather than the query string, and that is
 * not tidiness. Fifty ids is a URL long enough to be truncated by something in
 * the middle, and a URL carrying a list of ids is one that gets pasted into a
 * ticket and re-fetched by somebody with different rights. In the session it is
 * scoped to the person who made the selection and gone when they log out.
 */
final class Bulk
{
    /** Where the selection waits between the action and the redirect. */
    private const SESSION_KEY = 'glpipdf_bulk';

    public const ACTION = 'export';

    /**
     * Offered for every itemtype somebody has contributed a document for.
     *
     * Driven off the registry rather than a list kept here, so a plugin
     * installed next month brings its own bulk export with it and nothing in
     * this file has to be edited.
     *
     * @return array<string,string>
     */
    public static function actionsFor(string $itemtype): array
    {
        if (!Registry::exportable($itemtype) || Session::getCurrentInterface() !== 'central') {
            return [];
        }

        return [
            self::class . MassiveAction::CLASS_ACTION_SEPARATOR . self::ACTION
                => "<i class='ti ti-file-type-pdf'></i>" . __('Export to PDF', 'glpipdf'),
        ];
    }

    /**
     * The sub-form: which document, when an itemtype offers more than one.
     *
     * The method names here are fixed by GLPI: MassiveAction resolves the class
     * in front of the `:` in the action key and calls these two on it
     * statically. See MassiveAction::showSubForm() and
     * MassiveAction::processForSeveralItemtypes().
     *
     * An SOP is the case — its procedure and a filled-in run are two documents
     * of the same record — and picking between them after selecting fifty of
     * them is the only moment it can be asked.
     */
    public static function showMassiveActionsSubForm(MassiveAction $ma): bool
    {
        if ($ma->getAction() !== self::ACTION) {
            return false;
        }

        $itemtype = self::itemtypeOf($ma);
        $offers   = $itemtype !== '' ? Registry::documentsFor($itemtype) : [];

        if (count($offers) > 1) {
            $choices = [];
            foreach ($offers as $offer) {
                $choices[$offer['key']] = $offer['label'];
            }

            echo '<div class="mb-2">';
            \Dropdown::showFromArray('glpipdf_key', $choices, ['value' => $offers[0]['key']]);
            echo '</div>';
        }

        echo Html::submit(_x('button', 'Export'), [
            'name'  => 'massiveaction',
            'icon'  => 'ti ti-file-type-pdf',
            'class' => 'btn btn-sm btn-primary',
        ]);

        return true;
    }

    /**
     * Record the selection and ask to be sent to the page that renders it.
     *
     * Every id is marked OK here even though nothing has been rendered yet.
     * That is honest rather than optimistic: what this action does *is* record
     * the selection, and it did. Whether a given item can actually be read is
     * decided on the export page against the live record, which is the only
     * place that check means anything — see {@see Export::load()}.
     */
    public static function processMassiveActionsForOneItemtype(
        MassiveAction $ma,
        CommonDBTM $item,
        array $ids
    ): void
    {
        if ($ma->getAction() !== self::ACTION) {
            return;
        }

        $input = $ma->getInput();

        $_SESSION[self::SESSION_KEY] = [
            'itemtype' => $item::getType(),
            'ids'      => array_values(array_map('intval', $ids)),
            'key'      => (string) ($input['glpipdf_key'] ?? ''),
        ];

        foreach ($ids as $id) {
            $ma->itemDone($item::getType(), $id, MassiveAction::ACTION_OK);
        }

        $ma->setRedirect(Url::to('front/export.php?bulk=1'));
    }

    /**
     * The waiting selection, consumed.
     *
     * Read-once: a selection that survived being downloaded would re-download
     * on the next refresh of a page that happened to hit the same URL, and a
     * stale one would silently export last week's search.
     *
     * @return array{itemtype:string,ids:int[],key:string}|null
     */
    public static function take(): ?array
    {
        $stored = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);

        if (!is_array($stored) || ($stored['itemtype'] ?? '') === '' || ($stored['ids'] ?? []) === []) {
            return null;
        }

        return [
            'itemtype' => (string) $stored['itemtype'],
            'ids'      => array_values(array_map('intval', (array) $stored['ids'])),
            'key'      => (string) ($stored['key'] ?? ''),
        ];
    }

    /**
     * The itemtype the sub-form is being shown for.
     *
     * MassiveAction keeps the selection keyed by itemtype and a selection can
     * legitimately span several; the sub-form has one set of controls, so the
     * first is what it is drawn for. A mixed selection is rare and still works
     * — the process step runs once per itemtype either way.
     */
    private static function itemtypeOf(MassiveAction $ma): string
    {
        $items = $ma->getItems();

        return is_array($items) && $items !== [] ? (string) array_key_first($items) : '';
    }
}
