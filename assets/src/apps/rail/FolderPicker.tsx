/**
 * The folder filter, below the breakpoint: a button and a searchable list.
 *
 * ## Why the native select had to go down here
 *
 * The narrow layout's premise, written into FilterDisclosure.tsx, was that
 * navigation never needed the rail at this width because the folder select on
 * the toolbar carries the whole tree. Measured against 1,050 folders that
 * select holds **1,055 options and 24,027 characters** — a native picker a
 * thousand rows long, whose four levels of nesting are expressed as
 * non-breaking spaces and whose only way to reach row 900 is to keep
 * scrolling. True at three folders; false at a thousand.
 *
 * So below the breakpoint the control becomes a button that opens the same
 * kind of panel the bulk `Add to folder` flyout uses: a search field, a capped
 * list, and a line that says how many rows the cap is holding back. Search is
 * the strongest thing in the build at this size — 290ms against the same
 * fixture, results carrying their full path — and this puts it where the
 * filter already was.
 *
 * ## Why the select is still rendered
 *
 * Two reasons, and they are different in the two modes.
 *
 * In **list** mode the select is printed by `Admin\FolderSelect` into
 * `#posts-filter`, a GET form, which is what filters the library when scripts
 * fail. Removing it would take the no-script path with it. So it stays in the
 * document and CSS hides it — and the rule that hides it is gated on a body
 * class *this component sets*, so with scripts off nothing hides anything and
 * the native select is still the control.
 *
 * In **grid** mode there is no no-script path to protect, but the width that
 * decides this belongs to a container query, which script cannot read. Same
 * reasoning as the disclosure button: render both, let CSS choose. The cost is
 * that grid carries the select's options in the DOM at a width where they are
 * never shown. That is the status quo rather than a regression, and the honest
 * fix for it is E — a slimmer tree payload — not a guess at the width here.
 *
 * ## Why it does not reuse the bulk flyout's component
 *
 * It reuses its mechanics — `useAnchoredPanel` — and its styles, and the same
 * cap. What it does not share is the body: that panel picks *many* folders and
 * then performs a verb on a selection; this one picks *one* and changes what
 * the library is showing, immediately, with no confirm step. Same furniture,
 * different sentence.
 */

import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

import { FolderIcon, SearchIcon } from './icons';
import {
    flattenTree,
    searchTree,
    useLibraryCounts,
    type FolderNode,
} from './queries';
import { sortTree, useRail } from './store';
import { useAnchoredPanel } from './useAnchoredPanel';
import { t } from '../../core/api';

/**
 * How many rows the list renders at once.
 *
 * The same 100 the bulk flyout uses, for the same reasons written there: a
 * page of a list rather than all of it, capped rather than windowed, because
 * a row that is not in the DOM cannot be reached with Tab.
 */
const LIST_LIMIT = 100;

/**
 * The class the "hide the native select" rule is gated on.
 *
 * On <body> and set from script, which is the whole point: with scripts
 * off this class never appears, the rule never matches, and the
 * server-rendered select in `#posts-filter` is still the control that
 * filters the library. A rule keyed on the container query alone would
 * hide it for everyone, including the people it exists for.
 */
const HAS_PICKER = 'folderfolio-has-folder-picker';

/** A row in the panel, whether it came from the tree or from a search. */
interface Row {
    id: number | null;
    name: string;
    /** Indent when browsing; the path when searching. Never both. */
    depth: number;
    trail: string[] | null;
    count: number;
}

export function FolderPicker({ nodes }: { nodes: FolderNode[] }) {
    const [open, setOpen] = useState(false);
    const selectedId = useRail((s) => s.selectedId);
    const triggerRef = useRef<HTMLButtonElement>(null);

    // Owned here and removed on unmount: a frame that tears this down must
    // not leave the select hidden with nothing standing in for it.
    useEffect(() => {
        document.body.classList.add(HAS_PICKER);

        return () => document.body.classList.remove(HAS_PICKER);
    }, []);

    /*
     * The trigger says which folder the library is showing.
     *
     * Not "Filter by folder" with the answer hidden inside: at this width this
     * button is the only thing on screen that names the folder, because the
     * breadcrumb belongs to the rail band and the rail band may be collapsed.
     */
    const current = useMemo(() => {
        if (selectedId === null) {
            return t('allMedia', 'All media');
        }

        if (selectedId === 0) {
            return t('unassigned', 'Unassigned');
        }

        return (
            flattenTree(nodes).find((row) => row.node.id === selectedId)?.node.name
            ?? t('allMedia', 'All media')
        );
    }, [nodes, selectedId]);

    return (
        <>
            <button
                ref={triggerRef}
                type="button"
                className={
                    'button folderfolio-folder-picker'
                    + (selectedId === null ? '' : ' is-active')
                    + (open ? ' is-open' : '')
                }
                aria-haspopup="dialog"
                aria-expanded={open}
                onClick={() => setOpen((was) => !was)}
            >
                <FolderIcon size={14} />
                <span className="folderfolio-folder-picker__label">{current}</span>
            </button>

            {open
                ? createPortal(
                      <Panel
                          nodes={nodes}
                          anchor={triggerRef.current}
                          onClose={() => {
                              setOpen(false);
                              triggerRef.current?.focus();
                          }}
                      />,
                      document.body
                  )
                : null}
        </>
    );
}

interface PanelProps {
    nodes: FolderNode[];
    anchor: HTMLElement | null;
    onClose: () => void;
}

function Panel({ nodes, anchor, onClose }: PanelProps) {
    const sort = useRail((s) => s.sort);
    const selectedId = useRail((s) => s.selectedId);
    const select = useRail((s) => s.select);
    const { data: counts } = useLibraryCounts();
    const [query, setQuery] = useState('');
    const { ref, style } = useAnchoredPanel<HTMLDivElement>(anchor, onClose);

    const needle = query.trim().toLowerCase();

    // Sorting and flattening the whole tree does not depend on what is typed,
    // so it must not be paid per keystroke — the same measurement that shaped
    // the bulk flyout.
    const browsing = useMemo(
        (): Row[] =>
            flattenTree(sortTree(nodes, sort)).map(({ node, depth }) => ({
                id: node.id,
                name: node.name,
                depth,
                trail: null,
                count: node.total_count,
            })),
        [nodes, sort]
    );

    const searching = useMemo(
        (): Row[] =>
            needle === ''
                ? []
                : searchTree(nodes, needle).map(({ node, trail }) => ({
                      id: node.id,
                      name: node.name,
                      // A search result is not a tree any more — the parents
                      // that gave the indent its meaning are not all on
                      // screen. The path is shown instead, in words.
                      depth: 0,
                      trail,
                      count: node.total_count,
                  })),
        [nodes, needle]
    );

    /*
     * All media and Unassigned lead the list, and are searchable like any
     * other row.
     *
     * They are how you get *out* of a folder, which is the one thing a person
     * who has filtered themselves into a corner needs, and the one thing a cap
     * must never hide. Leading the list puts them above it by construction.
     */
    const fixed: Row[] = useMemo(() => {
        const rows: Row[] = [
            {
                id: null,
                name: t('allMedia', 'All media'),
                depth: 0,
                trail: null,
                count: counts?.all ?? 0,
            },
            {
                id: 0,
                name: t('unassigned', 'Unassigned'),
                depth: 0,
                trail: null,
                count: counts?.unassigned ?? 0,
            },
        ];

        return needle === ''
            ? rows
            : rows.filter((row) => row.name.toLowerCase().includes(needle));
    }, [counts, needle]);

    const matches = needle === '' ? browsing : searching;
    const shown = matches.slice(0, LIST_LIMIT);
    const hidden = matches.length - shown.length;

    const rows = [...fixed, ...shown];

    const choose = (id: number | null) => {
        select(id);
        onClose();
    };

    return (
        <div
            ref={ref}
            className="folderfolio folderfolio-flyout folderfolio-flyout--picker"
            role="dialog"
            aria-label={t('filterByFolder', 'Filter by folder')}
            style={style}
        >
            <p className="folderfolio-flyout__head">
                {t('filterByFolder', 'Filter by folder')}
            </p>

            <div className="folderfolio-flyout__search">
                <SearchIcon size={13} />
                <input
                    type="search"
                    // Doubled class is not a typo: core styles
                    // input[type="search"] at 0,1,1, which beats a single
                    // class. Same trap as the bulk flyout and _rail-chrome.css.
                    className="folderfolio-flyout__input folderfolio-flyout__input"
                    placeholder={t('searchPlaceholder', 'Search folders')}
                    aria-label={t('searchPlaceholder', 'Search folders')}
                    value={query}
                    autoFocus
                    onChange={(event) => setQuery(event.target.value)}
                />
            </div>

            {/*
              A listbox rather than a menu: these are the values of one
              control, one of which is currently true, which is what
              `aria-selected` on a listbox says and what a menu has no way to.
            */}
            <div
                className="folderfolio-flyout__list"
                role="listbox"
                aria-label={t('filterByFolder', 'Filter by folder')}
            >
                {rows.length === 0 ? (
                    <p className="folderfolio-flyout__empty">
                        {t('noMatch', 'No folder matches “%s”.', query.trim())}
                    </p>
                ) : (
                    rows.map((row) => (
                        <button
                            key={row.id === null ? 'all' : row.id}
                            type="button"
                            role="option"
                            aria-selected={row.id === selectedId}
                            className={
                                'folderfolio-flyout__pick'
                                + (row.id === selectedId ? ' is-on' : '')
                            }
                            style={{ '--ff-depth': row.depth } as React.CSSProperties}
                            onClick={() => choose(row.id)}
                        >
                            <span className="folderfolio-flyout__picktext">
                                <span className="folderfolio-flyout__name">{row.name}</span>
                                {row.trail === null ? null : (
                                    <span className="folderfolio-flyout__path">
                                        {row.trail.length === 0
                                            ? t('atTopLevel', 'Top level')
                                            : row.trail.join(' / ')}
                                    </span>
                                )}
                            </span>
                            <span className="folderfolio-flyout__count">{row.count}</span>
                        </button>
                    ))
                )}

                {/* Never a silent cap — see the bulk flyout. */}
                {hidden > 0 ? (
                    <p className="folderfolio-flyout__more">
                        {t('andMoreFolders', '%s more — keep typing to narrow', hidden)}
                    </p>
                ) : null}
            </div>
        </div>
    );
}
