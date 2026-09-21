/**
 * Puts the selected folder on every upload request.
 *
 * The client half of screen 10's "Uploads go to the selected folder." The
 * server half is `Support\UploadTarget`, which reads the same parameter on
 * `add_attachment` — one hook, so the grid, the media modal, drag-and-drop
 * and media-new.php are all covered by the same code.
 *
 * ## Two seams, because one is not enough
 *
 * `wp.Uploader.defaults.multipart_params` is what a *new* uploader copies its
 * parameters from, and `wp.Uploader.prototype.param()` sets one on a single
 * live uploader. The copy is shallow but makes a fresh object, so mutating
 * the defaults afterwards never reaches an uploader that already exists —
 * measured on WP 7.1 rather than assumed. So: the defaults for whatever is
 * built next, and `param()` on everything already built.
 *
 * ## Which means the live uploaders have to be known
 *
 * They are not enumerable from anywhere, and hunting for them through
 * `wp.media.frame` is the kind of structural guess that made the module this
 * replaces dead code. `wp.Uploader.prototype.init` is wrapped instead, purely
 * to record `this` — the same additive shape as lib/media-frame.ts, and
 * deliberately not the `wp.media.create` patch: that one is a factory every
 * plugin calls, where two wrappers means one wins by enqueue order. An
 * uploader's `init` is called once, by its own constructor.
 *
 * ## It is competed for after all, so the wrap is re-asserted
 *
 * Measured 21 Sep against FileBird 6.5.8: `wp.Uploader.prototype.init` is a
 * single slot, and FileBird, CatFolders and Premio's Folders each *assign* it
 * rather than wrapping it, discarding whatever was there. Worse, they patch
 * at `wp.domReady` / `DOMContentLoaded` while this bundle is enqueued with
 * `strategy: defer`, which runs at parse-complete — earlier. So on a site
 * with one of them active our wrapper was gone by the time anything uploaded,
 * with no error: `live` stayed empty and `param()` was never called.
 *
 * Two changes answer it. The guard is the wrapper's own identity rather than
 * a boolean, so a replacement is *detectable*; and the wrap is re-asserted
 * after the document is ready, on a macrotask, which is after every handler
 * the clobberers register. Re-wrapping then wraps *their* init and calls
 * through, so both plugins keep working — we chain even when they do not.
 *
 * The `multipart_params` seam is what covered this in the meantime: an
 * uploader built while our wrapper was missing still copied the parameter at
 * construction. The gap was only ever a *live* uploader being re-pointed at a
 * newly selected folder.
 */

const PARAM = 'folderfolio_folder';

const live = new Set<WpUploader>();
let current: number | null = null;

/**
 * Stamped on our own wrapper so that a plugin which replaced it can be told
 * from our own work. A boolean here instead meant "wrapped once, ever", which
 * is exactly the thing that was not true.
 */
const MARK = 'folderfolioUploadTarget';

function isOurWrapper(fn: unknown): boolean {
    return (
        typeof fn === 'function' &&
        (fn as unknown as Record<string, unknown>)[MARK] === true
    );
}

/**
 * `''` rather than removing the key.
 *
 * `param()` sets; there is no way through it to take a parameter off an
 * uploader that already carries one. An empty value is read as "no folder" on
 * the server — `UploadTarget::read()` returns null for it — which is the same
 * answer, arrived at without reaching into plupload's internals.
 */
function value(): string {
    return current === null ? '' : String(current);
}

function apply(): void {
    const defaults = window.wp?.Uploader?.defaults?.multipart_params;

    if (defaults) {
        if (current === null) {
            delete defaults[PARAM];
        } else {
            defaults[PARAM] = value();
        }
    }

    for (const uploader of live) {
        try {
            uploader.param(PARAM, value());
        } catch {
            // An uploader whose frame has been torn down. Nothing to do about
            // it and nothing to report: it will not be uploading anything.
        }
    }
}

function watchUploaders(): void {
    const proto = window.wp?.Uploader?.prototype;

    // Not `wrapped` — the question is whether the wrapper is still *installed*,
    // which is a different question once another plugin can overwrite it.
    if (!proto || isOurWrapper(proto.init)) {
        return;
    }

    // Whatever is there now, which on a site running FileBird or CatFolders is
    // their replacement rather than core's. Calling through keeps their
    // upload-to-folder working alongside ours.
    const original = proto.init;

    const wrapper = function (this: WpUploader, ...args: unknown[]): void {
        live.add(this);

        // Its parameters were copied from the defaults a moment ago, in the
        // constructor, so it is already correct — unless this ran before the
        // first folder was known, which is why it is set again here.
        const result = (original as (...a: unknown[]) => void).apply(this, args);

        try {
            this.param(PARAM, value());
        } catch {
            // As above.
        }

        return result;
    };

    (wrapper as unknown as Record<string, unknown>)[MARK] = true;
    proto.init = wrapper;
}

/**
 * Put the wrap back after the plugins that overwrite it have run.
 *
 * `strategy: defer` puts this bundle at parse-complete; `wp.domReady` and
 * `DOMContentLoaded` handlers run afterwards, and a macrotask after the event
 * has been dispatched runs after all of them. Both steps are cheap and
 * idempotent — `watchUploaders()` returns immediately when the wrapper is
 * already ours — so this costs nothing on a site with no rival installed.
 */
function reassertAfterReady(): void {
    const again = (): void => {
        watchUploaders();
        apply();
    };

    const afterHandlers = (): void => {
        again();
        setTimeout(again, 0);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', afterHandlers, {
            once: true,
        });
    } else {
        afterHandlers();
    }
}

/**
 * Start following a folder selection.
 *
 * `subscribe` returns the whole state in Zustand 5, so the id is compared
 * here: the store changes on every arrow key in the tree, and re-setting a
 * parameter that has not changed would mean touching every live uploader
 * dozens of times while somebody is only looking around.
 *
 * Both app bundles call this. Neither of them is the media library's own
 * JavaScript, so there is no screen where an uploader exists and no bundle of
 * ours is on the page.
 */
export function watchUploadTarget(
    subscribe: (listener: (selectedId: number | null) => void) => void,
    initial: number | null
): void {
    watchUploaders();
    reassertAfterReady();

    // `0` is Unassigned and `null` is All media. Neither is a folder, and an
    // upload made while looking at either is exactly the upload that should
    // stay unfiled.
    current = initial !== null && initial > 0 ? initial : null;
    apply();

    subscribe((selectedId) => {
        const next = selectedId !== null && selectedId > 0 ? selectedId : null;

        if (next === current) {
            return;
        }

        current = next;

        // The uploader may not have existed when this module first ran — on a
        // post-edit screen nothing builds one until a frame opens.
        watchUploaders();
        apply();
    });
}
