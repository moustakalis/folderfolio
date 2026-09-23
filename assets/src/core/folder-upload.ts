/**
 * Upload a folder structure from disk — tier 2 item 9.
 *
 * Drop a directory from the desktop onto the media library and its folders
 * are made under the selected folder, and every file is filed into the one it
 * sat in. The drop itself is core's: `moxie.js` already reads a dropped
 * directory recursively and marks each file with its `relativePath`, and
 * WordPress uploads the lot flat into whatever folder is selected. What this
 * adds is the structure.
 *
 * ## An id on the upload, never a path
 *
 * `Support\UploadTarget` reads one parameter on `add_attachment` and refuses
 * anything but a folder id — a path there would make an upload request a way
 * to write folders that nobody typed on purpose. That stays true. The folders
 * are made **first**, through the same route the Tools tab's bulk-create uses
 * (`POST /folders/bulk`, the `create` ability), and each upload then carries
 * the id of the folder its file belongs in. A person dropping a directory has
 * asked for folders; a URL parameter has not.
 *
 * ## Holding the queue while the folders are made
 *
 * `wp-plupload.js` starts the upload inside its own `FilesAdded` handler,
 * synchronously, so the first file's `BeforeUpload` fires before any request
 * could have come back. This binds `BeforeUpload` at a higher priority and
 * returns `false` for a file whose folder is not known yet — plupload leaves
 * it queued and the loop stops. When the folders exist, `stop()` then
 * `start()` runs the loop again from the first queued file. Both are
 * plupload's public API; `stop()`'s `CancelUpload` aborts only an XHR that is
 * in flight, and there is none while the loop is held.
 *
 * ## When the folders cannot be made
 *
 * The files still upload — into the selected folder, as a plain upload would —
 * and the notice says why the folders were not made. Losing the upload over a
 * folder that could not be created would be the worse failure. The usual
 * reasons are the nesting limit and the `create` ability; the second is known
 * before anything is sent, so it is said without asking the server.
 */

import { apiFetch, errorMessage, t } from './api';
import { can } from '../lib/can';
import { redrawUpload, showUpload } from '../lib/filter';
import { chunk, isInDirectory, isLitter, planStructure } from '../lib/structure';

const PARAM = 'folderfolio_folder';

/** `Domain\FolderBulk::MAX_PATHS`. */
const LINES_PER_REQUEST = 500;

const STARTED = 2;
const FAILED = 4;
const FILE_EXTENSION_ERROR = -601;

/** Higher than wp-plupload.js's 0, so these run before it. */
const FIRST = 100;

/** Lower, so this runs after it. */
const LAST = -100;

export interface StructureHooks {
    /** Folders were made, or files landed in them — redraw the tree. */
    changed?: () => void;
    /** A sentence for the screen. */
    notice?: (message: string) => void;
}

let hooks: StructureHooks = {};

export function setStructureHooks(next: StructureHooks): void {
    hooks = next;
}

interface Batch {
    /** Path → folder id, filled when the requests come back. */
    ids: Map<string, number>;
    settled: boolean;
    /** The queue is held on one of this batch's files. */
    held: boolean;
}

interface BulkRow {
    error?: string | null;
    folder_id?: number;
}

/**
 * Make the folders, a turn of 500 at a time — every turn planned first.
 *
 * The plan route writes nothing and marks each line it would refuse, with the
 * reason: the nesting limit, the 191-character name. Asking it first is what
 * lets the notice say *that* rather than the create route's refusal, which
 * was written for the Tools tab's preview ("Fix the ones marked below") and
 * means nothing over a grid of uploading files. It also means a drop too deep
 * at its 600th path writes nothing at all, rather than 500 folders and a
 * refusal.
 *
 * Rows come back one per line, in the order sent, so a row's index is its
 * path's index.
 */
async function makeFolders(
    paths: string[],
    parentId: number | null
): Promise<{ ids: Map<string, number>; error: string | null }> {
    const ids = new Map<string, number>();
    const turns = chunk(paths, LINES_PER_REQUEST);
    const body = (turn: string[]) => ({
        text: turn.join('\n'),
        ...(parentId !== null ? { parent_id: parentId } : {}),
    });

    try {
        for (const turn of turns) {
            const plan = await apiFetch<{ data: { rows: BulkRow[] } }>('/folders/bulk/plan', {
                method: 'POST',
                data: body(turn),
            });
            const refused = plan.data.rows.find((row) => typeof row.error === 'string');

            if (refused?.error) {
                return { ids, error: refused.error };
            }
        }

        for (const turn of turns) {
            const made = await apiFetch<{ data: { rows: BulkRow[] } }>('/folders/bulk', {
                method: 'POST',
                data: body(turn),
            });

            turn.forEach((path, at) => {
                const id = made.data.rows[at]?.folder_id;

                if (typeof id === 'number' && id > 0) {
                    ids.set(path, id);
                }
            });
        }
    } catch (error) {
        // A turn refused after its plan passed — somebody else's write in
        // between, or a session that expired. Earlier turns stand and their
        // files are filed; the rest go where a plain upload would.
        return { ids, error: errorMessage(error) };
    }

    return { ids, error: null };
}

const watched = new WeakSet<object>();

/**
 * Follow one uploader's queue for dropped directories.
 *
 * `target` is the selected folder as the upload parameter spells it — the
 * id, or `''` for none — read at the moment each file starts, which is what
 * a plain upload has always used.
 */
export function watchStructure(uploader: WpUploader, target: () => string): void {
    const up = uploader.uploader;

    if (!up || watched.has(up)) {
        return;
    }

    watched.add(up);

    /** plupload file id → the batch it belongs to and its folder's path. */
    const filed = new Map<string, { batch: Batch; path: string }>();

    up.bind(
        'FilesAdded',
        (queue: PlUploader, files: PlFile[]) => {
            const relative = files.map((file) => file.getSource?.()?.relativePath ?? '');

            // A plain upload — the overwhelming case — costs one scan.
            if (!relative.some(isInDirectory)) {
                return;
            }

            const plan = planStructure(relative);

            files.forEach((file, at) => {
                if (plan.place[at] === null) {
                    // FAILED first: wp-plupload's own FilesAdded handler, which
                    // runs next over this same array, skips a failed file, so
                    // no progress tile is drawn for it.
                    file.status = FAILED;
                    queue.removeFile(file);
                }
            });

            if (plan.paths.length === 0) {
                return;
            }

            if (!can('create')) {
                hooks.notice?.(
                    t(
                        'uploadNoFolders',
                        'You can upload these files but not make folders, so they are uploading without their folders.'
                    )
                );

                return;
            }

            const batch: Batch = { ids: new Map(), settled: false, held: false };

            files.forEach((file, at) => {
                const where = plan.place[at];

                if (where !== null && where !== undefined && where >= 0) {
                    filed.set(file.id, { batch, path: plan.paths[where] });
                }
            });

            const selected = target();
            const parentId = selected === '' ? null : Number(selected);

            void makeFolders(plan.paths, parentId).then(({ ids, error }) => {
                batch.ids = ids;
                batch.settled = true;

                if (ids.size > 0) {
                    hooks.changed?.();
                }

                if (error !== null) {
                    hooks.notice?.(
                        t(
                            'uploadFoldersFailed',
                            'The folders in this upload could not be made, so its files are uploading without them. %s',
                            error
                        )
                    );
                }

                if (batch.held && queue.state === STARTED) {
                    batch.held = false;
                    queue.stop();
                    queue.start();
                }
            });
        },
        undefined,
        FIRST
    );

    up.bind(
        'BeforeUpload',
        (queue: PlUploader, file: PlFile) => {
            const entry = filed.get(file.id);

            if (!entry) {
                // Set every time, not only for a dropped directory's files: a
                // file from a directory may have gone before this one, and it
                // left its own folder's id on the uploader.
                queue.settings.multipart_params[PARAM] = target();

                return undefined;
            }

            if (!entry.batch.settled) {
                entry.batch.held = true;

                return false;
            }

            const id = entry.batch.ids.get(entry.path);
            queue.settings.multipart_params[PARAM] = id !== undefined ? String(id) : target();

            return undefined;
        },
        undefined,
        FIRST
    );

    /*
     * Litter plupload refused before `FilesAdded` — `.DS_Store` has no
     * extension WordPress allows — is not an error anybody made. Returning
     * `false` stops wp-plupload's handler, which would list it under the grid
     * as a failed upload. Anything else refused inside a dropped directory, a
     * `.psd` say, is still listed: that one was meant.
     */
    up.bind(
        'Error',
        (_queue: PlUploader, error: { code?: number; file?: PlFile }) => {
            const relative = error.file?.getSource?.()?.relativePath ?? '';

            return error.code === FILE_EXTENSION_ERROR && isLitter(relative) ? false : undefined;
        },
        undefined,
        FIRST
    );

    /*
     * After wp-plupload's own FilesAdded, which is what builds each file's
     * Attachment model: put every upload in the grid it was made in
     * (lib/filter.ts, showUpload()). Plain uploads too — a grid on a folder
     * showed none of those either.
     */
    up.bind(
        'FilesAdded',
        (_queue: PlUploader, files: PlFile[]) => {
            const here = target();

            for (const file of files) {
                if (file.attachment && file.status !== FAILED) {
                    showUpload(file.attachment, here);
                }
            }
        },
        undefined,
        LAST
    );

    up.bind(
        'FileUploaded',
        (_queue: PlUploader, file: PlFile) => {
            filed.delete(file.id);

            // After wp-plupload has put the server's answer on the model.
            if (file.attachment) {
                redrawUpload(file.attachment);
            }
        },
        undefined,
        LAST
    );

    // The counts moved, whether or not a directory was dropped: a file went
    // into a folder, or into Unassigned.
    up.bind('UploadComplete', () => {
        hooks.changed?.();
    });
}
