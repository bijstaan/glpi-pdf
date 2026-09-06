<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

use GlpiPlugin\Glpipdf\Bulk;
use GlpiPlugin\Glpipdf\Settings;

/**
 * Install: settings, and nothing else.
 *
 * This plugin owns no data. It has no tables, no itemtypes and no rights of its
 * own — an export is a view of a record, so the permission to make one is the
 * permission to read that record, and inventing a right here would create the
 * possibility of an instance where somebody can read a ticket on screen but not
 * on paper (or, worse, the reverse). See GlpiPlugin\Glpipdf\Export.
 *
 * That makes uninstall genuinely complete: the config rows go, and there is
 * nothing left behind because there was nothing else.
 */
function plugin_glpipdf_install()
{
    Config::setConfigurationValues(PLUGIN_GLPIPDF_CONFIG_CONTEXT, Settings::DEFAULTS);

    return true;
}

function plugin_glpipdf_uninstall()
{
    Config::deleteConfigurationValues(
        PLUGIN_GLPIPDF_CONFIG_CONTEXT,
        array_keys(Settings::DEFAULTS)
    );

    // The rasterised logo cache. Derived data with a single source — see
    // Branding — so removing it costs nothing and leaving it behind would be
    // an orphaned directory under GLPI's plugin files that nobody could
    // explain a year later.
    $cache = GLPI_PLUGIN_DOC_DIR . '/glpipdf';
    foreach ((array) @glob($cache . '/*') as $file) {
        @unlink((string) $file);
    }
    @rmdir($cache);

    return true;
}

// ----------------------------------------------------------- bulk export

/**
 * Offer the bulk action on any itemtype something can render.
 *
 * Called by MassiveAction::getAllMassiveActions() through
 * Hooks::AUTO_MASSIVE_ACTIONS, which is why the function name is fixed.
 */
function plugin_glpipdf_MassiveActions($itemtype)
{
    return Bulk::actionsFor((string) $itemtype);
}
