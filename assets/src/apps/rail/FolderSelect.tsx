/**
 * The "All folders" select in WordPress's own filter row — screens 03, 06, 11.
 *
 * ## Why a native select, and why two of them
 *
 * The handoff calls it "WP's own filter-row control, so it degrades to a plain
 * indented <select> when the rail is collapsed or scripts fail". In list mode
 * that is not a figure of speech: the filter row lives inside `#posts-filter`,
 * a GET form with a Filter button, so a server-rendered `<select name=
 * "folderfolio_folder">` filters the library with no JavaScript at all. That
 * one is printed by Admin\FolderSelect, and `useNativeFolderSelect` below
 * wires it to the store so that clicking it is instant rather than a form
 * submit.
 *
 * Grid mode has no server-rendered toolbar to enhance — the whole frame is
 * built by Backbone after load — so there `FolderSelect` renders the same
 * control in React, through the toolbar slot.
 *
 * Two renderings of one control is a real cost, and it buys the thing that
 * matters: list mode keeps working when scripts do not, and the select's
 * selected option comes back correct from the server on every list refresh
 * without this file being involved.
 *
 * ## Indented, not slashed
 *
 * `Brand / Logos / Primary` is how screen 03 draws the closed control, but the
 * note beside screen 11 is explicit that paths are indented rather than
 * concatenated, and an `<option>` has exactly one label for both states. The
 * indent is the instruction; the full path is already on screen, larger, in
 * the breadcrumb directly above.
 *
 * ## …but not while the control is closed
 *
 * An `<option>`'s single label is the reason the indent leaked: pick a folder
 * three levels down and the closed control showed nine non-breaking spaces of
 * nothing before the name, which reads as a control that has lost its value.
 * The indent means "this folder is inside that one", and that sentence only
 * has a referent while the list is open and the other folders are visible.
 *
 * So the selected option's indent is dropped while the list is shut and put
 * back before it opens. `mousedown` and `keydown` both fire before the popup
 * is painted, which is what makes it safe to rewrite the label there.
 */

import { useEffect, useState } from 'react';

import { flattenTree, useLibraryCounts, type FolderNode } from './queries';
import { sortTree, useRail } from './store';
import { t } from '../../core/api';
import { FOLDER_QUERY_VAR } from '../../lib/filter';

/** The three-way selection as an option value. `null` is the empty string. */
function toValue(id: number | null): string {
    return id === null ? '' : String(id);
}

/** …and back. An empty value is no filter; `0` is the Unassigned row. */
function fromValue(value: string): number | null {
    if (value === '') {
        return null;
    }

    const parsed = Number.parseInt(value, 10);

    return Number.isNaN(parsed) ? null : parsed;
}

/** Three non-breaking spaces per level. Wide enough to read, narrow enough
 *  that depth 5 still leaves a name. */
function indent(depth: number): string {
    return '   '.repeat(depth);
}

/**
 * Name, then the count in brackets, separated by an em quad — the closest a
 * native option gets to the right-aligned figure screen 11 draws.
 *
 * The brackets are load-bearing: without them `Archive 29 0` gives a reader no
 * way to tell the folder's number from the library's. `Admin\FolderSelect`
 * prints the same shape, and so does wp-admin's own category dropdown.
 */
function label(name: string, depth: number, count: number): string {
    return `${indent(depth)}${name} (${count})`;
}

export function FolderSelect({ nodes }: { nodes: FolderNode[] }) {
    const selectedId = useRail((s) => s.selectedId);
    const select = useRail((s) => s.select);
    const sort = useRail((s) => s.sort);
    const { data: counts } = useLibraryCounts();

    // Whether the list is being looked at, which is the only time the indent
    // says anything. Both events that open a native select fire before it
    // paints, so the labels are already right by the time it does.
    const [open, setOpen] = useState(false);

    const rows = flattenTree(sortTree(nodes, sort));

    /** A row's indent, unless it is the one showing in a shut control. */
    const depthOf = (id: number | null, depth: number): number =>
        !open && id === selectedId ? 0 : depth;

    return (
        <>
            <label className="screen-reader-text" htmlFor="folderfolio-folder-filter">
                {t('filterByFolder', 'Filter by folder')}
            </label>

            <select
                id="folderfolio-folder-filter"
                className={
                    'folderfolio-folder-select'
                    + (selectedId === null ? '' : ' is-active')
                }
                value={toValue(selectedId)}
                onChange={(event) => {
                    select(fromValue(event.target.value));
                    setOpen(false);
                }}
                onMouseDown={() => setOpen(true)}
                onKeyDown={() => setOpen(true)}
                onBlur={() => setOpen(false)}
            >
                <option value="">
                    {label(t('allMedia', 'All media'), 0, counts?.all ?? 0)}
                </option>
                <option value="0">
                    {label(t('unassigned', 'Unassigned'), 0, counts?.unassigned ?? 0)}
                </option>

                {rows.map(({ node, depth }) => (
                    <option key={node.id} value={node.id}>
                        {label(node.name, depthOf(node.id, depth + 1), node.total_count)}
                    </option>
                ))}
            </select>
        </>
    );
}

/** The indent `label()` writes and `Admin\FolderSelect::label()` prints. */
const INDENT = /^\u00a0+/;

/**
 * The indent, on or off, for the select Admin\FolderSelect prints.
 *
 * That one is not ours to render, so its labels are rewritten in place. The
 * indented label is printed into `data-folderfolio-indented`, which means
 * restoring it is exact rather than a reconstruction — and means a select that
 * has just come back from the server on a list refresh is already correct
 * without this having run at all, because PHP prints the selected row
 * un-indented.
 */
function setIndentVisible(select: HTMLSelectElement, visible: boolean): void {
    const selected = select.selectedOptions[0];

    for (const option of select.options) {
        const indented = option.dataset.folderfolioIndented;

        if (indented === undefined) {
            // All media and Unassigned, which have no indent to hide.
            continue;
        }

        const next = visible || option !== selected ? indented : indented.replace(INDENT, '');

        if (option.text !== next) {
            option.text = next;
        }
    }
}

/**
 * The server-rendered select in list mode.
 *
 * Two directions, and both are needed:
 *
 *  - the user picks a folder in it, and the store has to hear about it, or the
 *    rail's highlight, the breadcrumb and the cards all disagree with the
 *    library they are sitting next to;
 *  - the user picks a folder in the rail, and the select has to follow. The
 *    list refresh does eventually bring back a correctly-selected copy from
 *    the server, but not for the few hundred milliseconds the request takes,
 *    and a filter control showing the wrong folder is worse than a slow one.
 *
 * The listener is delegated on the document rather than bound to the element,
 * because the element is replaced on every one of those refreshes.
 */
export function useNativeFolderSelect() {
    const selectedId = useRail((s) => s.selectedId);
    const select = useRail((s) => s.select);

    useEffect(() => {
        const ours = (event: Event): HTMLSelectElement | null => {
            const target = event.target;

            return target instanceof HTMLSelectElement && target.name === FOLDER_QUERY_VAR
                ? target
                : null;
        };

        const onChange = (event: Event) => {
            const el = ours(event);

            if (el) {
                setIndentVisible(el, false);
                select(fromValue(el.value));
            }
        };

        // Both fire before the popup paints, which is what makes rewriting the
        // labels here safe rather than a flicker.
        const onOpen = (event: Event) => {
            const el = ours(event);

            if (el) {
                setIndentVisible(el, true);
            }
        };

        // focusout rather than blur: it is the bubbling one, so it reaches a
        // listener on the document without a capture pass.
        const onLeave = (event: Event) => {
            const el = ours(event);

            if (el) {
                setIndentVisible(el, false);
            }
        };

        document.addEventListener('change', onChange);
        document.addEventListener('mousedown', onOpen);
        document.addEventListener('keydown', onOpen);
        document.addEventListener('focusout', onLeave);

        return () => {
            document.removeEventListener('change', onChange);
            document.removeEventListener('mousedown', onOpen);
            document.removeEventListener('keydown', onOpen);
            document.removeEventListener('focusout', onLeave);
        };
    }, [select]);

    useEffect(() => {
        document
            .querySelectorAll<HTMLSelectElement>(`select[name="${FOLDER_QUERY_VAR}"]`)
            .forEach((el) => {
                const value = toValue(selectedId);

                // Only when the option exists: a folder created since the page
                // was rendered is not in the server's copy yet, and setting a
                // value a select does not have silently clears it to the first
                // option — which would read as "All media" for a folder that
                // is, in fact, being shown.
                if ([...el.options].some((option) => option.value === value)) {
                    el.value = value;
                }

                // The selected row changed, so the row that must lose its
                // indent changed with it.
                setIndentVisible(el, false);

                el.classList.toggle('is-active', selectedId !== null);
            });
    }, [selectedId]);
}
