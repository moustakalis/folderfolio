interface FolderSelectedDetail {
    folderId: number | null;
}

interface FolderFolioMediaEvent extends CustomEvent<FolderSelectedDetail> {}

interface WordPressMediaFrame {
    trigger(event: string, payload?: { folderId: number | null }): void;
}

interface WordPressMedia {
    frame?: WordPressMediaFrame;
}

interface WordPressGlobal {
    media?: WordPressMedia;
}

declare global {
    interface Window {
        wp?: WordPressGlobal;
    }
}

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
    emitMediaFilter(null);
    window.dispatchEvent(
        new CustomEvent<FolderSelectedDetail>('folderfolio:folder-filter-cleared', {
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

document.addEventListener('DOMContentLoaded', () => {
    window.dispatchEvent(new CustomEvent('folderfolio:media-ready'));

    document.addEventListener(eventName, (event: Event) => {
        const { detail } = event as FolderFolioMediaEvent;
        const folderId = detail?.folderId ?? null;

        emitMediaFilter(folderId);

        if (folderId === null) {
            clearFolderFilter();
            return;
        }

        renderInfoBar(folderId);
    });
});
