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
  set(key: string | Record<string, unknown>, value?: string | number): void;
  get(key: string): unknown;
  on(event: string, callback: () => void): void;
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

/**
 * The block editor's packages, as globals.
 *
 * Read off `wp` rather than imported, which is this codebase's convention for
 * everything WordPress ships — see core/api.ts. Typed to what the gallery
 * block actually renders and no further: describing @wordpress/components
 * here would be maintaining a copy of its API surface with none of its tests.
 *
 * Every one of these is optional because every one of them is a script handle
 * that a screen either declared or did not.
 */
interface WpBlocks {
  registerBlockType(
    name: string,
    settings: {
      edit: (props: never) => React.ReactNode;
      save: () => React.ReactNode;
      icon?: unknown;
    }
  ): unknown;
}

interface WpBlockEditor {
  InspectorControls: React.ComponentType<{ children?: React.ReactNode }>;
  useBlockProps: () => Record<string, unknown>;
}

interface WpSelectOption {
  label: string;
  value: string;
}

interface WpComponents {
  Button: React.ComponentType<{
    variant?: 'primary' | 'secondary' | 'tertiary' | 'link';
    onClick?: () => void;
    children?: React.ReactNode;
  }>;
  PanelBody: React.ComponentType<{
    title?: string;
    initialOpen?: boolean;
    children?: React.ReactNode;
  }>;
  RangeControl: React.ComponentType<{
    __nextHasNoMarginBottom?: boolean;
    label: string;
    value: number;
    min?: number;
    max?: number;
    step?: number;
    help?: string;
    onChange: (value?: number) => void;
  }>;
  SelectControl: React.ComponentType<{
    __nextHasNoMarginBottom?: boolean;
    label: string;
    value: string;
    options: WpSelectOption[];
    onChange: (value: string) => void;
  }>;
  ToggleControl: React.ComponentType<{
    __nextHasNoMarginBottom?: boolean;
    label: string;
    checked: boolean;
    help?: string;
    onChange: (value: boolean) => void;
  }>;
}

interface WpGlobal {
  apiFetch<T>(options: WpApiFetchOptions): Promise<T>;
  media?: WpMediaFactory;
  a11y?: WpA11y;
  blocks?: WpBlocks;
  blockEditor?: WpBlockEditor;
  components?: WpComponents;
  /**
   * `wp-server-side-render`: the block's own render.php, asked for over REST.
   * One renderer for the editor and the page, so a preview cannot promise a
   * layout the front end does not deliver.
   */
  serverSideRender?: React.ComponentType<{
    block: string;
    attributes: Record<string, unknown>;
  }>;

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

  /**
   * `wp-plupload`'s uploader wrapper — how a folder gets onto an upload.
   *
   * `defaults.multipart_params` is what a *new* uploader copies its
   * parameters from. `prototype.param(key, value)` sets one on a single live
   * uploader. Both are needed and neither replaces the other: the copy is
   * shallow but produces a fresh object, so mutating the defaults after an
   * uploader exists does not reach it. Measured on WP 7.1, not assumed.
   */
  Uploader?: {
    defaults?: { multipart_params?: Record<string, string> };
    prototype: WpUploader;
  };
}

interface WpUploader {
  /** Sets one multipart parameter on this uploader. */
  param(key: string, value: string): void;
  init(): void;
}

/**
 * Config injected by MediaLibraryIntegration via wp_add_inline_script.
 */
interface FolderFolioConfig {
  restUrl: string;
  nonce: string;
  pluginUrl: string;
  /** admin_url('upload.php'), for the import report's way back. */
  uploadUrl?: string;
  /** admin_url('plugins.php'), for the import report's offer to retire the source. */
  pluginsUrl?: string;
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
  /** `folderfolio_max_depth`, filtered — a root folder is depth 0. */
  maxDepth?: number;

  /**
   * THIS USER'S own startup folder, and deliberately not the site's.
   *
   * `null` is no personal choice, `0` is Unassigned, a positive id is a
   * folder — the same three-way the query var uses. The breadcrumb's toggle
   * is pressed when this names the folder being shown; a site-wide startup
   * folder arriving is explained by the sentence under the crumbs instead,
   * because pressing the toggle can only set or clear a value of this user's.
   */
  startupFolder?: number | null;

  /**
   * Another folder plugin's data, when this library has none of ours.
   *
   * Absent in the ordinary case — both because the site has folders here and
   * because working it out costs nine sources' queries. See
   * `Modules\Import\Elsewhere`.
   */
  elsewhere?: {
    key: string;
    label: string;
    folders: number;
    files: number;
    /** How many *other* plugins also hold folders. */
    others: number;
    /** The Import tab, or '' when this user cannot reach it. */
    importUrl: string;
  };
}

declare const wp: WpGlobal | undefined;

interface Window {
  wp?: WpGlobal;
  folderFolio?: FolderFolioConfig;
}
