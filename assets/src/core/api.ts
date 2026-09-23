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
 * A label with a singular and a plural form.
 *
 * "1 files selected" is the kind of thing nobody reports and everybody sees.
 * The two forms are two keys in the same config object, chosen here by the
 * count, which is the same arrangement `t()` uses and needs no second
 * translation mechanism on the client.
 *
 * The honest limitation: this is English's two-form rule applied to every
 * language. Languages with three or more plural forms — Polish, Russian,
 * Arabic — need the CLDR rule that only wp-i18n's `_n()` carries, and using it
 * would mean shipping JSON translation files alongside the .po this plugin's
 * PHP already uses. That is a decision about how the plugin is translated, not
 * about this label; until it is taken, one form each is better than one form
 * for both.
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
  // A third shape: the import routes answer `{success: false, error: "…"}`,
  // the sentence itself where the folder routes put an object.
  const body = error as { error?: { message?: unknown } | string; message?: unknown } | null;
  const message =
    typeof body?.error === 'string' ? body.error : (body?.error?.message ?? body?.message);

  return typeof message === 'string' && message.trim() !== ''
    ? message
    : t('actionFailed', 'That could not be done.');
}
