<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpipdf;

use CommonDBTM;
use CommonGLPI;
use Html;
use Session;

/**
 * The "PDF" tab: choose what goes in the file, then take it.
 *
 * The only way to export a single item, and deliberately so. There was briefly
 * a one-click link on the item form as well; it was removed because it answered
 * a question this tab already answers, and because a second entry point that
 * silently gives the *default* selection is a way to hand somebody a shorter
 * document than they thought they asked for. The person producing evidence
 * needs the field history and the costs, and should not have to discover
 * afterwards that the export they handed over was missing half the record.
 *
 * ### Registered from POST_INIT, not from plugin_init
 *
 * `addtabon` needs a list of itemtypes at registration time, and the itemtypes
 * this tab belongs on are whatever plugins have contributed documents for —
 * which is not knowable during our own init, because GLPI initialises plugins
 * one at a time and half of them run after this one. Registering from
 * `Hooks::POST_INIT` is the fix: it fires once every plugin's hooks are in
 * place, and tab resolution happens later still, at render.
 *
 * ### One form, and it is not nested
 *
 * A tab's content is loaded into `#tabcontent`, which is outside the item's own
 * `<form>` — so this can be a real form with real controls, where anything
 * rendered *into* the item form could not be one without nesting. It is a GET,
 * because the response is a file: a POST that streams a download leaves the
 * browser on a page it cannot go back from.
 */
class PdfTab extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('PDF', 'glpipdf');
    }

    public static function getIcon()
    {
        return 'ti ti-file-type-pdf';
    }

    /**
     * Put the tab on everything anybody can render.
     *
     * Driven off the registry, so a plugin installed next month brings its own
     * tab with it and nothing here has to be edited.
     */
    public static function register(): void
    {
        foreach (array_keys(Registry::itemtypes()) as $itemtype) {
            \Plugin::registerClass(self::class, ['addtabon' => [$itemtype]]);
        }
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (
            !($item instanceof CommonDBTM)
            || $item->isNewItem()
            || !Export::permitted()
            || !$item->canViewItem()
            || Registry::documentsFor($item::getType(), $item) === []
        ) {
            return '';
        }

        return self::createTabEntry(self::getTypeName(), 0, $item::getType(), self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof CommonDBTM) || $item->isNewItem() || !Export::permitted()) {
            return false;
        }

        $offers = Registry::documentsFor($item::getType(), $item);
        if ($offers === []) {
            return false;
        }

        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        echo "<div class='p-3 glpipdf-tab'>";

        echo '<p class="text-muted">'
           . __s('Everything ticked below goes into one file. What is not ticked is left out '
               . 'entirely — not emptied, not marked absent — so an export is exactly as long as '
               . 'what it says.', 'glpipdf')
           . '</p>';

        foreach ($offers as $index => $offer) {
            self::renderOffer($item, $offer, $index === 0, $e);
        }

        echo '</div>';

        return true;
    }

    /**
     * One document, with its sections as checkboxes.
     *
     * Each offer gets its own form. An SOP's procedure and a filled-in run are
     * different documents with different sections, and one form spanning both
     * would submit a selection half of which belongs to the other.
     *
     * @param array<string,mixed> $offer
     */
    private static function renderOffer(CommonDBTM $item, array $offer, bool $open, callable $e): void
    {
        $parts = Parts::selectable($offer, $item::getType(), $item);

        echo "<details class='card mb-3'" . ($open ? ' open' : '') . '>';
        echo "<summary class='card-header'><h3 class='card-title d-inline'>"
           . $e($offer['label']) . '</h3></summary>';
        echo "<div class='card-body'>";

        echo "<form method='get' action='" . $e(Url::to('front/export.php')) . "'>";
        echo Html::hidden('itemtype', ['value' => $item::getType()]);
        echo Html::hidden('items_id', ['value' => (int) $item->getID()]);
        echo Html::hidden('key', ['value' => (string) $offer['key']]);

        // Says a selection was made at all. Without it an export with every box
        // unticked is indistinguishable from a plain link, and would silently
        // come back with the defaults — the form ignoring the reader.
        echo Html::hidden('chose', ['value' => '1']);

        if ($parts === []) {
            echo "<p class='text-muted'>"
               . __s('This document has no options — it is produced whole.', 'glpipdf')
               . '</p>';
        } else {
            $group = null;

            echo "<div class='glpipdf-parts'>";

            foreach ($parts as $part) {
                if ($part['group'] !== $group) {
                    $group = $part['group'];
                    echo "<div class='glpipdf-parts-group'>" . $e($group) . '</div>';
                }

                echo "<label class='form-check'>";
                echo "<input type='checkbox' class='form-check-input' name='parts[]' value='"
                   . $e($part['key']) . "'" . ($part['default'] ? " checked='checked'" : '') . '>';
                echo "<span class='form-check-label'>" . $e($part['label']) . '</span>';
                echo '</label>';
            }

            echo '</div>';

            echo "<div class='glpipdf-parts-actions'>";
            echo "<button type='button' class='btn btn-sm btn-ghost-secondary' data-glpipdf-all>"
               . __s('Select all', 'glpipdf') . '</button>';
            echo "<button type='button' class='btn btn-sm btn-ghost-secondary' data-glpipdf-none>"
               . __s('Select none', 'glpipdf') . '</button>';
            echo "<button type='button' class='btn btn-sm btn-ghost-secondary' data-glpipdf-reset>"
               . __s('Back to the usual set', 'glpipdf') . '</button>';
            echo '</div>';
        }

        echo "<div class='mt-3'>";
        echo "<button type='submit' class='btn btn-primary'>"
           . "<i class='ti ti-download me-1'></i>" . __s('Export to PDF', 'glpipdf') . '</button>';
        echo '</div>';

        echo '</form>';
        echo '</div></details>';
    }
}
