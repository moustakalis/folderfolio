/**
 * Folder tree component for Media Library.
 * Phase 3 - to be implemented.
 */

import { apiFetch } from './api';

export function initFolderTree(): void {
    // TODO: Initialize folder tree UI.
    console.log('FolderFolio: Folder tree initialized');
}

// Auto-init on DOM ready.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initFolderTree);
} else {
    initFolderTree();
}
