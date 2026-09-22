<?php

declare(strict_types=1);

namespace FolderFolio\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Domain\FolderRepository;
use FolderFolio\Support\Capabilities;
use FolderFolio\Support\Settings;

/**
 * The folder the media library opens in — tier 1 item 7.
 *
 * ## Why this is a redirect and not a default
 *
 * The obvious build is three lines in `MediaLibraryFilter`: when the request
 * names no folder, use the stored one. It is also the wrong build, and the
 * reason is the sentence the readme is going to make —
 *
 * > FolderFolio never filters your media library unless you pick a folder.
 *
 * — which is defended by a negative control asserting the **whole**
 * `posts_clauses` array comes back byte-identical when no folder is asked for.
 * Defaulting an absent parameter inside the filter would have made that
 * sentence false and left the test green, because the test asks the filter and
 * the filter is not what would have changed: the query var would already have
 * been set by the time it ran.
 *
 * So nothing here touches a query. A bare arrival at `upload.php` is sent to
 * the same URL with the folder in it, and the request that actually renders
 * the library is then indistinguishable from the one a click produces. The
 * filter stays untouched, its guard stays byte-identical, and the address bar
 * — the first place anybody looks — says what is being shown.
 *
 * ## Why this is not how the market does it
 *
 * FileBird remembers the last folder you were in and forces it onto
 * `upload.php` with no user action and nothing on screen: **28 of 47 files
 * disappear** on a stock library and the only clue is that the grid looks
 * short. That behaviour is in the "weighed and not taken" list for this
 * project, and the difference is not the feature — it is that this one is
 * asked for, visible in the URL, named in the breadcrumb, and has a × beside
 * it that works.
 *
 * ## The one test, and everything it excludes
 *
 * **The query var being present is the whole condition**, whatever its value.
 * `?folderfolio_folder=` — present and empty — is how the breadcrumb's × says
 * *"all media, and I mean it"*: it normalises to null, so the library is
 * unfiltered, and it survives a reload because this never fires on it. Without
 * that spelling the × would clear the filter and the next refresh would put it
 * straight back, which is a control that appears to work and does not.
 */
final class StartupFolder
{
    /**
     * Marks the arrival this class caused, so the rail can say so.
     *
     * It is on the URL rather than in a transient because it belongs to one
     * page view and nothing else: a second tab opened from a folder link is
     * not a startup arrival, and neither is the same tab after the person has
     * chosen something. `lib/filter.ts` drops it on every selection for the
     * same reason it drops `paged`.
     */
    public const QUERY_VAR = 'folderfolio_startup';

    public function __construct(
        private readonly FolderRepository $folders = new FolderRepository()
    ) {
    }

    public function register(): void
    {
        // `load-upload.php` and nothing else: it fires on the media library
        // screen, before any output, and never for the media modal, the
        // block editor's picker or an ajax grid query — all of which reach
        // the same data through paths that are not this screen.
        add_action('load-upload.php', [$this, 'maybeRedirect']);
    }

    public function maybeRedirect(): void
    {
        if (!$this->shouldRedirect()) {
            return;
        }

        $folderId = Settings::get()['startup_folder'];

        if ($folderId === null || !$this->exists($folderId)) {
            return;
        }

        $url = add_query_arg(
            [
                MediaLibraryFilter::QUERY_VAR => $folderId,
                self::QUERY_VAR => '1',
            ],
            // The request as it stands, so a mode, a search or an attachment
            // already on it survives. add_query_arg() re-encodes what it is
            // given; esc_url_raw is what wp_safe_redirect would want anyway.
            esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'] ?? 'upload.php'))
        );

        // 302, explicitly. A 301 would be cached by the browser and the person
        // would keep landing in a folder after the setting had been changed or
        // the folder deleted.
        wp_safe_redirect($url, 302);

        exit;
    }

    private function shouldRedirect(): bool
    {
        if (wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return false;
        }

        // A POST to upload.php is an upload or a bulk action. Answering it
        // with a redirect would drop the body on the floor.
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return false;
        }

        // Presence, not value — see the class comment. `isset()` would be
        // wrong here: it is false for a null and this is about the key.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (array_key_exists(MediaLibraryFilter::QUERY_VAR, $_GET)) {
            return false;
        }

        // A user who cannot see folders has no rail, no breadcrumb and no ×,
        // so a folder filter would be a library with things missing and no way
        // to find out why.
        return Capabilities::canUseFolders();
    }

    /**
     * Whether the stored folder is still there.
     *
     * A deleted folder must not redirect: it would filter the library to
     * nothing, and the rail would have no row to light up. The setting is
     * deliberately **not** cleared here — a read of a page is not the place to
     * write an option, and a folder that vanished because somebody was
     * mid-restore should not be forgotten on their behalf.
     */
    private function exists(int $folderId): bool
    {
        // Unassigned. Always a valid destination, and there is no row to find.
        if ($folderId === 0) {
            return true;
        }

        return $this->folders->find($folderId) !== null;
    }
}
