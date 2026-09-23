/**
 * Which folders a dropped directory needs — tier 2 item 9, the pure half.
 *
 * Dropping a folder from the desktop onto the media library already uploads
 * every file in it: `moxie.js` walks `webkitGetAsEntry()` recursively and
 * puts each file's place in the tree on `relativePath` —
 * `/Brand/Logos/primary.png`. Core then throws that away and the files land
 * flat. This reads it back and answers two questions:
 *
 * - **which folders to make** — every directory that directly holds a file,
 *   once each, in the order first met. Its ancestors are implied: the bulk
 *   route's `getOrCreateByPath()` makes `Brand` on its way to `Brand/Logos`.
 * - **where each file goes** — an index into that list, `-1` for a file
 *   dropped loose beside the directory (it goes where a plain upload would),
 *   or `null` for one to leave behind.
 *
 * Litter is met twice. Most of it has no extension WordPress allows, so
 * plupload's `mime_types` filter refuses it before `FilesAdded` and the
 * uploader's `Error` handler has to keep core from listing it as a failure
 * (`isLitter()`); the rest — `__MACOSX/._photo.jpg` has an allowed extension
 * and is not a photo — reaches `FilesAdded` and is taken off the queue there.
 *
 * ## What is left behind
 *
 * Files the desktop hides and nobody means to upload — `.DS_Store`, anything
 * under `.git/`, `Thumbs.db`, `desktop.ini`, a zip's `__MACOSX/` — **but only
 * inside a dropped directory**. A dotfile dropped on its own was chosen by
 * name, so it is uploaded like anything else, and core refuses it or not.
 *
 * ## What cannot be seen
 *
 * An empty directory. The browser reports files; a directory with nothing in
 * it produces no entry at all, so no folder is made for it.
 */

export interface StructurePlan {
    /** Folder paths to make, relative to where the drop lands. */
    paths: string[];
    /** Per file: an index into `paths`, `-1` for a loose file, `null` to skip. */
    place: Array<number | null>;
}

/** Names the desktop makes and nobody drops on purpose. Compared lower-cased. */
const LITTER = new Set(['thumbs.db', 'desktop.ini', '__macosx']);

function isLitterSegment(segment: string): boolean {
    return segment.startsWith('.') || LITTER.has(segment.toLowerCase());
}

/**
 * The segments of a relative path, without empty ones.
 *
 * A newline inside a directory name is legal on every desktop file system and
 * would split one line of the bulk route's text into two folders. It is made
 * a space here, which is what `sanitize_text_field()` would have made of it on
 * the server anyway.
 */
function segmentsOf(relativePath: string): string[] {
    return relativePath
        .split('/')
        .map((segment) => segment.replace(/[\r\n]+/g, ' '))
        .filter((segment) => segment.trim() !== '');
}

/**
 * True for a file the plan leaves behind — litter inside a dropped directory.
 *
 * Asked by the uploader's `Error` handler as well as by `planStructure()`:
 * most litter has no extension WordPress accepts, so plupload's own
 * `mime_types` filter refuses it *before* `FilesAdded`, and core then lists
 * `.DS_Store` as a failed upload nobody asked for.
 */
export function isLitter(relativePath: string): boolean {
    const segments = segmentsOf(relativePath);

    return segments.length > 1 && segments.some(isLitterSegment);
}

/** True when a file came out of a dropped directory rather than loose. */
export function isInDirectory(relativePath: string): boolean {
    return segmentsOf(relativePath).length > 1;
}

export function planStructure(relativePaths: readonly string[]): StructurePlan {
    const paths: string[] = [];
    const index = new Map<string, number>();

    const place = relativePaths.map((relativePath): number | null => {
        const segments = segmentsOf(relativePath);

        if (segments.length < 2) {
            return -1;
        }

        if (segments.some(isLitterSegment)) {
            return null;
        }

        const path = segments.slice(0, -1).join('/');
        let at = index.get(path);

        if (at === undefined) {
            at = paths.length;
            paths.push(path);
            index.set(path, at);
        }

        return at;
    });

    return { paths, place };
}

/**
 * Split the paths into the bulk route's submissions.
 *
 * `Domain\FolderBulk` takes 500 lines at a time. A drop is not a paste nobody
 * reviewed, so a bigger one is sent in turns rather than refused — each turn
 * all-or-nothing on its own, and later turns find what earlier ones made.
 */
export function chunk<T>(items: readonly T[], size: number): T[][] {
    const out: T[][] = [];

    for (let at = 0; at < items.length; at += size) {
        out.push(items.slice(at, at + size));
    }

    return out;
}
