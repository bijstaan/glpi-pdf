<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpipdf;

/**
 * Absolute URLs into this plugin.
 *
 * `$CFG_GLPI['root_doc']` is empty when GLPI is served from the root of a
 * domain and `/glpi` when it is not, so a hard-coded `/plugins/glpipdf/…`
 * works on exactly one of the two deployments and nobody notices which until
 * a link 404s in production.
 */
final class Url
{
    public static function to(string $path): string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        return ($CFG_GLPI['root_doc'] ?? '') . '/plugins/glpipdf/' . ltrim($path, '/');
    }
}
