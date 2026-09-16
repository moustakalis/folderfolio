/**
 * Bulk folder assignment for the Media Library list view.
 *
 * Scoped to list mode: it reads WordPress's own row checkboxes and sits next
 * to the native bulk-action controls. Grid mode has a separate selection model
 * and is handled with the media frame, not here.
 */

import { apiFetch, ApiEnvelope, Folder, t } from './api';

/** Must match MediaLibraryFilter::QUERY_VAR. */
const FOLDER_QUERY_VAR = 'folderfolio_folder';

const CHECKBOX_SELECTOR = 'input[name="media[]"]';

interface FlatFolder {
  id: number;
  label: string;
}

export class BulkActions {
  private selectedAttachmentIds: number[] = [];

  private folders: FlatFolder[] = [];

  /**
   * Folder currently being filtered on. A move has to come *from* somewhere,
   * and this is the only place the answer exists.
   */
  private sourceFolderId: number | null = null;

  private container: HTMLElement | null = null;

  async init(): Promise<void> {
    if (document.querySelector(CHECKBOX_SELECTOR) === null) {
      return;
    }

    this.sourceFolderId = this.folderFromUrl();

    this.injectUi();
    this.bindEvents();

    await this.loadFolders();
    this.updateSelection();
  }

  private folderFromUrl(): number | null {
    const raw = new URL(window.location.href).searchParams.get(FOLDER_QUERY_VAR);

    if (raw === null || raw === '') {
      return null;
    }

    const parsed = Number.parseInt(raw, 10);

    return Number.isNaN(parsed) || parsed === 0 ? null : parsed;
  }

  private injectUi(): void {
    const anchor = document.querySelector('.bulkactions');

    if (!anchor || document.getElementById('folderfolio-bulk-actions')) {
      return;
    }

    const container = document.createElement('div');
    container.id = 'folderfolio-bulk-actions';
    container.className = 'folderfolio-bulk-actions';
    container.hidden = true;

    const count = document.createElement('span');
    count.id = 'folderfolio-selected-count';
    count.className = 'folderfolio-selected-count';

    const select = document.createElement('select');
    select.id = 'folderfolio-bulk-folder';
    select.disabled = true;

    const assign = document.createElement('button');
    assign.type = 'button';
    assign.className = 'button';
    assign.id = 'folderfolio-bulk-assign';
    assign.textContent = t('assignToFolder', 'Assign to folder');

    const move = document.createElement('button');
    move.type = 'button';
    move.className = 'button';
    move.id = 'folderfolio-bulk-move';
    move.textContent = t('moveToFolder', 'Move to folder');
    move.disabled = this.sourceFolderId === null;
    move.title =
      this.sourceFolderId === null
        ? t(
            'moveNeedsSource',
            'Filter the library by a folder first - a move needs a folder to move out of.'
          )
        : '';

    container.append(count, select, assign, move);
    anchor.insertAdjacentElement('afterend', container);

    this.container = container;
  }

  private bindEvents(): void {
    document.addEventListener('change', (e) => {
      const target = e.target as HTMLElement;

      // #cb-select-all-1/2 toggle every row checkbox, so recount on either.
      if (target.matches(CHECKBOX_SELECTOR) || target.matches('#cb-select-all-1, #cb-select-all-2')) {
        this.updateSelection();
      }
    });

    document.getElementById('folderfolio-bulk-assign')?.addEventListener('click', () => {
      void this.run('assign');
    });

    document.getElementById('folderfolio-bulk-move')?.addEventListener('click', () => {
      void this.run('move');
    });
  }

  private async loadFolders(): Promise<void> {
    try {
      // GET /tree returns the tree as data directly, not wrapped in {tree}.
      const response = await apiFetch<ApiEnvelope<Folder[]>>('/tree');
      this.folders = this.flatten(response.data || [], 0);
    } catch (error) {
      console.error('FolderFolio: failed to load folders', error);
      this.folders = [];
    }

    this.renderFolderOptions();
  }

  private flatten(folders: Folder[], depth: number): FlatFolder[] {
    return folders.flatMap((folder) => [
      { id: folder.id, label: `${'— '.repeat(depth)}${folder.name}` },
      ...this.flatten(folder.children || [], depth + 1),
    ]);
  }

  private renderFolderOptions(): void {
    const select = document.getElementById('folderfolio-bulk-folder') as HTMLSelectElement | null;

    if (!select) {
      return;
    }

    select.replaceChildren();

    if (this.folders.length === 0) {
      const empty = document.createElement('option');
      empty.textContent = t('emptyTree', 'No folders yet');
      empty.value = '';
      select.append(empty);
      select.disabled = true;
      return;
    }

    for (const folder of this.folders) {
      const option = document.createElement('option');
      option.value = String(folder.id);
      // textContent, not innerHTML: folder names are user input.
      option.textContent = folder.label;
      select.append(option);
    }

    select.disabled = false;
  }

  private updateSelection(): void {
    const checked = document.querySelectorAll<HTMLInputElement>(`${CHECKBOX_SELECTOR}:checked`);

    this.selectedAttachmentIds = Array.from(checked)
      .map((checkbox) => Number.parseInt(checkbox.value, 10))
      .filter((id) => !Number.isNaN(id));

    const count = document.getElementById('folderfolio-selected-count');

    if (count) {
      count.textContent = t('selectedCount', '%s selected', this.selectedAttachmentIds.length);
    }

    if (this.container) {
      this.container.hidden = this.selectedAttachmentIds.length === 0;
    }
  }

  private async run(mode: 'assign' | 'move'): Promise<void> {
    const select = document.getElementById('folderfolio-bulk-folder') as HTMLSelectElement | null;
    const destination = Number.parseInt(select?.value || '', 10);

    if (this.selectedAttachmentIds.length === 0 || Number.isNaN(destination)) {
      return;
    }

    if (mode === 'move' && this.sourceFolderId === null) {
      return;
    }

    if (mode === 'move' && this.sourceFolderId === destination) {
      window.alert(t('alreadyInFolder', 'Those files are already in that folder.'));
      return;
    }

    const request =
      mode === 'assign'
        ? {
            path: '/attachments/assign',
            data: {
              folder_id: destination,
              attachment_ids: this.selectedAttachmentIds,
            },
          }
        : {
            path: '/attachments/bulk-move',
            data: {
              source_folder_id: this.sourceFolderId,
              destination_folder_id: destination,
              attachment_ids: this.selectedAttachmentIds,
            },
          };

    try {
      await apiFetch<ApiEnvelope<Record<string, number>>>(request.path, {
        method: 'POST',
        data: request.data,
      });

      window.location.reload();
    } catch (error) {
      console.error(`FolderFolio: bulk ${mode} failed`, error);
      window.alert(
        mode === 'assign'
          ? t('assignFailed', 'Could not assign the selected files.')
          : t('moveFailed', 'Could not move the selected files.')
      );
    }
  }
}

const bulkActions = new BulkActions();

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => void bulkActions.init(), { once: true });
} else {
  void bulkActions.init();
}

export { bulkActions };
