/**
 * Upload integration - auto-assign uploaded media to current folder
 * Phase 3.3: Upload-to-Current-Folder
 */

import { apiFetch, ApiEnvelope, t } from './api';

export class UploadIntegration {
  private activeFolderId: number | null = null;
  private uploadObserver: MutationObserver | null = null;

  setActiveFolder(folderId: number | null): void {
    this.activeFolderId = folderId;
  }

  init(): void {
    this.activeFolderId = this.folderFromUrl();
    this.bindUploadEvents();
  }

  private folderFromUrl(): number | null {
    const raw = new URL(window.location.href).searchParams.get('folderfolio_folder');

    if (raw === null || raw === '') {
      return null;
    }

    const parsed = Number.parseInt(raw, 10);

    return Number.isNaN(parsed) || parsed === 0 ? null : parsed;
  }

  private bindUploadEvents(): void {
    // Selecting a folder - or clearing the filter, which dispatches null -
    // is the only thing that changes the upload target.
    window.addEventListener('folderfolio:folder-selected', (e: Event) => {
      const event = e as CustomEvent<{ folderId: number | null }>;
      this.setActiveFolder(event.detail.folderId ?? null);
    });

    // The folder tree owns the Upload button and dispatches this when it is
    // pressed. This module used to build a second button with the same id
    // inside a DOMContentLoaded handler registered *after* DOMContentLoaded
    // had already fired, so it never appeared at all.
    window.addEventListener('folderfolio:upload-to-folder', (e: Event) => {
      const event = e as CustomEvent<{ folderId: number }>;
      this.setActiveFolder(event.detail.folderId);
      this.openUploadModal();
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
    } catch (error) {
      console.error('FolderFolio: Failed to assign attachment', error);
    }
  }

  private openUploadModal(): void {
    if (typeof wp === 'undefined' || !wp.media) {
      // Hardcoding /wp-admin/ breaks subdirectory installs and any site that
      // has moved wp-admin; the server tells us where it actually is.
      const mediaNew = window.folderFolio?.mediaNewUrl;

      if (mediaNew) {
        window.location.href = `${mediaNew}?folder_id=${this.activeFolderId}`;
      }

      return;
    }

    // Open WordPress media uploader
    const frame = wp.media({
      title: t('uploadModalTitle', 'Upload to Folder'),
      button: {
        text: t('upload', 'Upload'),
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
      alert(t('assignSuccess', 'Assigned %s file(s) to the folder.', assignedCount));

      // Reload page to show changes
      location.reload();
    } catch (error) {
      console.error('FolderFolio: Failed to assign attachments', error);
      alert(t('assignFailed', 'Could not assign the selected files.'));
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
