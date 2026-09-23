<?php

declare(strict_types=1);

namespace FolderFolio\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The inline script that hands a bundle its `window.folderFolio`.
 *
 * ## Why one writer, and why it merges
 *
 * Five screens' PHP each printed `window.folderFolio = window.folderFolio ||
 * {…}` — first writer wins, the rest are discarded whole. That was safe only
 * while no two of them met on one page with different contents, and they do:
 * on the block editor the gallery block's config (enqueued first) and the
 * media picker's both run. Found verifying tier 2 item 9 (23 Sep): the
 * gallery's config won, so the picker read **its** abilities —
 * `create`, `rename` and `delete` all false, written for a read-only
 * inspector tree — and was read-only for an administrator; read **its**
 * strings, and fell back to English for every picker label; and a directory
 * dropped there uploaded flat because `can('create')` said no.
 *
 * So the writers merge instead. Scalars keep first-writer-wins, which is what
 * every writer's comment already assumed. `i18n` is a union — no writer's
 * string can be lost to another's. `can` is not merged at all, because since
 * this change every writer states the same four answers from
 * `Capabilities::can()`: an ability is a fact about the user, and a screen
 * that wants to offer less narrows it in its own bundle (the gallery does,
 * `restrictAbilities()` in lib/can.ts), never in a global other bundles read.
 */
final class ClientConfig
{
    /**
     * @param array<string, mixed> $config
     */
    public static function script(array $config): string
    {
        return '(function(own){'
            . 'var had=window.folderFolio||{};'
            . 'var merged=Object.assign({},own,had);'
            . 'merged.i18n=Object.assign({},own.i18n||{},had.i18n||{});'
            . 'window.folderFolio=merged;'
            . '})(' . wp_json_encode($config) . ');';
    }
}
