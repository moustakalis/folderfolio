import { pickForm } from './plural';

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
 * Whose folders this screen shows — tier 3 item 12.
 *
 * `attachment` on the media library and in every picker; a post type on its
 * own list screen. Read from the config each time rather than captured, so
 * the value is whatever the page was printed with.
 */
export function objectType(): string {
  return window.folderFolio?.objectType ?? 'attachment';
}

/** Is this screen's tree the media library's? Only files have downloads, file order and "with files". */
export function isMedia(): boolean {
  return objectType() === 'attachment';
}

/**
 * A path naming no folder, for this screen's type: `/folders` on media, as it
 * always was, and `/folders?object_type=page` on Pages. A request that names
 * a folder needs none of this — the server answers from the folder.
 */
export function typedPath(path: string): string {
  return isMedia() ? path : `${path}${path.includes('?') ? '&' : '?'}object_type=${encodeURIComponent(objectType())}`;
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
  const entry = window.folderFolio?.i18n?.[key];

  // A plural entry is an array of forms and is read by `tn()`; asked for as
  // a plain label it is the fallback, never "a,b,c".
  return fill(typeof entry === 'string' ? entry : fallback, values);
}

/** Put `values` into a template's `%s` / `%1$s` placeholders. */
function fill(template: string, values: Array<string | number>): string {
  // Positional placeholders first. A sentence with two values in it has to be
  // translatable into a language that wants them in the other order, which is
  // what `%1$s` is for and why WordPress's own strings use it; filling those
  // sequentially would leave `%1$s` on the screen, which is how this was
  // found — "Folders — %1$s of %2$s", rendered verbatim, in the import wizard.
  if (/%\d+\$s/.test(template)) {
    return template.replace(/%(\d+)\$s/g, (match, index: string) => {
      const value = values[Number(index) - 1];

      return value === undefined ? match : String(value);
    });
  }

  return values.reduce<string>((out, value) => out.replace('%s', String(value)), template);
}

/**
 * A label with a singular and a plural form — or as many forms as the site's
 * language has.
 *
 * "1 files selected" is the kind of thing nobody reports and everybody sees,
 * and "5 plik" is the Polish of it. The server sends a counted label as one
 * entry under the `one` key holding **every form of the translation**, in
 * its order (Support\Plurals, from `_n_noop()`), and the config's
 * `pluralRule` is the translation's own Plural-Forms expression; this picks
 * the form the count takes. English is `n != 1` over two forms.
 *
 * `many` and the two fallbacks are what renders when the entry is missing —
 * a config from an older writer, or a test page — with English's rule.
 */
export function tn(
  one: string,
  many: string,
  count: number,
  fallbackOne: string,
  fallbackMany: string,
  ...values: Array<string | number>
): string {
  // `count` picks the form and nothing else — it is not injected into the
  // string. A sentence with two numbers in it ("Added 3 files to 2 folders")
  // has one of them deciding the plural and both of them appearing, and a
  // helper that silently filled the first placeholder would put the wrong one
  // there half the time.
  const forms = window.folderFolio?.i18n?.[one];

  if (Array.isArray(forms) && forms.length > 0) {
    return fill(pickForm(forms, count, window.folderFolio?.pluralRule), values);
  }

  return count === 1 ? t(one, fallbackOne, ...values) : t(many, fallbackMany, ...values);
}

/**
 * The server's own sentence, whichever shape it arrived in.
 *
 * Our routes answer `{success: false, error: {code, message}}`; core's own
 * refusals — a 403 from a permission callback, a missing argument — answer
 * `{code, message, data}`. `wp.apiFetch` rejects with the body as it came, not
 * with an `Error`, so there is no `.message` on the first shape at all.
 */
export function errorMessage(error: unknown): string {
  // Every route answers `{success: false, error: {code, message}}` — the
  // import routes too, since 24 Sep. A bare string is still read, so a
  // response cached from before that still says something.
  const body = error as { error?: { message?: unknown } | string; message?: unknown } | null;
  const message =
    typeof body?.error === 'string' ? body.error : (body?.error?.message ?? body?.message);

  return typeof message === 'string' && message.trim() !== ''
    ? message
    : t('actionFailed', 'That could not be done.');
}
