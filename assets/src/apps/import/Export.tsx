/**
 * The way out — tier 1 item 6, on the Import tab's other half.
 *
 * Nine importers in and nothing out is a roach motel, and three of the four
 * plugins this page can read from have no export at all. This is one button
 * and it is in 1.0 for the third of its three jobs: moving a structure to
 * another site, keeping a copy before a large reorganisation, and being able
 * to leave.
 *
 * ## Why the download is built here and not served by the route
 *
 * The REST route returns the document. Turning it into a file could have been
 * the server's job with a `Content-Disposition` header, but that means
 * navigating the browser to an authenticated REST URL with the nonce in the
 * query string — a URL that ends up in history, in a server log, and in
 * whatever the person pastes into a support thread. A `fetch` plus a Blob
 * keeps the nonce in a header where it belongs, and costs four lines.
 *
 * `parse: false` is what makes that possible: wp.apiFetch normally hands back
 * the parsed body and throws the Response away, and the filename travels in a
 * header so the client does not rebuild it from the same two facts and get it
 * subtly different.
 */

import { useState } from 'react';

import { t } from '../../core/api';

declare const wp: {
    apiFetch: <T>(options: { path: string; parse?: boolean }) => Promise<T>;
};

export function Export() {
    const [withAssignments, setWithAssignments] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function download() {
        setBusy(true);
        setError(null);

        try {
            const response = await wp.apiFetch<Response>({
                path: `/folderfolio/v1/export?assignments=${withAssignments ? '1' : '0'}`,
                parse: false,
            });

            const name =
                response.headers.get('X-FolderFolio-Filename') ?? 'folderfolio-export.json';
            const text = JSON.stringify(await response.json(), null, 2);
            const url = URL.createObjectURL(new Blob([text], { type: 'application/json' }));

            const link = document.createElement('a');
            link.href = url;
            link.download = name;
            document.body.append(link);
            link.click();
            link.remove();

            // Not immediately: Safari has not always finished with the object
            // URL by the time click() returns, and revoking it underneath a
            // download in flight produces a failed download with no error.
            setTimeout(() => URL.revokeObjectURL(url), 10_000);
        } catch (e) {
            setError(
                e instanceof Error && e.message
                    ? e.message
                    : t('exportFailed', 'The export could not be produced.')
            );
        } finally {
            setBusy(false);
        }
    }

    return (
        <section className="folderfolio-export">
            <div className="folderfolio-wizard__head">
                <p className="folderfolio-wizard__eyebrow">
                    {t('exportEyebrow', 'Take it with you')}
                </p>
                <p className="folderfolio-wizard__lede">
                    {t(
                        'exportLede',
                        'Download your folder structure as a file — names, nesting, colours and the order everything sits in. Keep it before a big reorganisation, move it to another site, or just have it.'
                    )}
                </p>
            </div>

            <label className="folderfolio-export__option">
                <input
                    type="checkbox"
                    checked={withAssignments}
                    onChange={(event) => setWithAssignments(event.target.checked)}
                />
                <span>
                    {t('exportWithAssignments', 'Include which files are in which folder')}
                    {/*
                      Said plainly rather than left to be discovered: the tree
                      is a few hundred rows and this is one row per filed
                      file, so on a large library it is the difference between
                      a small file and a very large one.
                    */}
                    <span className="folderfolio-export__hint">
                        {t('exportWithAssignmentsHint', 'Makes a much larger file on a big library.')}
                    </span>
                </span>
            </label>

            <button
                type="button"
                className="button button-secondary"
                disabled={busy}
                onClick={() => void download()}
            >
                {busy
                    ? t('exportBusy', 'Preparing…')
                    : t('exportButton', 'Export folder structure')}
            </button>

            {error === null ? null : (
                <p className="folderfolio-wizard__error" role="alert">
                    {error}
                </p>
            )}
        </section>
    );
}
