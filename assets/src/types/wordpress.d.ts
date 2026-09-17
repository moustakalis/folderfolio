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

  /**
   * The Backbone view constructors.
   *
   * Only `AttachmentsBrowser` is named, and only its prototype, because that
   * is the single thing lib/media-frame.ts touches: it wraps `initialize` to
   * publish each browser view on its own element. Typing the rest of
   * `wp.media.view` would be describing an API nothing here calls.
   */
  view?: {
    AttachmentsBrowser?: { prototype?: Record<string, unknown> };
  };
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
  /** admin_url('upload.php'), for the import report's way back. */
  uploadUrl?: string;
  version: string;
  /**
   * The four abilities of the roles matrix, resolved for this user. Optional
   * for the same reason the settings below are.
   */
  can?: Record<'create' | 'rename' | 'delete' | 'assign', boolean>;
  i18n: Record<string, string>;

  /**
   * Site settings — screen 08. Optional because two bundles write this object
   * and the older of them predates them; every reader has a fallback.
   */
  countMode?: 'inherited' | 'direct';
  defaultSort?: string;
  /** The undo grace period, in seconds. */
  undoWindow?: number;
}

declare const wp: WpGlobal | undefined;

interface Window {
  wp?: WpGlobal;
  folderFolio?: FolderFolioConfig;
}
