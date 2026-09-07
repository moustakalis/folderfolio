import { apiFetch, ApiEnvelope, Folder } from './api';

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

    await this.loadTree();
    this.render();
    this.bindEvents();
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
        <button type="button" class="button button-small" id="folderfolio-new-folder">
          <span class="dashicons dashicons-plus-alt"></span>
          New
        </button>
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

      if (target.closest('.folderfolio-folder-name')) {
        const folderId = parseInt(target.getAttribute('data-folder-id') || '0', 10);
        void this.handleFolderSelect(folderId);
        return;
      }

      if (target.closest('.folderfolio-toggle')) {
        const folderId = parseInt(target.getAttribute('data-folder-id') || '0', 10);
        this.handleToggle(folderId);
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
