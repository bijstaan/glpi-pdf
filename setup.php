<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */
/**
 * GLPI PDF — one branded PDF export for the whole suite.
 *
 * Every plugin in this suite eventually grows something a person needs on
 * paper: a procedure to sign, a change record for an auditor, a service review
 * for an entity. Left to themselves each one grows its own answer — a print
 * stylesheet here, an HTML archive there — and the estate ends up handing
 * entities six documents that look like six products.
 *
 * So this plugin owns the document, and the other plugins own their content. A
 * contributor describes what it has to say as data ({@see Doc}); the engine
 * decides what that looks like ({@see Engine}); and the name at the top comes
 * from glpi-whitelabel, never from GLPI ({@see Branding}).
 *
 * ### It adds no data of its own
 *
 * No tables, no itemtypes, no rights. An export is a view of a record, so the
 * permission to produce one is the permission to read that record — see
 * {@see Export}. Uninstalling takes the settings and nothing else, because
 * there is nothing else.
 *
 * ### TCPDF is already here
 *
 * GLPI 11 ships `tecnickcom/tcpdf` in its vendor tree and uses it for the
 * search engine's list export. This plugin uses the same copy, so it adds no
 * dependency — which matters, since there is deliberately no composer.json
 * anywhere in this monorepo.
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Glpipdf\Itil;
use GlpiPlugin\Glpipdf\PdfTab;

define('PLUGIN_GLPIPDF_VERSION', '0.1.0');
define('PLUGIN_GLPIPDF_MIN_GLPI', '11.0');

// Settings live under this config context.
define('PLUGIN_GLPIPDF_CONFIG_CONTEXT', 'plugin:glpipdf');

function plugin_init_glpipdf()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['glpipdf'] = true;

    $PLUGIN_HOOKS['config_page']['glpipdf'] = 'front/config.php';

    // The plugin's own contribution: Ticket, Change and Problem. Registered
    // through the same hook every other plugin uses, rather than being special
    // — if the built-in contributor cannot be expressed in the public contract
    // then the public contract is not good enough.
    $PLUGIN_HOOKS['glpipdf_documents']['glpipdf'] = [Itil::class, 'offers'];

    // The PDF tab, and the only way in for a single item. There was briefly an
    // export link on the item form as well; it was removed because it answers a
    // question the tab already answers, and because a second entry point that
    // silently gives the *default* selection is a way to hand somebody a
    // shorter document than they thought they asked for.
    //
    // Registered from POST_INIT rather than here: `addtabon` wants a list of
    // itemtypes, and the itemtypes this belongs on are whatever plugins have
    // contributed documents for — which is not knowable during our own init,
    // because GLPI initialises plugins one at a time and half of them run after
    // this one. POST_INIT fires once every plugin's hooks are in place.
    $PLUGIN_HOOKS[Hooks::POST_INIT]['glpipdf'] = [PdfTab::class, 'register'];

    // Tools for glpi-ai. Registered unconditionally, like every other
    // contributor in the suite: only glpi-ai reads this hook, so an instance
    // without it pays one array assignment and never loads the class.
    $PLUGIN_HOOKS['glpiai_tools']['glpipdf'] = [\GlpiPlugin\Glpipdf\AiTools::class, 'all'];

    $PLUGIN_HOOKS['add_css']['glpipdf']        = 'css/pdf.css';
    $PLUGIN_HOOKS['add_javascript']['glpipdf'] = 'js/pdf.js';

    // Bulk export from a search. Registered for every itemtype something has
    // offered a document for, so a plugin installed later brings its own
    // massive action with it and this list never has to be maintained.
    $PLUGIN_HOOKS['use_massive_action']['glpipdf'] = 1;
}

function plugin_version_glpipdf()
{
    return [
        'name'         => 'GLPI PDF',
        'version'      => PLUGIN_GLPIPDF_VERSION,
        'author'       => 'Bijstaan',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => 'https://github.com/bijstaan/glpi-pdf',
        'requirements' => ['glpi' => ['min' => PLUGIN_GLPIPDF_MIN_GLPI]],
    ];
}

function plugin_glpipdf_check_prerequisites()
{
    return true;
}

function plugin_glpipdf_check_config($verbose = false)
{
    return true;
}
