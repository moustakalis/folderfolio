/**
 * "or select a folder" — tier 2 item 9's second door, option B on board
 * `UzMC1qdGkxa2JQckXu65tW` (Nick's pick, 23 Sep).
 *
 * Folder upload shipped drag-only: moxie reads a dropped directory and marks
 * each file's `relativePath`, and core/folder-upload.ts makes the folders.
 * A person who does not drag had no way in. This puts one link under core's
 * *Select Files* in its upload panel — the library grid's and the picker's
 * *Upload files* tab are the same view, `wp.media.view.UploaderInline` — that
 * opens the system's folder chooser and hands the files to the same uploader,
 * marked the same way a drop marks them. From there it is the drop's exact
 * path: planned, held, filed by id.
 *
 * ## Why a link and not a second button
 *
 * Core's panel has one primary action and most uploads are files. A second
 * hero button (option A) doubled it for the rarer path; a ⋮ row (option C)
 * could never be reached by a role that may create folders but has no ⋮ —
 * Author, by default. A link is found by anyone who looks for it and costs the
 * panel one line.
 *
 * ## Into core's markup, additively
 *
 * `UploaderInline.prototype.ready` is wrapped — core's runs first, always —
 * and the link is added after the panel's `.browser` button once. Panels that
 * rendered before this bundle ran are found in the document on install.
 * Shown only to someone who may create folders, and only where the browser
 * can choose a directory at all.
 *
 * ## Which uploader
 *
 * Found at click time, not render time: the panel's `.browser` button is the
 * element a wp.Uploader was built around (`uploader.browser[0]`), so the
 * uploader that owns this panel is the live one whose browser button is this
 * one. `find` is core/upload-target.ts's list of live uploaders.
 */

import { t } from './api';
import { can } from '../lib/can';

const CLASS = 'folderfolio-select-folder';
const MARK = 'folderfolioSelectFolder';

type Find = (browser: Element) => WpUploader | undefined;

interface MoxieFile {
    relativePath?: string;
}

interface MoxieGlobal {
    File: new (ruid: null, file: File) => MoxieFile;
}

function supported(): boolean {
    return 'webkitdirectory' in document.createElement('input');
}

/**
 * The files a folder chooser returned, as moxie files marked the way a drop
 * marks them: `/Brand/Logos/a.png`, from the chooser's
 * `webkitRelativePath` — `Brand/Logos/a.png`, the chosen folder first.
 */
function marked(files: FileList): MoxieFile[] {
    const moxie = (window as unknown as { mOxie?: MoxieGlobal }).mOxie;

    if (!moxie) {
        return [];
    }

    return Array.from(files).map((file) => {
        const entry = new moxie.File(null, file);
        entry.relativePath = '/' + file.webkitRelativePath.replace(/^\/+/, '');

        return entry;
    });
}

function enhance(panel: Element, find: Find): void {
    const browser = panel.querySelector('.upload-ui .browser');

    if (!browser || panel.querySelector(`.${CLASS}`)) {
        return;
    }

    const line = document.createElement('p');
    // `folderfolio` for the tokens: they are scoped to that class, and the
    // link's ink is --ff-accent-text rather than core's scheme link colour,
    // which is not dark enough on this grey in every scheme (_uploader.css).
    line.className = `folderfolio ${CLASS}`;

    const input = document.createElement('input');
    input.type = 'file';
    input.multiple = true;
    input.hidden = true;
    input.tabIndex = -1;
    input.setAttribute('aria-hidden', 'true');
    input.setAttribute('webkitdirectory', '');

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'button-link';
    button.textContent = t('selectFolder', 'or select a folder');

    // The panel's size line, as core's own button says it.
    const describedBy = browser.getAttribute('aria-describedby');

    if (describedBy) {
        button.setAttribute('aria-describedby', describedBy);
    }

    button.addEventListener('click', () => input.click());

    input.addEventListener('change', () => {
        const uploader = find(browser)?.uploader;
        const files = input.files ? marked(input.files) : [];

        if (uploader && files.length > 0) {
            (uploader as unknown as { addFile(files: MoxieFile[]): void }).addFile(files);
        }

        // Choosing the same folder twice must fire `change` twice.
        input.value = '';
    });

    line.append(button, input);
    browser.insertAdjacentElement('afterend', line);
}

function scan(find: Find): void {
    document.querySelectorAll('.uploader-inline').forEach((panel) => enhance(panel, find));
}

export function installSelectFolder(find: Find): void {
    if (!can('create') || !supported()) {
        return;
    }

    const view = (
        window.wp as unknown as
            | { media?: { view?: { UploaderInline?: { prototype: Record<string, unknown> } } } }
            | undefined
    )?.media?.view?.UploaderInline;

    if (view) {
        const proto = view.prototype;
        const original = proto.ready as ((...args: unknown[]) => unknown) | undefined;

        if (typeof original === 'function' && !(original as unknown as Record<string, unknown>)[MARK]) {
            const wrapped = function (this: { el?: Element }, ...args: unknown[]): unknown {
                const result = original.apply(this, args);

                if (this.el) {
                    enhance(this.el, find);
                }

                return result;
            };

            (wrapped as unknown as Record<string, unknown>)[MARK] = true;
            proto.ready = wrapped;
        }
    }

    scan(find);

    if (document.readyState !== 'complete') {
        window.addEventListener('load', () => scan(find), { once: true });
    }
}
