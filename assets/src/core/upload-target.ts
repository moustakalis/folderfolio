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
 * repeatedly after the document is ready. Re-wrapping then wraps *their* init
 * and calls through, so both plugins keep working — we chain even when they
 * do not.
 *
 * The first attempt at the second half re-asserted once, on one macrotask,
 * and lost against FileBird and CatFolders together. `reassertAfterReady()`
 * below has the measurement and why a single tick was the wrong shape: where
 * the failure mode is "last writer wins", one more writer is not one more of
 * the same thing.
 *
 * The `multipart_params` seam is what covered this in the meantime: an
 * uploader built while our wrapper was missing still copied the parameter at
 * construction. The gap was only ever a *live* uploader being re-pointed at a
 * newly selected folder.
 */

import { setStructureHooks, watchStructure, type StructureHooks } from './folder-upload';
import { installSelectFolder } from './select-folder';

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

        // A dropped directory's folders — tier 2 item 9. Here because this is
        // the one moment every uploader is seen, and it needs the same
        // "which folder" answer the parameter above carries.
        watchStructure(this, value);

        return result;
    };

    (wrapper as unknown as Record<string, unknown>)[MARK] = true;
    proto.init = wrapper;
}

/**
 * When the wrap is put back, in milliseconds after the document is ready.
 *
 * A sequence rather than one tick, because "after the plugins that clobber
 * us" is not a single moment. Measured in their own bundles: FileBird calls
 * its patch from `wp.domReady`, which runs the callback *synchronously* when
 * `readyState` is already `interactive` — which it is for every deferred
 * bundle on the page; CatFolders calls its patch from its own
 * `DOMContentLoaded` listener; Premio patches during parsing, from a classic
 * script. Three plugins, three different stages.
 */
const RETRY_DELAYS = [0, 50, 250, 1000];

/**
 * Put the wrap back after the plugins that overwrite it have run.
 *
 * ## Why one macrotask was not enough
 *
 * This bundle is enqueued with `strategy: defer`, and a deferred script runs
 * in the window where `document.readyState` is already **`interactive`**:
 * parsing has finished and `DOMContentLoaded` has *not* fired yet. So the old
 * `readyState === 'loading'` test was false here on every page load, the
 * listener was never registered, and the whole re-assertion rode on a single
 * `setTimeout(…, 0)` queued *before* the task that fires `DOMContentLoaded`
 * was queued. Which of those two the event loop runs first is not specified,
 * and in practice it turns on how long the deferred-script phase took — that
 * is, on how many plugins are active. Which is exactly the measurement: the
 * guard held with FileBird alone and with CatFolders alone, and was gone with
 * both.
 *
 * So the test is `!== 'complete'` — the only readyState at which
 * `DOMContentLoaded` has already fired — and the timer is a sequence rather
 * than a bet, with `window.load` at the end of it.
 *
 * Every entry is idempotent: `watchUploaders()` returns immediately when the
 * wrapper is already ours, so on a site with no rival installed this is a
 * handful of early returns.
 */
function reassertAfterReady(): void {
    const again = (): void => {
        watchUploaders();
        apply();
    };

    const sequence = (): void => {
        again();

        for (const delay of RETRY_DELAYS) {
            window.setTimeout(again, delay);
        }
    };

    if (document.readyState !== 'complete') {
        document.addEventListener('DOMContentLoaded', sequence, { once: true });
        window.addEventListener('load', sequence, { once: true });
    }

    // And now, because a plugin that patched during parsing has already had
    // its turn.
    sequence();

    /*
     * Once more when the page is first touched.
     *
     * A fixed sequence of delays answers the four plugins on the market
     * today, all of which have finished by `load`. It cannot answer one that
     * patches later still — when a media modal opens, say. The first pointer
     * or key event is the cheapest thing that is certain to come before an
     * upload, and `once` means it costs one early return and then nothing.
     */
    const onFirstInput = (): void => {
        again();
    };

    window.addEventListener('pointerdown', onFirstInput, {
        once: true,
        capture: true,
    });
    window.addEventListener('keydown', onFirstInput, {
        once: true,
        capture: true,
    });
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
    initial: number | null,
    structure: StructureHooks = {}
): void {
    setStructureHooks(structure);
    watchUploaders();

    // "or select a folder" under core's Select Files — core/select-folder.ts.
    // It finds its uploader among the live ones by the button it was built
    // around.
    installSelectFolder((browser) => {
        for (const uploader of live) {
            if (uploader.browser?.[0] === browser) {
                return uploader;
            }
        }

        return undefined;
    });
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
