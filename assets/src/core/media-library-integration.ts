interface FolderSelectedDetail {
    folderId: number | null;
}

interface FolderFolioMediaEvent extends CustomEvent<FolderSelectedDetail> {}

const eventName = 'folderfolio:folder-selected';
const infoBarId = 'folderfolio-current-folder-bar';

function emitMediaFilter(folderId: number | null): void {
    window.wp?.media?.frame?.trigger('folderfolio:filter', { folderId });
}

function getOrCreateInfoBar(): HTMLElement | null {
    const existing = document.getElementById(infoBarId);

    if (existing) {
        return existing;
    }

    const filterBar = document.querySelector<HTMLElement>('.wp-filter');

    if (!filterBar) {
        return null;
    }

    const infoBar = document.createElement('div');
    infoBar.id = infoBarId;
    infoBar.className = 'media-folder-filter';

    filterBar.insertAdjacentElement('afterend', infoBar);

    return infoBar;
}

function clearFolderFilter(): void {
    document.getElementById(infoBarId)?.remove();

    window.dispatchEvent(
        new CustomEvent<FolderSelectedDetail>(eventName, {
            detail: { folderId: null },
        }),
    );
}

function renderInfoBar(folderId: number): void {
    const infoBar = getOrCreateInfoBar();

    if (!infoBar) {
        return;
    }

    infoBar.replaceChildren();

    const label = document.createTextNode(`Filtering by folder #${folderId} `);
    const clearButton = document.createElement('button');

    clearButton.type = 'button';
    clearButton.className = 'button';
    clearButton.textContent = 'Clear filter';
    clearButton.addEventListener('click', clearFolderFilter);

    infoBar.append(label, clearButton);
}

function currentFolderFromUrl(): number | null {
    const raw = new URL(window.location.href).searchParams.get('folderfolio_folder');

    if (raw === null || raw === '') {
        return null;
    }

    const parsed = Number.parseInt(raw, 10);

    return Number.isNaN(parsed) ? null : parsed;
}

function start(): void {
    window.dispatchEvent(new CustomEvent('folderfolio:media-ready'));

    // folder-tree.ts dispatches on window. A listener on document is never in
    // the propagation path of a window-dispatched event, which is why this
    // module previously did nothing at all.
    window.addEventListener(eventName, (event: Event) => {
        const { detail } = event as FolderFolioMediaEvent;
        const folderId = detail?.folderId ?? null;

        emitMediaFilter(folderId);

        if (folderId === null) {
            document.getElementById(infoBarId)?.remove();
            return;
        }

        renderInfoBar(folderId);
    });

    // List mode arrives filtered via the URL, with no event to react to.
    const active = currentFolderFromUrl();

    if (active !== null) {
        renderInfoBar(active);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
} else {
    start();
}
