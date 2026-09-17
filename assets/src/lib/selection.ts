/**
 * What the library has selected, in whichever mode it is in.
 *
 * Both modes are read from the DOM rather than from their own selection
 * models, and that is deliberate. Grid keeps its selection in a Backbone
 * collection reachable through `wp.media.frame.state().get('selection')`;
 * list keeps it nowhere at all — the checkboxes *are* the state. Reading the
 * grid through Backbone would mean one code path per mode, and the Backbone
 * one depends on internals that core has moved before. The rendered DOM is
 * the one surface both modes agree on and neither is going to reshape
 * quietly.
 *
 * `li.attachment.selected` is set by core's own view; `.check-column
 * :checkbox:checked` is the list table's. Nothing here writes either.
 */

const GRID = 'li.attachment.selected[data-id]';
const LIST = '#the-list tr .check-column input[type="checkbox"]:checked';

/**
 * The attachment ids the user has marked, right now.
 *
 * Grid wins when both are non-empty, which in practice never happens — only
 * one mode is on screen. The order matters only for that impossible case, and
 * grid is the mode whose selection is visible without scrolling.
 */
export function selectedAttachmentIds(): number[] {
    const grid = ids(document.querySelectorAll<HTMLElement>(GRID), (el) => el.dataset.id ?? '');

    if (grid.length > 0) {
        return grid;
    }

    return ids(document.querySelectorAll<HTMLInputElement>(LIST), (el) => el.value);
}

function ids<T extends Element>(list: NodeListOf<T>, read: (el: T) => string): number[] {
    return [...list]
        .map((el) => Number.parseInt(read(el), 10))
        .filter((n) => !Number.isNaN(n));
}

/**
 * Call back whenever the selection changes. Returns a teardown.
 *
 * Two sources, because the two modes change the DOM in two different ways: a
 * grid tile gains a class, a list checkbox fires `change`. Watching only
 * mutations would miss nothing in either mode — a checked checkbox is not an
 * attribute change, so `:checked` moves without the DOM changing at all.
 *
 * Coalesced through a timeout rather than requestAnimationFrame: this has to
 * keep working in a tab where the render loop is not running, which is the
 * case in an automated browser and the reason an earlier version of the
 * breadcrumb looked broken when it was not.
 */
export function watchSelection(onChange: (ids: number[]) => void): () => void {
    let last = '';
    let queued: ReturnType<typeof setTimeout> | undefined;

    const check = () => {
        queued = undefined;

        const next = selectedAttachmentIds();
        const signature = next.join(',');

        if (signature === last) {
            return;
        }

        last = signature;
        onChange(next);
    };

    const schedule = () => {
        if (queued === undefined) {
            queued = setTimeout(check, 0);
        }
    };

    // Select-all in the list header toggles every row at once; one `change`
    // bubbles per box, and the coalescing above turns that into one call.
    document.addEventListener('change', schedule, true);

    const observer = new MutationObserver(schedule);
    observer.observe(document.body, {
        subtree: true,
        childList: true,
        attributes: true,
        attributeFilter: ['class'],
    });

    schedule();

    return () => {
        document.removeEventListener('change', schedule, true);
        observer.disconnect();

        if (queued !== undefined) {
            clearTimeout(queued);
        }
    };
}
