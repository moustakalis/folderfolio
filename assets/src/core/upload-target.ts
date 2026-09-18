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
 * uploader's `init` is called once, by its own constructor, on an object
 * nobody else is competing for.
 */

const PARAM = 'folderfolio_folder';

const live = new Set<WpUploader>();
let wrapped = false;
let current: number | null = null;

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

    if (!proto || wrapped) {
        return;
    }

    wrapped = true;

    const original = proto.init;

    proto.init = function (this: WpUploader, ...args: unknown[]): void {
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
