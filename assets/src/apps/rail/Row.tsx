/**
 * One folder row — design screen 02.
 *
 * Every measurement lives in _row.css; this file's job is to emit the markup
 * that stylesheet is written against and to get the ARIA right. The only
 * geometry it passes is `--ff-depth`, which the stylesheet cannot know.
 */

import { useEffect, useRef } from 'react';

import { ChevronDownIcon, ChevronRightIcon, FolderIcon, FolderOpenIcon } from './icons';
import type { FolderNode } from './queries';
import { useDropTarget } from './useDropTarget';
import { useRail } from './store';
import { t } from '../../core/api';
import { swatchStyle } from '../../lib/swatches';

export interface RowProps {
    node: FolderNode;
    depth: number;
    expanded: boolean;
    /** True while this row's name is an input. */
    renaming: boolean;
    /**
     * True for the first row in the tree while nothing is focused.
     *
     * The tree is a single tab stop, so exactly one row must be tabbable at
     * all times. Focus normally supplies it; this is what supplies it before
     * anyone has arrowed anywhere, and after the focused row is deleted.
     */
    fallbackTabStop: boolean;
    onSelect: () => void;
    onToggle: () => void;
    /** The child group, when this row is expanded. Rendered inside the <li>. */
    children?: React.ReactNode;
}

export function Row({
    node,
    depth,
    expanded,
    renaming,
    fallbackTabStop,
    onSelect,
    onToggle,
    children,
}: RowProps) {
    /*
     * Focus and selection are read here rather than handed down, and that is
     * a performance decision with a measurement behind it.
     *
     * When the tree owned both, one arrow key re-created every Row element in
     * the tree — 126ms per keystroke at 5,000 expanded rows, measured, which
     * is the point where the highlight visibly trails your finger. A Zustand
     * selector returning a boolean re-renders only the components whose
     * boolean actually changed, so moving focus now re-renders exactly two
     * rows whatever the size of the tree.
     *
     * The cost is that a Row can no longer be rendered outside the store.
     * Nothing renders one outside the store.
     */
    const focused = useRail((s) => s.focusedId === node.id);
    const selected = useRail((s) => s.selectedId === node.id);

    const ref = useRef<HTMLDivElement>(null);
    const hasChildren = node.children.length > 0;
    const drop = useDropTarget(node.id);

    // The tree is a single tab stop, so focus is moved rather than tabbed to.
    // Only ever when this row is the focused one *and* focus is already inside
    // the tree — otherwise arrowing would steal focus from the search field.
    // Never while renaming: the input owns focus then.
    useEffect(() => {
        const el = ref.current;

        if (!focused || renaming || !el) {
            return;
        }

        const active = document.activeElement;

        if (active && el.parentElement?.closest('[role="tree"]')?.contains(active)) {
            el.focus();
        }
    }, [focused, renaming]);

    return (
        <li role="none">
            <div
                ref={ref}
                className={
                    'folderfolio-row'
                    + (hasChildren ? '' : ' folderfolio-row--leaf')
                    + (renaming ? ' is-renaming' : '')
                    + (drop.isOver ? ' is-dragover' : '')
                }
                {...drop.handlers}
                role="treeitem"
                aria-level={depth + 1}
                aria-selected={selected}
                aria-expanded={hasChildren ? expanded : undefined}
                // Roving tabindex: exactly one row is reachable by Tab, and
                // the arrow keys move which one that is.
                tabIndex={(focused || fallbackTabStop) && !renaming ? 0 : -1}
                style={
                    {
                        '--ff-depth': depth,
                        ...swatchStyle(node.color),
                    } as React.CSSProperties
                }
                onClick={renaming ? undefined : onSelect}
            >
                {/*
                  One guide per ancestor level. Absolutely positioned by the
                  stylesheet from --ff-guide-i, so the indent and the switcher
                  size can change in the cramped variants without moving them.
                */}
                {Array.from({ length: depth }, (_, i) => (
                    <span
                        key={i}
                        className="folderfolio-row__guide"
                        style={{ '--ff-guide-i': i } as React.CSSProperties}
                    />
                ))}

                {/*
                  tabIndex -1 and aria-hidden: this is a pointer affordance for
                  something the treeitem already exposes through aria-expanded
                  and handles from the keyboard with Right, Left and Space. A
                  focusable button here would put a second tab stop inside a
                  composite widget.

                  A leaf keeps the slot and loses the chevron, so names never
                  jitter horizontally between rows that do and do not have
                  children.
                */}
                <button
                    type="button"
                    className={`folderfolio-row__switcher${hasChildren ? '' : ' folderfolio-row__switcher--leaf'}`}
                    tabIndex={-1}
                    aria-hidden="true"
                    onClick={(event) => {
                        event.stopPropagation();
                        onToggle();
                    }}
                >
                    {hasChildren
                        ? expanded
                            ? <ChevronDownIcon size={14} />
                            : <ChevronRightIcon size={14} />
                        : null}
                </button>

                <span className="folderfolio-row__icon">
                    {expanded && hasChildren ? <FolderOpenIcon /> : <FolderIcon />}
                </span>

                {renaming ? (
                    <NameInput label={t('renameFolder', 'Rename folder')} />
                ) : (
                    <>
                        <span className="folderfolio-row__name">{node.name}</span>

                        {/*
                          The subtree total, not the folder's own count. A
                          parent whose files all live in its children reads 0
                          in every competitor tested, which anyone would take
                          to mean empty.
                        */}
                        {/*
                          While files are hovering, the tag previews what the
                          folder will hold rather than what it holds — the
                          answer to "will this do what I think" given before
                          the drop rather than after it.
                        */}
                        <span className="folderfolio-row__count">
                            {drop.isOver ? `+${drop.incoming}` : node.total_count}
                        </span>
                    </>
                )}
            </div>

            {children}
        </li>
    );
}

/**
 * A row that is only an input: the folder being created.
 *
 * Sits where the folder will live rather than above the tree, so "inside
 * which folder?" is answered by position instead of by a sentence. Screen 05
 * draws it exactly here.
 */
export function CreateRow({ depth }: { depth: number }) {
    return (
        <li role="none">
            <div
                className="folderfolio-row is-renaming"
                style={{ '--ff-depth': depth } as React.CSSProperties}
            >
                {Array.from({ length: depth }, (_, i) => (
                    <span
                        key={i}
                        className="folderfolio-row__guide"
                        style={{ '--ff-guide-i': i } as React.CSSProperties}
                    />
                ))}

                <span className="folderfolio-row__switcher folderfolio-row__switcher--leaf" />

                <span className="folderfolio-row__icon">
                    <FolderIcon />
                </span>

                <NameInput label={t('newFolderName', 'Name for the new folder')} />
            </div>
        </li>
    );
}

/**
 * The input itself, shared by both — and by the narrow-width level view,
 * which has its own row markup but must not have its own editing mechanic.
 *
 * It reads and writes the store directly rather than taking props: there is
 * only ever one of these on screen — that is what a single `editing` slot in
 * the store means — and threading four callbacks through Tree and Row to
 * reach it would be ceremony around a fact the store already states.
 *
 * Save and Cancel are the toolbar-height buttons beside it, not blur. A field
 * that commits on blur loses what you typed the moment you reach for anything
 * else, and one that cancels on blur does the same in the other direction.
 */
export function NameInput({ label }: { label: string }) {
    const value = useRail((s) => s.editing?.value ?? '');
    const setEditValue = useRail((s) => s.setEditValue);
    const ref = useRef<HTMLInputElement>(null);

    useEffect(() => {
        ref.current?.focus();
        // Selected, not just focused: renaming usually replaces the whole name,
        // and when it does not, one arrow key undoes the selection.
        ref.current?.select();
    }, []);

    return (
        <input
            ref={ref}
            type="text"
            className="folderfolio-row__input"
            aria-label={label}
            value={value}
            onChange={(event) => setEditValue(event.target.value)}
            // Enter and Escape reach the tree's key handler by bubbling; it
            // owns every other key in the tree and ignores the rest while an
            // input has focus, so arrows and typing behave normally in here.
            onClick={(event) => event.stopPropagation()}
        />
    );
}

/**
 * The loading state: three ghost rows.
 *
 * A spinner says "something is happening somewhere"; ghosts say "folders are
 * arriving here", at the size they will arrive in, so nothing jumps when they
 * do. The widths vary because three identical bars read as a graphic rather
 * than as pending content.
 */
export function GhostRows() {
    return (
        <>
            {[62, 44, 71].map((width, i) => (
                <li role="none" key={i}>
                    <div
                        className="folderfolio-row folderfolio-row--ghost"
                        style={{ '--ff-ghost-w': `${width}%` } as React.CSSProperties}
                    >
                        <span className="folderfolio-row__switcher" />
                        <span className="folderfolio-row__ghost-icon" />
                        <span className="folderfolio-row__ghost-name" />
                    </div>
                </li>
            ))}
        </>
    );
}
