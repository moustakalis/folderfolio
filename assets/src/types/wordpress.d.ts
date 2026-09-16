/**
 * Minimal ambient declarations for the WordPress admin globals FolderFolio
 * touches. Deliberately narrow: only what the bundles actually call.
 */

interface WpApiFetchOptions {
  path: string;
  method?: string;
  data?: unknown;
}

interface WpMediaSelection {
  map<T>(callback: (model: { id: number }) => T): { toArray(): T[] };
}

interface WpMediaState {
  get(key: 'selection'): WpMediaSelection;
}

interface WpMediaCollectionProps {
  set(key: string, value: string | number): void;
}

interface WpMediaCollection {
  props?: WpMediaCollectionProps;
}

interface WpMediaContentView {
  collection?: WpMediaCollection;
}

interface WpMediaContentRegion {
  get?(): WpMediaContentView | undefined;
}

interface WpMediaFrame {
  el: Element;
  content?: WpMediaContentRegion;
  on(event: string, callback: () => void): void;
  open(): void;
  state(): WpMediaState;
  trigger(event: string, payload?: { folderId: number | null }): void;
}

interface WpMediaFactory {
  (options?: Record<string, unknown>): WpMediaFrame;
  create(options?: Record<string, unknown>): WpMediaFrame;
  frame?: WpMediaFrame;
}

interface WpGlobal {
  apiFetch<T>(options: WpApiFetchOptions): Promise<T>;
  media?: WpMediaFactory;
}

/**
 * Config injected by MediaLibraryIntegration via wp_add_inline_script.
 */
interface FolderFolioConfig {
  restUrl: string;
  nonce: string;
  pluginUrl: string;
  version: string;
  canManageFolders: boolean;
  i18n: Record<string, string>;
}

declare const wp: WpGlobal | undefined;

interface Window {
  wp?: WpGlobal;
  folderFolio?: FolderFolioConfig;
}
