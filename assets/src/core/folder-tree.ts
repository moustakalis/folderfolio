import { apiFetch, ApiEnvelope, Folder } from './api';

export async function fetchFolderTree(): Promise<Folder[]> {
  const response = await apiFetch<ApiEnvelope<Folder[]>>('/tree');
  return response.data;
}

export function initFolderFolio(): void {
  void fetchFolderTree().catch((error: unknown) => {
    // The visual Media Library integration ships in Phase 3.
    console.error('FolderFolio could not load the folder tree.', error);
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initFolderFolio, { once: true });
} else {
  initFolderFolio();
}
