import { apiFetch, ApiEnvelope, Folder } from './api';

/** Must match MediaLibraryFilter::QUERY_VAR. */
const FOLDER_QUERY_VAR = 'folderfolio_folder';

/**
 * The active folder survives a list-mode page load, so read it back off the
 * URL rather than resetting the tree to "All media" after every filter.
 */
function folderFromUrl(): number | null {
  const raw = new URL(window.location.href).searchParams.get(FOLDER_QUERY_VAR);

  if (raw === null || raw === '') {
    return null;
  }

  const parsed = Number.parseInt(raw, 10);

  return Number.isNaN(parsed) ? null : parsed;
}

export class FolderTree {
  private container: HTMLElement | null = null;
  private tree: Folder[] = [];
  private activeFolderId: number | null = null;

  async init(): Promise<void> {
    this.container = document.getElementById('folderfolio-folder-tree');
    if (!this.container) {
      console.warn('FolderFolio: #folderfolio-folder-tree not found');
      return;
    }

    this.activeFolderId = folderFromUrl();

    await this.loadTree();
    this.render();
    this.bindEvents();
    this.setupMediaLibraryIntegration();
  }

  private async loadTree(): Promise<void> {
    try {
      const response = await apiFetch<ApiEnvelope<Folder[]>>('/tree');
      this.tree = response.data || [];
    } catch (error) {
      console.error('FolderFolio: Failed to load folder tree', error);
      this.tree = [];
    }
  }

  private render(): void {
    if (!this.container) return;

    this.container.innerHTML = `
      <div class="folderfolio-tree-header">
        <h3>Folders</h3>
        <div class="folderfolio-actions">
          <button type="button" class="button button-small" id="folderfolio-new-folder">
            <span class="dashicons dashicons-plus-alt"></span>
            New
          </button>
          <button type="button" class="button button-small" id="folderfolio-upload-to-folder">
            <span class="dashicons dashicons-upload"></span>
            Upload
          </button>
        </div>
      </div>
      <div class="folderfolio-tree-search">
        <input type="text" placeholder="Search folders..." id="folderfolio-search-input" />
      </div>
      <ul class="folderfolio-tree-list" id="folderfolio-tree-list">
        ${this.renderTree(this.tree, 0)}
      </ul>
    `;
  }

  private renderTree(folders: Folder[], depth: number): string {
    if (folders.length === 0) {
      return '<li class="folderfolio-empty">No folders yet</li>';
    }

    return folders
      .map(
        (folder) => `
        <li class="folderfolio-tree-item ${this.activeFolderId === folder.id ? 'active' : ''}" data-folder-id="${folder.id}" data-depth="${depth}">
          <div class="folderfolio-folder-row">
            <span class="folderfolio-toggle dashicons ${folder.children && folder.children.length > 0 ? 'dashicons-arrow-down-alt2' : ''}" data-folder-id="${folder.id}"></span>
            <span class="folderfolio-folder-name" data-folder-id="${folder.id}">${this.escapeHtml(folder.name)}</span>
            <span class="folderfolio-folder-count">${folder.children ? folder.children.length : 0}</span>
          </div>
          ${folder.children && folder.children.length > 0 ? `<ul class="folderfolio-children" data-parent-id="${folder.id}">${this.renderTree(folder.children, depth + 1)}</ul>` : ''}
        </li>
      `
      )
      .join('');
  }

  private bindEvents(): void {
    if (!this.container) return;

    // New folder button
    this.container.addEventListener('click', (e) => {
      const target = e.target as HTMLElement;

      if (target.closest('#folderfolio-new-folder')) {
        void this.handleNewFolder();
        return;
      }

      if (target.closest('#folderfolio-upload-to-folder')) {
        void this.handleUploadToFolder();
        return;
      }

      // The id lives on the matched ancestor, not necessarily on the element
      // that was clicked - reading it off the target returned folder 0 for any
      // click that landed on a nested node.
      const name = target.closest('.folderfolio-folder-name');

      if (name) {
        void this.handleFolderSelect(
          parseInt(name.getAttribute('data-folder-id') || '0', 10)
        );
        return;
      }

      const toggle = target.closest('.folderfolio-toggle');

      if (toggle) {
        this.handleToggle(parseInt(toggle.getAttribute('data-folder-id') || '0', 10));
        return;
      }
    });

    // Search input
    const searchInput = this.container.querySelector('#folderfolio-search-input') as HTMLInputElement;
    if (searchInput) {
      searchInput.addEventListener('input', (e) => {
        const query = (e.target as HTMLInputElement).value.toLowerCase();
        this.filterTree(query);
      });
    }
  }

  private async handleNewFolder(): Promise<void> {
    const name = prompt('Enter folder name:');
    if (!name || name.trim() === '') return;

    try {
      await apiFetch<ApiEnvelope<{ id: number; tree: Folder[] }>>('/folders', {
        method: 'POST',
        data: { name: name.trim(), parent_id: this.activeFolderId },
      });
      await this.loadTree();
      this.render();
      this.bindEvents();
    } catch (error) {
      console.error('FolderFolio: Failed to create folder', error);
      alert('Failed to create folder');
    }
  }

  private async handleUploadToFolder(): Promise<void> {
    if (!this.activeFolderId) {
      alert('Please select a folder first');
      return;
    }

    // Dispatch event for upload integration
    window.dispatchEvent(
      new CustomEvent('folderfolio:upload-to-folder', {
        detail: { folderId: this.activeFolderId },
        bubbles: true,
      })
    );
  }

  private async handleFolderSelect(folderId: number): Promise<void> {
    this.activeFolderId = folderId;
    this.render();
    this.bindEvents();

    // Dispatch custom event for Media Library to filter by folder
    window.dispatchEvent(
      new CustomEvent('folderfolio:folder-selected', {
        detail: { folderId },
        bubbles: true,
      })
    );
  }

  private handleToggle(folderId: number): void {
    const childrenContainer = this.container?.querySelector(`.folderfolio-children[data-parent-id="${folderId}"]`);
    const toggleIcon = this.container?.querySelector(`.folderfolio-toggle[data-folder-id="${folderId}"]`);

    if (childrenContainer && toggleIcon) {
      const isExpanded = childrenContainer.classList.contains('expanded');
      childrenContainer.classList.toggle('expanded', !isExpanded);
      toggleIcon.classList.toggle('dashicons-arrow-down-alt2', !isExpanded);
      toggleIcon.classList.toggle('dashicons-arrow-right-alt2', isExpanded);
    }
  }

  private filterTree(query: string): void {
    const items = this.container?.querySelectorAll('.folderfolio-tree-item') as NodeListOf<HTMLElement>;
    items.forEach((item) => {
      const nameEl = item.querySelector('.folderfolio-folder-name');
      const name = nameEl?.textContent?.toLowerCase() || '';
      item.style.display = name.includes(query) ? '' : 'none';
    });
  }

  private escapeHtml(text: string): string {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  /**
   * Filtering is done in SQL by MediaLibraryFilter. All this has to do is get
   * the folder id into the next query the library runs.
   */
  private setupMediaLibraryIntegration(): void {
    window.addEventListener('folderfolio:folder-selected', (e: Event) => {
      const event = e as CustomEvent<{ folderId: number | null }>;
      this.applyFilter(event.detail.folderId);
    });
  }

  /**
   * Grid mode re-queries in place through the media frame's collection; list
   * mode is an ordinary page load with the folder in the URL.
   */
  private applyFilter(folderId: number | null): void {
    const collection = window.wp?.media?.frame?.content?.get?.()?.collection;

    if (collection?.props) {
      collection.props.set(FOLDER_QUERY_VAR, folderId === null ? '' : folderId);
      return;
    }

    const url = new URL(window.location.href);

    if (folderId === null) {
      url.searchParams.delete(FOLDER_QUERY_VAR);
    } else {
      url.searchParams.set(FOLDER_QUERY_VAR, String(folderId));
    }

    // Page 3 of the old folder is not page 3 of the new one.
    url.searchParams.delete('paged');

    window.location.assign(url.toString());
  }

  getActiveFolderId(): number | null {
    return this.activeFolderId;
  }
}

// Auto-init
const folderTree = new FolderTree();
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => void folderTree.init(), { once: true });
} else {
  void folderTree.init();
}

export { folderTree };
