/**
 * Upload integration - auto-assign uploaded media to current folder
 * Phase 3.3: Upload-to-Current-Folder
 */

import { apiFetch, ApiEnvelope } from './api';

export class UploadIntegration {
  private activeFolderId: number | null = null;
  private uploadObserver: MutationObserver | null = null;

  setActiveFolder(folderId: number | null): void {
    this.activeFolderId = folderId;
    console.log('FolderFolio: Active folder set to', folderId);
  }

  init(): void {
    this.bindUploadEvents();
    this.injectUploadUI();
  }

  private bindUploadEvents(): void {
    // Listen for folder selection to track active folder
    window.addEventListener('folderfolio:folder-selected', (e: Event) => {
      const event = e as CustomEvent<{ folderId: number }>;
      this.setActiveFolder(event.detail.folderId);
    });

    // Listen for folder clear to reset active folder
    document.addEventListener('click', (e) => {
      const target = e.target as HTMLElement;
      if (target.matches('#folderfolio-clear-filter')) {
        this.setActiveFolder(null);
      }
    });

    // Watch for new attachments in the media grid (after upload)
    this.watchForNewAttachments();
  }

  private watchForNewAttachments(): void {
    const attachmentsContainer = document.querySelector('.attachments');
    if (!attachmentsContainer) return;

    this.uploadObserver = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
          if (node instanceof HTMLElement && node.matches('.attachment')) {
            const attachmentId = parseInt(node.dataset.attachmentId || '0', 10);
            if (attachmentId && this.activeFolderId) {
              void this.assignToFolder(attachmentId, this.activeFolderId);
            }
          }
        });
      });
    });

    this.uploadObserver.observe(attachmentsContainer, {
      childList: true,
      subtree: false,
    });
  }

  private async assignToFolder(attachmentId: number, folderId: number): Promise<void> {
    try {
      await apiFetch<ApiEnvelope<{ assigned: number }>>('/attachments/assign', {
        method: 'POST',
        data: {
          folder_id: folderId,
          attachment_ids: [attachmentId],
        },
      });
      console.log(`FolderFolio: Assigned attachment ${attachmentId} to folder ${folderId}`);
    } catch (error) {
      console.error('FolderFolio: Failed to assign attachment', error);
    }
  }

  private injectUploadUI(): void {
    // Add "Upload to folder" button in folder tree
    document.addEventListener('DOMContentLoaded', () => {
      const folderTreeHeader = document.querySelector('.folderfolio-tree-header');
      if (!folderTreeHeader) return;

      const uploadBtn = document.createElement('button');
      uploadBtn.type = 'button';
      uploadBtn.className = 'button button-small';
      uploadBtn.id = 'folderfolio-upload-to-folder';
      uploadBtn.innerHTML = '<span class="dashicons dashicons-upload"></span> Upload to folder';
      uploadBtn.style.marginLeft = '8px';

      uploadBtn.addEventListener('click', () => {
        if (!this.activeFolderId) {
          alert('Please select a folder first');
          return;
        }
        this.openUploadModal();
      });

      folderTreeHeader.appendChild(uploadBtn);
    });
  }

  private openUploadModal(): void {
    if (typeof wp === 'undefined' || !wp.media) {
      // Fallback: redirect to media-new.php with folder parameter
      window.location.href = `/wp-admin/media-new.php?folder_id=${this.activeFolderId}`;
      return;
    }

    // Open WordPress media uploader
    const frame = wp.media({
      title: 'Upload to Folder',
      button: {
        text: 'Upload',
      },
      multiple: true,
    });

    frame.on('select', () => {
      const attachments = frame.state().get('selection');
      const attachmentIds = attachments.map((model: { id: number }) => model.id).toArray();

      if (this.activeFolderId && attachmentIds.length > 0) {
        void this.bulkAssignToFolder(attachmentIds, this.activeFolderId);
      }
    });

    frame.open();
  }

  private async bulkAssignToFolder(attachmentIds: number[], folderId: number): Promise<void> {
    try {
      const response = await apiFetch<ApiEnvelope<{ assigned: number }>>('/attachments/assign', {
        method: 'POST',
        data: {
          folder_id: folderId,
          attachment_ids: attachmentIds,
        },
      });

      const assignedCount = response.data?.assigned || attachmentIds.length;
      alert(`Successfully assigned ${assignedCount} file(s) to folder`);

      // Reload page to show changes
      location.reload();
    } catch (error) {
      console.error('FolderFolio: Failed to assign attachments', error);
      alert('Failed to assign files to folder');
    }
  }
}

// Auto-init
const uploadIntegration = new UploadIntegration();
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => uploadIntegration.init(), { once: true });
} else {
  uploadIntegration.init();
}

export { uploadIntegration };
