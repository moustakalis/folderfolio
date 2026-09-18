/**
 * Putting the folder tree inside WordPress's media frame — screen 10.
 *
 * ## What v0.2.0 did, and why it is not registered
 *
 * `MediaModalIntegration` replaced `wp.media.create` with a wrapper that
 * returned a decorated frame. That is a global swap of the factory every
 * plugin on the site calls, so a second plugin doing the same thing gets one
 * of the two wrappers and not the other, and the order depends on enqueue
 * order. It is the reason that class has sat unregistered since the rebuild
 * began, and none of it survives here.
 *
 * ## What this does instead
 *
 * Almost all of it is DOM. The frame renders
 *
 *     .media-modal .media-frame-content > .attachments-browser
 *
 * and the folder column is a sibling inserted before the browser, held there
 * by the same slot mechanism the library toolbar uses (lib/toolbar-slot.ts) —
 * so it survives the frame re-rendering, and it appears and disappears with
 * the modal without anything having to be told that the modal opened.
 *
 * The one thing the DOM cannot answer is *which collection this browser is
 * showing*, and that is what filtering needs. `wp.media.frame` is not a
 * reliable answer: the block editor creates frames without setting it. So
 * there is exactly one patch here, and it adds no behaviour — it calls
 * through to core first and then writes a reference to the view onto the
 * view's own element. Nothing else about the frame changes, a second plugin
 * wrapping the same method still works, and if the patch ever fails to apply
 * the column still renders and only filtering stops.
 */

import type { Place } from './toolbar-slot';

/** Our reference, parked on the browser view's own element. */
interface BrowserElement extends HTMLElement {
    folderfolioBrowser?: {
        collection?: { props?: { set: (key: string, value: unknown) => void } };
    };
}

interface PatchableProto {
    initialize?: (...args: unknown[]) => unknown;
    folderfolioPatched?: boolean;
}

/**
 * Make every AttachmentsBrowser reachable from its own DOM node.
 *
 * Idempotent, and a no-op when `wp.media` is not on the page — which is the
 * normal state on an admin screen that never opens a picker.
 */
export function publishBrowsers(): boolean {
    const proto = (
        window.wp?.media?.view?.AttachmentsBrowser as { prototype?: PatchableProto } | undefined
    )?.prototype;

    if (!proto || typeof proto.initialize !== 'function') {
        return false;
    }

    if (proto.folderfolioPatched) {
        return true;
    }

    const original = proto.initialize;

    proto.initialize = function (this: { el?: BrowserElement }, ...args: unknown[]) {
        // Core first, always. Whatever this returns is what the caller gets.
        const result = original.apply(this, args);

        if (this.el) {
            this.el.folderfolioBrowser = this as unknown as BrowserElement['folderfolioBrowser'];
        }

        return result;
    };

    proto.folderfolioPatched = true;

    return true;
}

/**
 * The folder column's place: first child of the frame's content area, before
 * the attachments.
 *
 * Scoped to `.media-modal` deliberately. `.media-frame-content >
 * .attachments-browser` is also the grid on upload.php, which already has the
 * rail — and two folder trees on one screen is worse than none.
 */
export function frameColumnPlace(): Place | null {
    const browser = document.querySelector('.media-modal .media-frame-content .attachments-browser');

    if (!browser?.parentElement) {
        return null;
    }

    return { parent: browser.parentElement, before: browser };
}

/**
 * Where screen 10's "Uploads go to the selected folder." goes: the frame's
 * own footer, left of the Select button.
 *
 * Prepended into `.media-toolbar` rather than placed in a named region,
 * because the footer's regions are not the same on every frame — a select
 * frame has `.media-toolbar-primary` holding its button, and a frame in a
 * different mode may have no secondary region at all. First child of the
 * toolbar is left of everything in every one of them, since core floats the
 * primary region right.
 */
export function frameFooterPlace(): Place | null {
    const toolbar = document.querySelector('.media-modal .media-frame-toolbar .media-toolbar');

    if (!toolbar) {
        return null;
    }

    return { parent: toolbar, before: toolbar.firstElementChild };
}

/**
 * Filter the open frame to a folder.
 *
 * The collection re-queries itself; `ajax_query_attachments_args` reads the
 * prop out of the posted query exactly as it does for the library grid, so
 * there is no second server path for this. Returns false when there is no
 * frame to filter, which is not an error — the column can be rendered a frame
 * before the browser view exists.
 */
export function filterFrame(folderId: number | null): boolean {
    const browser = document.querySelector<BrowserElement>(
        '.media-modal .attachments-browser'
    )?.folderfolioBrowser;

    const props = browser?.collection?.props;

    if (!props) {
        return false;
    }

    // '' rather than null: the query var is absent when empty, and `0` is a
    // real value meaning Unassigned. Same three-way rule as lib/filter.ts.
    props.set('folderfolio_folder', folderId === null ? '' : folderId);

    return true;
}
