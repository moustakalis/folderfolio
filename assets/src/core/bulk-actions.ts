/**
 * Bulk assignment actions for Media Library
 * Phase 3.2: Bulk select and assign/move to folder
 */

import { apiFetch, ApiEnvelope } from './api';

export class BulkActions {
  private selectedAttachmentIds: number[] = [];

  init(): void {
    this.bindEvents();
    this.injectBulkActionsUI();
  }

  private bindEvents(): void {
    // Listen for attachment selection changes
    document.addEventListener('change', (e) => {
      const target = e.target as HTMLInputElement;
      if (target.matches('.attachment-sel')) {
        this.updateSelectedAttachments();
      }
    });

    // Listen for bulk action form submissions
    document.addEventListener('submit', (e) => {
      const target = e.target as HTMLFormElement;
      if (target.matches('#posts-filter') || target.matches('.bulkactions')) {
        const action = (target.querySelector('select[name="action"]') as HTMLSelectElement)?.value;
        if (action?.startsWith('folderfolio_')) {
          e.preventDefault();
          void this.handleBulkAction(action);
        }
      }
    });
  }

  private updateSelectedAttachments(): void {
    const checkboxes = document.querySelectorAll('.attachment-sel:checked') as NodeListOf<HTMLInputElement>;
    this.selectedAttachmentIds = Array.from(checkboxes).map((cb) => parseInt(cb.value, 10));
    this.updateBulkActionUI();
  }

  private updateBulkActionUI(): void {
    const countEl = document.getElementById('folderfolio-selected-count');
    const actionContainer = document.getElementById('folderfolio-bulk-actions');

    if (countEl) {
      countEl.textContent = this.selectedAttachmentIds.length.toString();
    }

    if (actionContainer) {
      actionContainer.style.display = this.selectedAttachmentIds.length > 0 ? '' : 'none';
    }
  }

  private injectBulkActionsUI(): void {
    // Add bulk action dropdown to Media Library
    const bulkActionsContainer = document.querySelector('.bulkactions');
    if (!bulkActionsContainer) return;

    const uiHtml = `
      <div id="folderfolio-bulk-actions" style="display: none; margin-left: 20px;">
        <span id="folderfolio-selected-count">0</span> selected
        <button type="button" class="button" id="folderfolio-assign-to-folder">Assign to folder</button>
        <button type="button" class="button" id="folderfolio-move-to-folder">Move to folder</button>
      </div>
    `;

    bulkActionsContainer.insertAdjacentHTML('afterend', uiHtml);

    // Bind buttons
    document.getElementById('folderfolio-assign-to-folder')?.addEventListener('click', () => {
      void this.showFolderPicker('assign');
    });

    document.getElementById('folderfolio-move-to-folder')?.addEventListener('click', () => {
      void this.showFolderPicker('move');
    });
  }

  private async showFolderPicker(mode: 'assign' | 'move'): Promise<void> {
    if (this.selectedAttachmentIds.length === 0) {
      alert('No attachments selected');
      return;
    }

    try {
      const response = await apiFetch<ApiEnvelope<{ tree: Folder[] }>>('/tree');
      const folders = response.data?.tree || [];

      const folderOptions = folders
        .map((f) => `<option value="${f.id}">${this.escapeHtml(f.name)}</option>`)
        .join('');

      const folderIdStr = prompt(
        `Enter folder ID to ${mode} to:\n\n${folders.map((f) => `${f.id}: ${f.name}`).join('\n')}`
      );

      if (!folderIdStr) return;

      const folderId = parseInt(folderIdStr, 10);
      if (!folderId) {
        alert('Invalid folder ID');
        return;
      }

      await this.executeBulkAction(mode, folderId);
    } catch (error) {
      console.error('FolderFolio: Failed to load folders', error);
      alert('Failed to load folders');
    }
  }

  private async executeBulkAction(mode: 'assign' | 'move', folderId: number): Promise<void> {
    const endpoint = mode === 'assign' ? '/attachments/assign' : '/attachments/bulk-move';

    try {
      let data: Record<string, unknown> = { folder_id: folderId, attachment_ids: this.selectedAttachmentIds };

      if (mode === 'move') {
        // For move, we need source folder - for now use first selected attachment's first folder
        data = {
          source_folder_id: 1, // TODO: Get actual source folder
          destination_folder_id: folderId,
          attachment_ids: this.selectedAttachmentIds,
        };
      }

      await apiFetch<ApiEnvelope<Record<string, unknown>>>(endpoint, {
        method: 'POST',
        data,
      });

      alert(`Successfully ${mode}ed ${this.selectedAttachmentIds.length} attachment(s)`);
      this.selectedAttachmentIds = [];
      this.updateBulkActionUI();

      // Reload page to show changes
      location.reload();
    } catch (error) {
      console.error(`FolderFolio: Failed to ${mode} attachments`, error);
      alert(`Failed to ${mode} attachments`);
    }
  }

  private async handleBulkAction(action: string): Promise<void> {
    if (this.selectedAttachmentIds.length === 0) {
      alert('No attachments selected');
      return;
    }

    if (action === 'folderfolio_assign') {
      await this.showFolderPicker('assign');
    } else if (action === 'folderfolio_move') {
      await this.showFolderPicker('move');
    }
  }

  private escapeHtml(text: string): string {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }
}

// Auto-init
const bulkActions = new BulkActions();
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => bulkActions.init(), { once: true });
} else {
  bulkActions.init();
}

export { bulkActions };
