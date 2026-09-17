/**
 * The settings screen's only script — the Copy report button on the Status
 * tab.
 *
 * Everything else on screen 08 is a form that posts to admin-post.php, which
 * is why this file is forty lines and not an app. The report is already in a
 * readonly <textarea> on the page: with this script the button copies it, and
 * without it the textarea can still be selected by hand. That is the whole
 * enhancement.
 */

const COPY_ATTR = 'data-folderfolio-copy';

function report(button: HTMLElement): HTMLTextAreaElement | null {
    const selector = button.getAttribute(COPY_ATTR);

    return selector ? document.querySelector<HTMLTextAreaElement>(selector) : null;
}

async function copy(button: HTMLButtonElement): Promise<void> {
    const target = report(button);

    if (!target) {
        return;
    }

    const original = button.textContent ?? '';

    try {
        // navigator.clipboard is unavailable on plain-HTTP admins, which a
        // surprising number of local installs still are. Selecting the
        // textarea leaves the report highlighted and one keystroke away,
        // which is better than a button that silently does nothing.
        await navigator.clipboard.writeText(target.value);
    } catch {
        target.focus();
        target.select();

        return;
    }

    // The button is the only feedback surface here; a notice would be a
    // second thing to dismiss for an action that cannot fail visibly.
    button.textContent = button.dataset.folderfolioCopied ?? 'Copied';

    window.setTimeout(() => {
        button.textContent = original;
    }, 2000);
}

document.addEventListener('click', (event) => {
    const target = event.target;

    if (!(target instanceof Element)) {
        return;
    }

    const button = target.closest<HTMLButtonElement>(`button[${COPY_ATTR}]`);

    if (button) {
        void copy(button);
    }
});
