<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Load every class of a plugin against a booted GLPI kernel.
 *
 * The gap this closes: `php -l` parses a file, it does not link it. An
 * inheritance-signature mismatch with a GLPI or vendor base class — a method
 * declared private that the parent declares public, a changed return type — is
 * a *compile* error, raised when the class is first loaded and not before. It
 * passes lint, passes CI, and takes out a live page.
 *
 * This plugin found one on its first run: `Engine::writeHtml()` was private,
 * and TCPDF already has `writeHTML()` — PHP method names being case-insensitive,
 * those are the same method. The export answered 500 with "Access level to
 * GlpiPlugin\Glpipdf\Engine::writeHtml() must be public".
 *
 * Run inside the GLPI container, from anywhere:
 *
 *     php plugins/glpipdf/tests/class-load.php glpipdf glpisop …
 *
 * With no arguments it sweeps this plugin alone.
 */

$root = '/var/www/glpi';

if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "This has to run inside the GLPI container.\n");
    exit(2);
}

chdir($root);
require $root . '/vendor/autoload.php';

(new Glpi\Kernel\Kernel('production'))->boot();

$directories = array_slice($argv, 1);
if ($directories === []) {
    $directories = ['glpipdf'];
}

/**
 * A plugin directory's PSR-4 namespace root.
 *
 * GLPI derives it from the directory name with the first letter upper-cased —
 * `glpisop` becomes `GlpiPlugin\Glpisop` — with the one exception that a
 * plugin may declare something else. Nothing in this suite does, apart from
 * whitelabel, whose directory and namespace already agree.
 */
$namespaceFor = static fn(string $directory): string => 'GlpiPlugin\\' . ucfirst($directory) . '\\';

$failures = 0;
$loaded   = 0;

foreach ($directories as $directory) {
    $src = sprintf('%s/plugins/%s/src', $root, $directory);

    if (!is_dir($src)) {
        printf("  skipped %s (no src/)\n", $directory);
        continue;
    }

    foreach ((array) glob($src . '/*.php') as $file) {
        $class = $namespaceFor($directory) . basename((string) $file, '.php');

        try {
            if (!class_exists($class)) {
                // Not every file is a class in that namespace — a trait, an
                // interface, a class the autoloader maps elsewhere. Reported
                // rather than failed, because the thing being tested is
                // whether loading *fatals*, not whether the name was guessed.
                printf("  ? %s\n", $class);
                continue;
            }
            $loaded++;
        } catch (\Throwable $e) {
            printf("FATAL %s\n      %s\n", $class, $e->getMessage());
            $failures++;
        }
    }
}

printf(
    "\n%d class(es) loaded, %d failure(s)\n",
    $loaded,
    $failures
);

exit($failures === 0 ? 0 : 1);
