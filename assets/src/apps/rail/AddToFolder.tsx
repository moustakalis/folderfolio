/**
 * Bulk "Add to folder" — the trigger in WordPress's toolbar, and the flyout
 * drawn on screen 11.
 *
 * ## Adds, never moves
 *
 * Checkboxes, not radios, and the footer says so in words: "Adds a copy of the
 * membership". A file can be in several folders, so filing it into Campaigns
 * says nothing about whether it is also in Brand — and this control cannot
 * show what it would be removing, because the folders a file is already in are
 * not on screen here. Taking something away is the drag's job, where the
 * folder being dragged out of is the one you are looking at.
 *
 * ## Where it lives
 *
 * In core's toolbar, through lib/toolbar-slot.ts, because that toolbar is
 * rebuilt underneath us in both modes. The flyout itself is portaled to the
 * body and positioned against the trigger: the toolbar is a flex row with its
 * own overflow in list mode, and a 300px panel inside it would be clipped.
 */

import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

import { ChevronDownIcon, SearchIcon } from './icons';
import { flattenTree, useAddToFolders, type FolderNode } from './queries';
import { sortTree, useRail } from './store';
import { watchSelection } from '../../lib/selection';
import { t, tn } from '../../core/api';

export function AddToFolder({ nodes }: { nodes: FolderNode[] }) {
    const [ids, setIds] = useState<number[]>([]);
    const [open, setOpen] = useState(false);
    const triggerRef = useRef<HTMLButtonElement>(null);

    useEffect(() => watchSelection(setIds), []);

    /**
     * Losing the selection closes the flyout.
     *
     * Otherwise deselecting everything behind an open panel leaves a panel
     * whose header says "0 files selected" and whose button does nothing —
     * a dead end the user has to find their own way out of.
     */
    useEffect(() => {
        if (ids.length === 0) {
            setOpen(false);
        }
    }, [ids.length]);

    return (
        <>
            <button
                ref={triggerRef}
                type="button"
                className={`button folderfolio-bulk${open ? ' is-open' : ''}`}
                disabled={ids.length === 0}
                aria-haspopup="dialog"
                aria-expanded={open}
                onClick={() => setOpen((was) => !was)}
            >
                {t('addToFolder', 'Add to folder')}
                <ChevronDownIcon size={13} />
            </button>

            {open ? (
                createPortal(
                    <Flyout
                        nodes={nodes}
                        ids={ids}
                        anchor={triggerRef.current}
                        onClose={() => {
                            setOpen(false);
                            triggerRef.current?.focus();
                        }}
                    />,
                    document.body
                )
            ) : null}
        </>
    );
}

/**
 * The header sentence, with the count emphasised wherever it falls.
 *
 * `t()` fills %s positionally, so the count has to be located after the fact.
 * Splitting on the rendered number would match a digit elsewhere in the
 * sentence; splitting the *template* on its placeholder cannot.
 */
function headParts(count: number): React.ReactNode {
    const template = tn(
        'fileSelected',
        'filesSelected',
        count,
        '%s file selected',
        '%s files selected',
        count
    );
    // tn() has already filled the placeholder, so the number is in the string.
    // Located by searching for the rendered number rather than by splitting the
    // template, because the two calls would otherwise have to agree on which
    // key was picked.
    const rendered = String(count);
    const at = template.indexOf(rendered);

    if (at === -1) {
        return template;
    }

    return (
        <>
            {template.slice(0, at)}
            <strong>{rendered}</strong>
            {template.slice(at + rendered.length)}
        </>
    );
}

interface FlyoutProps {
    nodes: FolderNode[];
    ids: number[];
    anchor: HTMLElement | null;
    onClose: () => void;
}

function Flyout({ nodes, ids, anchor, onClose }: FlyoutProps) {
    const sort = useRail((s) => s.sort);
    const [checked, setChecked] = useState<ReadonlySet<number>>(new Set());
    const [query, setQuery] = useState('');
    const add = useAddToFolders();
    const ref = useRef<HTMLDivElement>(null);

    const rows = flattenTree(sortTree(nodes, sort));
    const needle = query.trim().toLowerCase();
    const shown = needle === ''
        ? rows
        : rows
            .filter((row) => row.node.name.toLowerCase().includes(needle))
            // A filtered list is not a tree any more — the parents that gave
            // the indent its meaning are not all there. Flattening it is
            // honest; keeping the indent would draw a hierarchy that is not
            // on screen.
            .map((row) => ({ ...row, depth: 0 }));

    const toggle = useCallback((id: number) => {
        setChecked((was) => {
            const next = new Set(was);

            if (!next.delete(id)) {
                next.add(id);
            }

            return next;
        });
    }, []);

    /**
     * Pinned to the trigger, in viewport coordinates.
     *
     * Measured in a layout effect so the panel is never painted at 0,0 first.
     * Flipped to the right edge of the trigger when the left-aligned position
     * would run off screen, which it does in list mode on a narrow window
     * where the bulk group sits close to the right of a shrunken content
     * column.
     */
    const [at, setAt] = useState<{ top: number; left: number } | null>(null);

    useLayoutEffect(() => {
        if (!anchor) {
            return;
        }

        const place = () => {
            const box = anchor.getBoundingClientRect();
            const width = ref.current?.offsetWidth ?? 300;
            const left = Math.max(8, Math.min(box.left, window.innerWidth - width - 8));

            setAt({ top: box.bottom + 2, left });
        };

        place();

        window.addEventListener('resize', place);
        window.addEventListener('scroll', place, true);

        return () => {
            window.removeEventListener('resize', place);
            window.removeEventListener('scroll', place, true);
        };
    }, [anchor]);

    /** Escape, a click outside, and focus leaving — see Toolbar's Menu. */
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.stopPropagation();
                onClose();
            }
        };

        const onPointer = (event: PointerEvent) => {
            const target = event.target as Node;

            if (!ref.current?.contains(target) && !anchor?.contains(target)) {
                onClose();
            }
        };

        document.addEventListener('keydown', onKey, true);
        document.addEventListener('pointerdown', onPointer, true);

        return () => {
            document.removeEventListener('keydown', onKey, true);
            document.removeEventListener('pointerdown', onPointer, true);
        };
    }, [onClose, anchor]);

    const submit = () => {
        if (checked.size === 0 || add.isPending) {
            return;
        }

        const folderIds = [...checked];

        add.mutate(
            { ids, folderIds },
            {
                onSuccess: () => {
                    /*
                     * Named, not counted.
                     *
                     * "Added 3 files to Brand, Campaigns" tells someone who
                     * cannot see the tree badges update exactly what happened;
                     * "to 2 folders" tells them a number they would then have
                     * to go and check. It also leaves the sentence with one
                     * plural instead of two, which is the difference between a
                     * translatable string and a combinatorial one.
                     */
                    const names = rows
                        .filter((row) => folderIds.includes(row.node.id))
                        .map((row) => row.node.name)
                        .join(', ');

                    window.wp?.a11y?.speak(
                        tn(
                            'addedFile',
                            'addedFiles',
                            ids.length,
                            'Added %s file to %s',
                            'Added %s files to %s',
                            ids.length,
                            names
                        ),
                        'polite'
                    );
                    onClose();
                },
            }
        );
    };

    return (
        <div
            ref={ref}
            className="folderfolio folderfolio-flyout"
            role="dialog"
            aria-label={t('addToFolder', 'Add to folder')}
            style={{
                top: at ? `${at.top}px` : '-9999px',
                left: at ? `${at.left}px` : '-9999px',
            }}
        >
            {/*
              "**3 files** selected", with the count in bold — screen 11 draws
              it that way because the number is the one thing worth checking
              before filing anything, and the selection itself may be scrolled
              off behind the panel.

              Built by splitting the translated string on its own placeholder
              rather than by concatenating two fragments, so a translation is
              free to put the number anywhere in the sentence.
            */}
            <p className="folderfolio-flyout__head">
                {headParts(ids.length)}
            </p>

            <div className="folderfolio-flyout__search">
                <SearchIcon size={13} />
                <input
                    type="search"
                    className="folderfolio-flyout__input folderfolio-flyout__input"
                    // Doubled class above is not a typo: core styles
                    // input[type="search"] at specificity 0,1,1, which beats a
                    // single class. See _rail-chrome.css, same trap.
                    placeholder={t('findFolder', 'Find a folder')}
                    aria-label={t('findFolder', 'Find a folder')}
                    value={query}
                    autoFocus
                    onChange={(event) => setQuery(event.target.value)}
                />
            </div>

            <div className="folderfolio-flyout__list">
                {shown.length === 0 ? (
                    <p className="folderfolio-flyout__empty">
                        {needle === ''
                            ? t('emptyTree', 'No folders yet')
                            : t('noMatch', 'No folder matches “%s”.', query.trim())}
                    </p>
                ) : (
                    shown.map(({ node, depth }) => (
                        <label
                            key={node.id}
                            className="folderfolio-flyout__row"
                            style={{ '--ff-depth': depth } as React.CSSProperties}
                        >
                            <input
                                type="checkbox"
                                checked={checked.has(node.id)}
                                onChange={() => toggle(node.id)}
                            />
                            <span className="folderfolio-flyout__name">{node.name}</span>
                        </label>
                    ))
                )}
            </div>

            <div className="folderfolio-flyout__foot">
                <button
                    type="button"
                    className="button button-primary"
                    disabled={checked.size === 0 || add.isPending}
                    onClick={submit}
                >
                    {t('addToFolder', 'Add to folder')}
                </button>

                <span className="folderfolio-flyout__note">
                    {add.isError
                        ? t('addFailed', 'Could not file those files.')
                        : t('addsACopy', 'Adds a copy of the membership')}
                </span>
            </div>
        </div>
    );
}
