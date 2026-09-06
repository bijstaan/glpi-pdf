<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpipdf;

use Config;

/**
 * Plugin settings, with defaults.
 *
 * Deliberately short. The reason this plugin exists is that six other plugins
 * should produce one house style rather than six, and every knob added here is
 * a way for that to stop being true. What is configurable is what genuinely
 * differs between sites — the accent, whether the logo is used at all, what the
 * footer says — and not what differs between documents.
 */
final class Settings
{
    public const DEFAULTS = [
        // The rule under the masthead and the meter bars. Not read from
        // glpi-whitelabel: that plugin has no accent setting, it themes GLPI's
        // chrome through a palette, and a palette that works as an application
        // sidebar is not a rule colour on white A4.
        'accent'        => '#1c6fbb',

        // Put the whitelabel logo on the masthead. Off is a legitimate choice:
        // a wordmark alone prints better than a logo that was drawn for a dark
        // sidebar and disappears on paper.
        'include_logo'  => 1,

        // Appended to the footer of every document. Somewhere to put
        // "Commercial in confidence" or a document-control reference.
        'footer_note'   => '',

        // Whether an export may include another plugin's appendix — the SOP
        // checklist inside a ticket's document, and so on. See Registry.
        'appendices'    => 1,

        // How many items one bulk export will render before it refuses.
        // A cap rather than a queue: the export runs in the request, and the
        // honest failure is "that is too many, narrow the search".
        'bulk_limit'    => 50,
    ];

    /** @return array<string,int|string> */
    public static function all(): array
    {
        $stored = Config::getConfigurationValues(
            PLUGIN_GLPIPDF_CONFIG_CONTEXT,
            array_keys(self::DEFAULTS)
        );

        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $out[$key] = array_key_exists($key, $stored) ? $stored[$key] : $default;
        }

        return $out;
    }

    public static function get(string $key): string
    {
        return (string) (self::all()[$key] ?? (self::DEFAULTS[$key] ?? ''));
    }

    public static function flag(string $key): bool
    {
        return (int) (self::all()[$key] ?? 0) === 1;
    }

    /**
     * The accent, validated.
     *
     * An unparseable colour would otherwise reach TCPDF and be drawn as black,
     * which looks like a deliberate choice rather than a typo in a settings
     * field somebody edited months ago.
     */
    public static function accent(): string
    {
        $accent = trim(self::get('accent'));

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $accent) === 1
            ? $accent
            : (string) self::DEFAULTS['accent'];
    }

    public static function bulkLimit(): int
    {
        $limit = (int) self::get('bulk_limit');

        return $limit > 0 ? min($limit, 500) : (int) self::DEFAULTS['bulk_limit'];
    }

    public static function save(array $input): void
    {
        $values = [];

        foreach (array_keys(self::DEFAULTS) as $key) {
            $values[$key] = match ($key) {
                'include_logo', 'appendices' => !empty($input[$key]) ? 1 : 0,
                'bulk_limit'                 => max(1, min(500, (int) ($input[$key] ?? 50))),
                'accent'                     => preg_match('/^#[0-9A-Fa-f]{6}$/', trim((string) ($input[$key] ?? ''))) === 1
                    ? trim((string) $input[$key])
                    : (string) self::DEFAULTS['accent'],
                default                      => trim((string) ($input[$key] ?? '')),
            };
        }

        Config::setConfigurationValues(PLUGIN_GLPIPDF_CONFIG_CONTEXT, $values);
    }
}
