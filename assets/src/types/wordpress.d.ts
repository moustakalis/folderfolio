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

/**
 * wp-a11y. Optional because it is only present when something on the screen
 * declared it as a script dependency.
 */
interface WpA11y {
  speak(message: string, politeness?: 'polite' | 'assertive'): void;
}

interface WpGlobal {
  apiFetch<T>(options: WpApiFetchOptions): Promise<T>;
  media?: WpMediaFactory;
  a11y?: WpA11y;

  /**
   * The `wp-element` script handle: WordPress's own React, react-dom and
   * react-dom/client flattened into one namespace.
   *
   * Typed as `unknown` on purpose. The shims in assets/src/shims are the only
   * code that should touch it, and each of them casts this to the precise
   * module type it stands in for — `typeof import('react')` and friends.
   * Anything else importing `react` gets the real @types/react, because the
   * substitution happens in the bundler, not in the type system.
   */
  element?: unknown;
}

/**
 * Config injected by MediaLibraryIntegration via wp_add_inline_script.
 */
interface FolderFolioConfig {
  restUrl: string;
  nonce: string;
  pluginUrl: string;
  mediaNewUrl: string;
  version: string;
  canManageFolders: boolean;
  i18n: Record<string, string>;
}

declare const wp: WpGlobal | undefined;

interface Window {
  wp?: WpGlobal;
  folderFolio?: FolderFolioConfig;
}
