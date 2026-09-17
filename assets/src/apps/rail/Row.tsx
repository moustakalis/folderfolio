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

export interface RowProps {
    node: FolderNode;
    depth: number;
    expanded: boolean;
    selected: boolean;
    focused: boolean;
    onSelect: () => void;
    onToggle: () => void;
    /** The child group, when this row is expanded. Rendered inside the <li>. */
    children?: React.ReactNode;
}

export function Row({
    node,
    depth,
    expanded,
    selected,
    focused,
    onSelect,
    onToggle,
    children,
}: RowProps) {
    const ref = useRef<HTMLDivElement>(null);
    const hasChildren = node.children.length > 0;

    // The tree is a single tab stop, so focus is moved rather than tabbed to.
    // Only ever when this row is the focused one *and* focus is already inside
    // the tree — otherwise arrowing would steal focus from the search field.
    useEffect(() => {
        const el = ref.current;

        if (!focused || !el) {
            return;
        }

        const active = document.activeElement;

        if (active && el.parentElement?.closest('[role="tree"]')?.contains(active)) {
            el.focus();
        }
    }, [focused]);

    return (
        <li role="none">
            <div
                ref={ref}
                className={`folderfolio-row${hasChildren ? '' : ' folderfolio-row--leaf'}`}
                role="treeitem"
                aria-level={depth + 1}
                aria-selected={selected}
                aria-expanded={hasChildren ? expanded : undefined}
                // Roving tabindex: exactly one row is reachable by Tab, and
                // the arrow keys move which one that is.
                tabIndex={focused ? 0 : -1}
                style={
                    {
                        '--ff-depth': depth,
                        ...(node.color ? { '--ff-folder': node.color } : {}),
                    } as React.CSSProperties
                }
                onClick={onSelect}
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

                <span className="folderfolio-row__name">{node.name}</span>

                {/*
                  The subtree total, not the folder's own count. A parent whose
                  files all live in its children reads 0 in every competitor
                  tested, which anyone would take to mean empty.
                */}
                <span className="folderfolio-row__count">{node.total_count}</span>
            </div>

            {children}
        </li>
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
