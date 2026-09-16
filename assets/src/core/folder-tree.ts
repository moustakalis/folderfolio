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
    this.positionPanel();

    this.container = document.getElementById('folderfolio-folder-tree');
    if (!this.container) {
      console.warn('FolderFolio: #folderfolio-folder-tree not found');
      return;
    }

    this.activeFolderId = folderFromUrl();

    // Bound once, never in render(): the listeners are delegated to the
    // container, which survives innerHTML. Re-binding per render stacked a
    // duplicate handler each time, so a folder click fired twice, then three
    // times, and so on.
    this.bindEvents();
    this.setupMediaLibraryIntegration();

    await this.loadTree();
    this.render();
  }

  /**
   * The container is printed on all_admin_notices, which fires before the
   * page's own .wrap - so it lands above the heading with no page padding.
   * That hook is the only server-side point grid and list mode share; getting
   * the position right is the client's job.
   */
  private positionPanel(): void {
    const panel = document.getElementById('folderfolio-sidebar');
    const wrap = document.querySelector('.wrap');

    if (!panel || !wrap || wrap.contains(panel)) {
      return;
    }

    const header = wrap.querySelector('.wp-header-end') ?? wrap.querySelector('h1');

    if (header) {
      header.insertAdjacentElement('afterend', panel);
    } else {
      wrap.prepend(panel);
    }
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

    // Delegated, because render() replaces the input element itself.
    this.container.addEventListener('input', (e) => {
      const target = e.target as HTMLElement;

      if (target.id === 'folderfolio-search-input') {
        this.filterTree((target as HTMLInputElement).value.toLowerCase());
      }
    });
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

  /**
   * Only dispatches. The listener below owns the state change, so selecting a
   * folder and clearing the filter take the same path - clearing used to leave
   * the old folder highlighted because it did not.
   */
  private async handleFolderSelect(folderId: number): Promise<void> {
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
      const folderId = event.detail.folderId ?? null;

      if (this.activeFolderId !== folderId) {
        this.activeFolderId = folderId;
        this.render();
      }

      this.applyFilter(folderId);
    });
  }

  /**
   * Grid mode re-queries in place through the media frame's collection; list
   * mode is an ordinary page load with the folder in the URL.
   */
  private applyFilter(folderId: number | null): void {
    const url = new URL(window.location.href);

    if (folderId === null) {
      url.searchParams.delete(FOLDER_QUERY_VAR);
    } else {
      url.searchParams.set(FOLDER_QUERY_VAR, String(folderId));
    }

    // Page 3 of the old folder is not page 3 of the new one.
    url.searchParams.delete('paged');

    const collection = window.wp?.media?.frame?.content?.get?.()?.collection;

    if (collection?.props) {
      collection.props.set(FOLDER_QUERY_VAR, folderId === null ? '' : folderId);

      // Grid re-queries in place, so nothing would otherwise update the address
      // bar - a refresh or a shared link would lose the filter.
      window.history.replaceState({}, '', url.toString());
      return;
    }

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
