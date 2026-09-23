/**
 * Download a folder as a ZIP — the rail's half (tier 2 item 11).
 *
 * Two steps, so the browser never opens a response it would have to refuse:
 * ask `GET /folders/{id}/zip` what the archive would hold, then hand its URL
 * to the browser's own download manager. The archive is streamed by
 * `Admin\FolderDownload`; the browser shows its progress, reports a cut-off
 * download as failed, and its Resume continues one (the response serves byte
 * ranges).
 *
 * Nick's answers (board PpiAmXsixk3sG9yygJQnw5): above 1 GB the sheet says
 * the size and asks first; no hard limit unless a site sets one, in which case
 * the sheet says so instead of starting.
 */

import { apiFetch, errorMessage, t, tn, type ApiEnvelope } from '../../core/api';
import { useRail } from './store';

export interface ZipSummary {
    name: string;
    files: number;
    bytes: number;
    size: string;
    left_out: number;
    confirm: boolean;
    refused: string | null;
    url: string | null;
}

export async function downloadFolder(folderId: number): Promise<void> {
    const { showNotice, dismissNotice } = useRail.getState();

    dismissNotice();

    let summary: ZipSummary;

    try {
        summary = (await apiFetch<ApiEnvelope<ZipSummary>>(`/folders/${folderId}/zip`)).data;
    } catch (error) {
        showNotice(errorMessage(error));

        return;
    }

    if (summary.refused !== null || summary.url === null) {
        showNotice(summary.refused ?? t('zipRefused', 'This folder cannot be downloaded.'));

        return;
    }

    if (summary.files === 0) {
        showNotice(
            summary.left_out > 0
                ? t('zipNoneReadable', 'None of the files in “%s” can be downloaded.', summary.name)
                : t('zipEmpty', '“%s” has no files to download.', summary.name)
        );

        return;
    }

    const url = summary.url;

    if (summary.confirm) {
        showNotice(
            tn(
                'zipConfirmOne',
                'zipConfirmMany',
                summary.files,
                '“%1$s” is %2$s, %3$s file. A download this large can stop partway on some hosts — your browser’s Resume continues it.',
                '“%1$s” is %2$s in %3$s files. A download this large can stop partway on some hosts — your browser’s Resume continues it.',
                summary.name,
                summary.size,
                summary.files.toLocaleString()
            ),
            { label: t('download', 'Download'), run: () => save(url) }
        );

        return;
    }

    save(url);
}

/**
 * Hand the URL to the browser's download manager.
 *
 * A same-origin link with `download`, not a navigation: the page stays where
 * it is, and a response the server refuses becomes a failed download in the
 * browser's list rather than an error page replacing the library.
 */
function save(url: string): void {
    const link = document.createElement('a');

    link.href = url;
    link.download = '';
    link.hidden = true;
    document.body.append(link);
    link.click();
    link.remove();
}
