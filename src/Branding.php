<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpipdf;

/**
 * Whose name is on the file.
 *
 * A PDF is the one thing this suite produces that leaves the estate, so "does
 * this say GLPI on it" has an answer and the answer is never yes. An entity
 * has never heard of GLPI; a procedure or a service review arriving with a
 * stranger's product name at the top reads as somebody else's system that their
 * tickets happen to live in.
 *
 * Three states, and the middle one is the one worth designing for:
 *
 *  - **glpi-whitelabel configured** — its name and its logo, read from that
 *    plugin rather than copied here, so rebranding stays one page.
 *  - **absent or unconfigured** — neutral. No name, no logo, no product name at
 *    all. A plain document beats one wearing the wrong brand.
 *  - **half-configured** — whatever it has. A document that fell all the way
 *    back to neutral because only the login logo was missing would be
 *    surprising, and glpi-whitelabel's own Brand class takes the same position.
 *
 * Core's own {@see \GLPIPDF} is deliberately not used as the base class. Its
 * footer is the string "GLPI PDF export" and its creator metadata is "GLPI" —
 * both of which are exactly what must not appear, and overriding all three
 * inherited members leaves nothing of it behind but the constructor.
 */
final class Branding
{
    private function __construct(
        /** The name at the top. Never GLPI. */
        public readonly string $name,
        /** Absolute path to a raster logo TCPDF can place, or ''. */
        public readonly string $logo,
        public readonly string $accent,
        /** Whether any of this came from glpi-whitelabel. */
        public readonly bool $branded,
    ) {
    }

    /**
     * The masthead a document is rendered with.
     *
     * The accent is this plugin's own setting rather than glpi-whitelabel's,
     * because glpi-whitelabel has no such setting: it themes GLPI's chrome
     * through a palette, and a palette that reads well as an application
     * sidebar is not a rule colour on white A4.
     */
    public static function resolve(): self
    {
        $accent = Settings::accent();

        if (!self::available()) {
            return new self('', '', $accent, false);
        }

        /** @var class-string $settings */
        $settings = \GlpiPlugin\Whitelabel\Settings::class;

        $name = trim((string) $settings::get('name'));
        $logo = Settings::flag('include_logo') ? self::logo() : '';

        if ($name === '' && $logo === '') {
            return new self('', '', $accent, false);
        }

        return new self($name, $logo, $accent, true);
    }

    /** The neutral masthead, for previews and for tests. */
    public static function neutral(): self
    {
        return new self('', '', Settings::accent(), false);
    }

    private static function available(): bool
    {
        return \Plugin::isPluginActive('whitelabel')
            && class_exists(\GlpiPlugin\Whitelabel\Settings::class)
            && class_exists(\GlpiPlugin\Whitelabel\Assets::class);
    }

    // ------------------------------------------------------------- the logo

    /**
     * A masthead-sized copy of the configured logo, cached on disk.
     *
     * Two problems are being solved at once, and both are real rather than
     * theoretical.
     *
     * TCPDF embeds the *file it is given*, at its original pixel dimensions —
     * it does not resample. The logo slot in glpi-whitelabel suggests 200×110
     * for a 100×55 box, but nothing stops an administrator uploading the
     * 1.2 MB print original, and this instance's is exactly that. Every page of
     * every export would then carry it: a four-page procedure came out at
     * 1.1 MB in testing, for a mark 28 mm wide.
     *
     * And TCPDF cannot place an SVG through `Image()` at all. glpi-whitelabel
     * accepts SVG — correctly, it is the right format for a logo — so the
     * masthead has to be a raster copy or there is no masthead for anyone who
     * uploaded one.
     *
     * So the logo is rasterised and downscaled once and kept. The cache key is
     * glpi-whitelabel's own `revision`, which that plugin already bumps on every
     * save for exactly this reason — its stylesheet and images are served by
     * PHP and would otherwise be held by browsers. Reusing it means a re-upload
     * invalidates this cache too, with nothing here having to watch for it.
     */
    private static function logo(): string
    {
        /** @var class-string $settings */
        $settings = \GlpiPlugin\Whitelabel\Settings::class;
        /** @var class-string $assets */
        $assets = \GlpiPlugin\Whitelabel\Assets::class;

        $source = $assets::path('logo');
        if ($source === null || !is_readable($source)) {
            return '';
        }

        $revision = (string) $settings::get('revision');
        $cached    = self::cacheDir() . '/logo-' . preg_replace('/\W+/', '', $revision) . '.png';

        if (is_file($cached) && filesize($cached) > 0) {
            return $cached;
        }

        $rendered = self::rasterise($source);
        if ($rendered === null) {
            return '';
        }

        self::sweepCache();

        return @file_put_contents($cached, $rendered) === false ? '' : $cached;
    }

    /**
     * Turn whatever was uploaded into a small PNG.
     *
     * SVG is not attempted. Rasterising one needs ImageMagick or a renderer,
     * neither of which GLPI requires, and a plugin that works on some
     * instances and silently does not on others is worse than one that is
     * clear about it — the settings page says so, and the document falls back
     * to the wordmark, which is a legitimate masthead rather than a broken one.
     */
    private static function rasterise(string $source): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }

        $info = @getimagesize($source);
        if ($info === false) {
            return null;
        }

        $image = match ($info[2]) {
            IMAGETYPE_PNG  => @imagecreatefrompng($source),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_GIF  => @imagecreatefromgif($source),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            default        => false,
        };

        if (!$image) {
            return null;
        }

        $width  = imagesx($image);
        $height = imagesy($image);

        // 28 mm at 300 dpi is ~330 px. Anything larger is invisible on paper
        // and expensive on every page of every export.
        $target_w = self::MAX_LOGO_PX;
        $target_h = (int) round($height * ($target_w / max(1, $width)));

        if ($width <= $target_w) {
            $target_w = $width;
            $target_h = $height;
        }

        $canvas = imagecreatetruecolor($target_w, max(1, $target_h));

        // A logo is very often transparent, and a masthead is on white. Losing
        // the alpha channel turns a transparent mark into a black rectangle,
        // which is the single most visible way this could go wrong.
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagealphablending($canvas, true);

        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $target_w, max(1, $target_h), $width, $height);

        ob_start();
        imagepng($canvas, null, 9);
        $bytes = (string) ob_get_clean();

        imagedestroy($canvas);
        imagedestroy($image);

        return $bytes !== '' ? $bytes : null;
    }

    private const MAX_LOGO_PX = 360;

    private static function cacheDir(): string
    {
        $dir = GLPI_PLUGIN_DOC_DIR . '/glpipdf';

        if (!is_dir($dir)) {
            @mkdir($dir, 0o770, true);
        }

        return $dir;
    }

    /**
     * Keep one logo, not one per revision ever saved.
     *
     * glpi-whitelabel's revision bumps on *every* save of its settings page,
     * not only on an image change, so an administrator tuning the dark palette
     * would otherwise leave a trail of identical PNGs behind.
     */
    private static function sweepCache(): void
    {
        foreach ((array) @glob(self::cacheDir() . '/logo-*.png') as $stale) {
            @unlink((string) $stale);
        }
    }
}
