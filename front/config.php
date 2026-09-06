<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Settings for the PDF exporter.
 *
 * House style, as settled across the suite's config pages: one
 * `container-fluid` capped at 960px, cards as sections, one Save, status
 * first, and READ opens the page while UPDATE saves it — a profile with only
 * the former sees the values and no buttons rather than a form that answers
 * Save with access-denied.
 *
 * The right is core `config`. This plugin only decides how other plugins'
 * documents look; it owns no data and grants no access to any, so a right of
 * its own would be one more thing to grant for no additional authority.
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpipdf\Branding;
use GlpiPlugin\Glpipdf\Registry;
use GlpiPlugin\Glpipdf\Settings;
use GlpiPlugin\Glpipdf\Url;

Session::checkRight('config', READ);

$can_edit = Session::haveRight('config', UPDATE);

if (isset($_POST['update'])) {
    Session::checkRight('config', UPDATE);
    Settings::save($_POST);
    Session::addMessageAfterRedirect(__('Settings saved.', 'glpipdf'), true, INFO);
    Html::back();
}

Html::header(
    __('PDF export', 'glpipdf'),
    $_SERVER['PHP_SELF'],
    class_exists(\GlpiPlugin\Glpinav\Nav::class)
        ? \GlpiPlugin\Glpinav\Nav::sector('Config', 'config')
        : 'config',
    'Config'
);

$settings = Settings::all();
$brand    = Branding::resolve();
$e        = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

echo "<div class='container-fluid glpipdf-config' style='max-width:960px'>";

// ------------------------------------------------------------ status first

echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Branding', 'glpipdf') . '</h3></div>';
echo "<div class='card-body'>";

if ($brand->branded) {
    echo "<div class='alert alert-success mb-2'>";
    echo '<i class="ti ti-check me-1"></i>';
    echo htmlspecialchars(
        sprintf(
            __('Documents carry “%s”, from glpi-whitelabel.', 'glpipdf'),
            $brand->name !== '' ? $brand->name : __('your logo', 'glpipdf')
        ),
        ENT_QUOTES,
        'UTF-8'
    );
    echo '</div>';

    if ($brand->logo === '' && Settings::flag('include_logo')) {
        // The one case worth calling out: whitelabel has a logo but it could
        // not be turned into something TCPDF can place — an SVG on an instance
        // with no rasteriser, most often. The document is not broken, it just
        // has a wordmark instead of a mark, and an administrator who uploaded a
        // logo deserves to know it is not being used.
        echo "<div class='alert alert-warning mb-2'>"
           . __s('The configured logo could not be prepared for print — an SVG, or an image '
               . 'type this server cannot read. Documents use the name alone.', 'glpipdf')
           . '</div>';
    }
} else {
    echo "<div class='alert alert-info mb-2'>"
       . __s('glpi-whitelabel is not configured, so documents are produced unbranded — no name '
           . 'and no logo. They will never carry GLPI’s.', 'glpipdf')
       . '</div>';
}

echo "<p class='text-muted mb-0'>"
   . __s('The name and the logo come from glpi-whitelabel so that rebranding stays one page. '
       . 'Only the accent below belongs to this plugin: a palette that reads well as an '
       . 'application sidebar is not a rule colour on white A4.', 'glpipdf')
   . '</p>';

echo '</div></div>';

// --------------------------------------------------------------- the form

// No action attribute, deliberately. `$_SERVER['PHP_SELF']` is `/index.php`
// under GLPI 11's front controller, so posting to it hands the form to the
// router, which answers 400 — and the page comes back looking exactly as
// though it had saved. Omitting the attribute posts to the current URL, which
// is the only reliably-right target.
echo "<form method='post'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('How documents look', 'glpipdf') . '</h3></div>';
echo "<div class='card-body'>";

echo "<div class='row'>";

echo "<div class='col-md-4 mb-3'><label class='form-label'>" . __s('Accent colour', 'glpipdf') . '</label>';
echo "<input type='color' class='form-control form-control-color' name='accent' value='"
   . $e($settings['accent']) . "'" . ($can_edit ? '' : ' disabled') . '>';
echo "<div class='form-text'>" . __s('The rule under the title, and the bars.', 'glpipdf') . '</div>';
echo '</div>';

echo "<div class='col-md-8 mb-3'><label class='form-label'>" . __s('Footer note', 'glpipdf') . '</label>';
echo "<input type='text' class='form-control' name='footer_note' maxlength='255' value='"
   . $e($settings['footer_note']) . "'" . ($can_edit ? '' : ' disabled') . '>';
echo "<div class='form-text'>"
   . __s('Printed small at the end of every document. Somewhere for “Commercial in confidence” '
       . 'or a document-control reference. A document that sets its own has priority.', 'glpipdf')
   . '</div></div>';

echo '</div>';

echo "<label class='form-check'>";
echo "<input type='checkbox' class='form-check-input' name='include_logo' value='1' "
   . ((int) $settings['include_logo'] === 1 ? "checked='checked' " : '')
   . ($can_edit ? '' : 'disabled ') . '>';
echo "<span class='form-check-label'>" . __s('Use the logo', 'glpipdf') . ' — '
   . __s('unticking prints the name alone, which is the better choice for a mark drawn for a '
       . 'dark sidebar', 'glpipdf')
   . '</span></label>';

echo "<label class='form-check'>";
echo "<input type='checkbox' class='form-check-input' name='appendices' value='1' "
   . ((int) $settings['appendices'] === 1 ? "checked='checked' " : '')
   . ($can_edit ? '' : 'disabled ') . '>';
echo "<span class='form-check-label'>" . __s('Include appendices from other plugins', 'glpipdf')
   . ' — ' . __s('a ticket’s SOP checklist inside the ticket’s own document, rather than as a '
       . 'second file', 'glpipdf')
   . '</span></label>';

echo '</div></div>';

echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Bulk export', 'glpipdf') . '</h3></div>';
echo "<div class='card-body'>";

echo "<div class='mb-3' style='max-width:14rem'><label class='form-label'>"
   . __s('Most items in one export', 'glpipdf') . '</label>';
echo "<input type='number' min='1' max='500' class='form-control' name='bulk_limit' value='"
   . $e($settings['bulk_limit']) . "'" . ($can_edit ? '' : ' disabled') . '></div>';

echo "<p class='text-muted mb-0'>"
   . __s('A cap rather than a queue: the export runs inside the request, so the honest answer '
       . 'to a selection of four hundred tickets is that it is too many. Anything beyond the '
       . 'limit is left out and the document says how many.', 'glpipdf')
   . '</p>';

echo '</div></div>';

if ($can_edit) {
    echo "<div class='d-flex mb-4'>";
    echo "<button type='submit' name='update' value='1' class='btn btn-primary ms-auto'>"
       . __s('Save') . '</button>';
    echo '</div>';
} else {
    echo "<div class='alert alert-secondary'>"
       . __s('Read only: you can see these settings but not change them.', 'glpipdf')
       . '</div>';
}

echo '</form>';

// ------------------------------------------------------- what can be made

echo "<div class='card mb-4'><div class='card-header'><h3 class='card-title'>"
   . __s('What can be exported', 'glpipdf') . '</h3></div>';
echo "<div class='card-body'>";

$offers = Registry::offers();

if ($offers === []) {
    echo "<p class='text-muted mb-0'>"
       . __s('Nothing yet. Plugins register their own documents; installing one adds its rows '
           . 'here without anything being configured.', 'glpipdf')
       . '</p>';
} else {
    echo "<table class='table table-sm'><thead><tr>";
    echo '<th>' . __s('Object', 'glpipdf') . '</th>';
    echo '<th>' . __s('Document', 'glpipdf') . '</th>';
    echo '<th>' . __s('Kind', 'glpipdf') . '</th>';
    echo '<th>' . __s('From', 'glpipdf') . '</th>';
    echo '</tr></thead><tbody>';

    foreach ($offers as $offer) {
        /** @var class-string<CommonDBTM> $itemtype */
        $itemtype = $offer['itemtype'];

        echo '<tr>';
        echo '<td>' . $e($itemtype::getTypeName(1)) . '</td>';
        echo '<td>' . $e($offer['label']) . '</td>';
        echo '<td>' . ($offer['kind'] === 'appendix'
            ? "<span class='badge bg-azure-lt'>" . __s('appendix', 'glpipdf') . '</span>'
            : "<span class='badge bg-secondary-lt'>" . __s('document', 'glpipdf') . '</span>')
           . '</td>';
        echo "<td class='text-muted'>" . $e($offer['plugin']) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';

    echo "<p class='text-muted mb-0'>"
       . __s('An appendix is added to somebody else’s document rather than being one of its '
           . 'own — an SOP checklist inside the ticket it was run on.', 'glpipdf')
       . '</p>';
}

echo '</div></div>';

echo '</div>';

Html::footer();
