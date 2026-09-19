<?php

declare(strict_types=1);

namespace FolderFolio\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The cache-busting version for one of the plugin's own asset files.
 *
 * ## Why this exists
 *
 * The bundles esbuild writes carry a content hash in their `*.asset.php`
 * manifest, so `apps/rail.js?ver=6b678efb…` changes the moment its contents
 * change. The hand-written files beside them — `core/admin.css`,
 * `core/rail.js`, `core/frame.css`, `core/settings.css`, the block's two
 * stylesheets — were enqueued at `FOLDERFOLIO_VERSION` instead, which changes
 * **once per release**.
 *
 * On a release that is nearly right; on a development site it is wrong all
 * day. A stylesheet rebuilt twenty times under the same `?ver=1.0.0` is
 * twenty different files behind one URL, and a browser that fetched the first
 * one keeps it. That produced a real, hard-to-read bug: the `Filter`
 * disclosure rendered from a fresh hash-versioned bundle while the rules that
 * open its panel came from a stale stylesheet, so the button was on screen,
 * its click handler ran, its state changed — and nothing moved.
 *
 * It is not only a development problem. A user who takes a hotfix that ships
 * a corrected stylesheet without a version bump has exactly the same stale
 * copy, and no way to know.
 *
 * ## What it does
 *
 * `filemtime()` on the file, which changes precisely when the file does, and
 * never otherwise. Falls back to `FOLDERFOLIO_VERSION` when the file is
 * missing or unreadable — a `false` from `filemtime()` would otherwise become
 * `?ver=0` and pin the asset forever.
 *
 * One `stat` per enqueued file per request. `wp_enqueue_style` callers already
 * `file_exists()` the same path, so on those paths it is a cache hit.
 */
final class Assets
{
    /**
     * @param string $relative Path under the plugin directory, e.g.
     *                         `assets/build/core/admin.css`.
     */
    public static function version(string $relative): string
    {
        $path = FOLDERFOLIO_PLUGIN_DIR . ltrim($relative, '/');

        if (!is_readable($path)) {
            return FOLDERFOLIO_VERSION;
        }

        $stamp = filemtime($path);

        return false === $stamp ? FOLDERFOLIO_VERSION : (string) $stamp;
    }
}
