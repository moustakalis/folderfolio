/**
 * Media Modal integration - adds folder tree to wp.media picker
 * Phase 4: Media Modal Integration
 */

import { apiFetch, ApiEnvelope, Folder } from './api';

export class MediaModalIntegration {
  private activeFolderId: number | null = null;
  private modalFrame: any = null;

  init(): void {
    this.interceptMediaFrames();
    this.injectFolderTree();
  }

  private interceptMediaFrames(): void {
    if (typeof wp === 'undefined' || !wp.media) {
      console.warn('FolderFolio: wp.media not available');
      return;
    }

    // Intercept all media frames
    const originalCreate = wp.media.create;
    wp.media.create = function (options: any) {
      const frame = originalCreate.call(this, options);

      // Listen for frame open to inject folder tree
      frame.on('open', () => {
        setTimeout(() => {
          MediaModalIntegrationInstance.injectFolderTreeIntoModal(frame);
        }, 100);
      });

      return frame;
    };
  }

  private injectFolderTreeIntoModal(frame: any): void {
    const $frame = jQuery(frame.el);
    const $sidebar = $frame.find('.media-sidebar');

    if ($sidebar.length === 0) return;

    // Check if already injected
    if ($frame.find('#folderfolio-modal-tree').length > 0) return;

    const treeHtml = `
      <div id="folderfolio-modal-tree" class="folderfolio-modal-tree">
        <div class="folderfolio-modal-header">
          <h4>Folders</h4>
          <button type="button" class="button button-small" id="folderfolio-modal-new-folder">
            <span class="dashicons dashicons-plus-alt"></span>
          </button>
        </div>
        <input type="text" placeholder="Search..." class="folderfolio-modal-search" />
        <div class="folderfolio-modal-content" id="folderfolio-modal-content"></div>
      </div>
    `;

    $sidebar.prepend(treeHtml);

    // Load and render tree
    void this.loadModalTree('folderfolio-modal-content');

    // Bind modal events
    this.bindModalEvents(frame);
  }

  private async loadModalTree(containerId: string): Promise<void> {
    try {
      const response = await apiFetch<ApiEnvelope<Folder[]>>('/tree');
      const folders = response.data || [];
      const container = document.getElementById(containerId);

      if (!container) return;

      container.innerHTML = this.renderModalTree(folders, 0);
    } catch (error) {
      console.error('FolderFolio: Failed to load modal tree', error);
      const container = document.getElementById(containerId);
      if (container) {
        container.innerHTML = '<p class="folderfolio-error">Failed to load folders</p>';
      }
    }
  }

  private renderModalTree(folders: Folder[], depth: number): string {
    if (folders.length === 0) {
      return '<p class="folderfolio-empty">No folders</p>';
    }

    return folders
      .map(
        (folder) => `
        <div class="folderfolio-modal-item ${this.activeFolderId === folder.id ? 'active' : ''}" data-folder-id="${folder.id}" data-depth="${depth}">
          <span class="folderfolio-modal-toggle dashicons ${folder.children && folder.children.length > 0 ? 'dashicons-arrow-down-alt2' : 'dashicons-minus'}"></span>
          <span class="folderfolio-modal-name">${this.escapeHtml(folder.name)}</span>
        </div>
        ${folder.children && folder.children.length > 0 ? `<div class="folderfolio-modal-children" data-parent-id="${folder.id}" style="display: none;">${this.renderModalTree(folder.children, depth + 1)}</div>` : ''}
      `
      )
      .join('');
  }

  private bindModalEvents(frame: any): void {
    const $frame = jQuery(frame.el);

    // Folder selection
    $frame.on('click', '.folderfolio-modal-name', (e: Event) => {
      const target = e.currentTarget as HTMLElement;
      const folderId = parseInt(target.dataset.folderId || '0', 10);
      void this.handleModalFolderSelect(frame, folderId);
    });

    // Toggle children
    $frame.on('click', '.folderfolio-modal-toggle', (e: Event) => {
      const target = e.currentTarget as HTMLElement;
      const folderId = parseInt(target.dataset.folderId || '0', 10);
      this.handleModalToggle($frame, folderId);
    });

    // New folder
    $frame.on('click', '#folderfolio-modal-new-folder', async () => {
      const name = prompt('Enter folder name:');
      if (!name || name.trim() === '') return;

      try {
        await apiFetch<ApiEnvelope<{ id: number }>>('/folders', {
          method: 'POST',
          data: { name: name.trim(), parent_id: this.activeFolderId },
        });
        await this.loadModalTree('folderfolio-modal-content');
      } catch (error) {
        console.error('FolderFolio: Failed to create folder', error);
        alert('Failed to create folder');
      }
    });
  }

  private async handleModalFolderSelect(frame: any, folderId: number): Promise<void> {
    this.activeFolderId = folderId;

    // Update UI
    const $frame = jQuery(frame.el);
    $frame.find('.folderfolio-modal-item').removeClass('active');
    $frame.find(`[data-folder-id="${folderId}"]`).addClass('active');

    // Filter attachments in modal
    await this.filterModalAttachments(frame, folderId);
  }

  private async filterModalAttachments(frame: any, folderId: number): Promise<void> {
    try {
      const response = await apiFetch<ApiEnvelope<{ attachment_ids: number[] }>>(
        `/folders/${folderId}/attachments`
      );
      const attachmentIds = response.data?.attachment_ids || [];

      const $frame = jQuery(frame.el);
      const $attachments = $frame.find('.attachment');

      $attachments.each((_: number, el: Element) => {
        const $el = jQuery(el);
        const attachmentId = parseInt($el.data('attachment-id')?.toString() || '0', 10);
        const isVisible = attachmentIds.includes(attachmentId);
        $el.css('display', isVisible ? '' : 'none');
      });

      // Show filter indicator
      this.showModalFilterIndicator(frame, folderId);
    } catch (error) {
      console.error('FolderFolio: Failed to filter modal attachments', error);
    }
  }

  private showModalFilterIndicator(frame: any, folderId: number): void {
    const $frame = jQuery(frame.el);
    let $indicator = $frame.find('#folderfolio-modal-filter-indicator');

    if ($indicator.length === 0) {
      $indicator = jQuery(
        `<div id="folderfolio-modal-filter-indicator" class="folderfolio-filter-indicator">
          Filtering by folder #${folderId}
          <button type="button" class="button button-small" id="folderfolio-modal-clear-filter">Clear</button>
        </div>`
      );
      $frame.find('.media-toolbar').first().after($indicator);
    }

    $indicator.find('#folderfolio-modal-clear-filter').on('click', () => {
      this.clearModalFilter(frame);
    });
  }

  private clearModalFilter(frame: any): void {
    const $frame = jQuery(frame.el);
    $frame.find('#folderfolio-modal-filter-indicator').remove();
    $frame.find('.attachment').css('display', '');
    $frame.find('.folderfolio-modal-item').removeClass('active');
    this.activeFolderId = null;
  }

  private handleModalToggle($frame: jQuery, folderId: number): void {
    const $children = $frame.find(`.folderfolio-modal-children[data-parent-id="${folderId}"]`);
    const $toggle = $frame.find(`.folderfolio-modal-toggle[data-folder-id="${folderId}"]`);

    if ($children.length === 0) return;

    const isExpanded = $children.css('display') !== 'none';
    $children.css('display', isExpanded ? 'none' : 'block');
    $toggle
      .removeClass('dashicons-arrow-down-alt2 dashicons-arrow-right-alt2')
      .addClass(isExpanded ? 'dashicons-arrow-right-alt2' : 'dashicons-arrow-down-alt2');
  }

  private injectFolderTree(): void {
    // Global folder tree for non-modal contexts
    console.log('FolderFolio: Media Modal integration initialized');
  }

  private escapeHtml(text: string): string {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }
}

const MediaModalIntegrationInstance = new MediaModalIntegration();
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => MediaModalIntegrationInstance.init(), { once: true });
} else {
  MediaModalIntegrationInstance.init();
}

export { MediaModalIntegrationInstance };
