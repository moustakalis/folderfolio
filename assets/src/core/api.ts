export interface ApiEnvelope<T> {
  success: boolean;
  data: T;
}

export interface Folder {
  id: number;
  parent_id: number | null;
  name: string;
  slug: string | null;
  color: string | null;
  icon: string | null;
  sort_order: number;
  children: Folder[];
}

type ApiFetchOptions = {
  method?: string;
  data?: unknown;
};

// WordPress wp.apiFetch global
declare const wp: {
  apiFetch: <T>(options: { path: string } & ApiFetchOptions) => Promise<T>;
};

export function apiFetch<T>(path: string, options: ApiFetchOptions = {}): Promise<T> {
  return wp.apiFetch<T>({
    path: `/folderfolio/v1${path}`,
    ...options,
  });
}

/**
 * Look up a server-supplied label.
 *
 * The config object carried these strings from the start and nothing read
 * them; every visible label was a hardcoded English literal. The fallback
 * keeps each call site readable, and means a missing key degrades to the old
 * behaviour rather than to "undefined".
 *
 * Placeholders are %s, in order, as in the PHP side.
 */
export function t(key: string, fallback: string, ...values: Array<string | number>): string {
  const template = window.folderFolio?.i18n?.[key] ?? fallback;

  return values.reduce<string>((out, value) => out.replace('%s', String(value)), template);
}
