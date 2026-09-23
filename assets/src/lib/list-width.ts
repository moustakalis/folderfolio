/**
 * Core's narrow list table when the table is narrow, not only the window.
 *
 * WordPress folds a list table into its phone shape at a *viewport* of 782px.
 * Beside the rail the table can be under 500px wide in a 1000px window, with
 * every column still at its declared width — measured on 23 Sep, Title and
 * Folders were one letter wide. `_content.css` has core's own narrow rules
 * scoped to `#posts-filter.folderfolio-list--narrow`; this sets the class.
 *
 * A ResizeObserver on the form, and not a container query: `container-type`
 * makes the form a formatting context of its own, which then refuses to flow
 * past core's floated `.subsubsub` and moved the table 134px right at 1440.
 */

/** Core's table is never laid out much under this — ~707px at a 783px window. */
export const NARROW_LIST = 700;

export function watchListWidth(): () => void {
    const form = document.getElementById('posts-filter');

    if (!form || !form.querySelector('.wp-list-table') || typeof ResizeObserver === 'undefined') {
        return () => undefined;
    }

    const apply = () => {
        form.classList.toggle('folderfolio-list--narrow', form.clientWidth > 0 && form.clientWidth < NARROW_LIST);
    };

    const observer = new ResizeObserver(apply);
    observer.observe(form);
    apply();

    return () => {
        observer.disconnect();
        form.classList.remove('folderfolio-list--narrow');
    };
}
