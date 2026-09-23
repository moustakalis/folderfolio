/**
 * The tree, and the keyboard.
 *
 * The handoff is specific: one tab stop, focus tracked apart from selection so
 * arrowing does not re-filter the library, and the full set of tree keys. All
 * of that needs a flat list of the rows currently on screen — "the next
 * visible row, crossing levels" is not a question a nested render can answer —
 * so the tree is flattened once per render and the key handler works on that.
 */

import { useCallback, useEffect, useMemo } from 'react';

import { CreateRow, Row, GhostRows } from './Row';
import { useReorderFolders, type FolderNode } from './queries';
import { usePaste } from './paste';
import { useFolderDrop } from './folder-drop';
import { planSiblingMove } from './move';
import { sortTree, useRail } from './store';
import { can } from '../../lib/can';
import { t } from '../../core/api';
import { EmptyTree } from './EmptyTree';

/**
 * The type-ahead buffer: 1s, as the handoff specifies.
 *
 * Module scope, not a ref. There is one tree on the page, and a buffer that
 * lives inside the component is reset by anything that remounts it — which is
 * how the first version of this behaved: each keystroke started a fresh
 * search, so typing "sc" jumped to the first C rather than matching nothing.
 * Nothing about "what the user typed in the last second" belongs to a React
 * instance.
 */
const typed = { text: '', at: 0 };

interface Visible {
    node: FolderNode;
    depth: number;
}

/** Is `id` anywhere beneath `node`? */
function contains(node: FolderNode, id: number): boolean {
    return node.children.some((child) => child.id === id || contains(child, id));
}

/** Depth-first, skipping anything inside a collapsed parent. */
function flatten(nodes: FolderNode[], expanded: Set<number>, depth = 0, out: Visible[] = []) {
    for (const node of nodes) {
        out.push({ node, depth });

        if (node.children.length > 0 && expanded.has(node.id)) {
            flatten(node.children, expanded, depth + 1, out);
        }
    }

    return out;
}

export interface TreeProps {
    nodes: FolderNode[];
    loading: boolean;
    onSaveEdit: () => void;
    onCancelEdit: () => void;
    onDelete: (node: FolderNode) => void;
}

export function Tree({ nodes, loading, onSaveEdit, onCancelEdit, onDelete }: TreeProps) {
    /*
     * Deliberately not subscribed to `focusedId` or `selectedId`.
     *
     * Either one would make this component re-render on every arrow key, and
     * re-rendering this component re-creates every Row element beneath it. The
     * rows read those two values themselves; see Row.tsx. What is left here is
     * a single boolean — whether anything is focused at all — which changes
     * twice in a session rather than on every keystroke, and which the roving
     * tabindex needs so that the tree is always reachable by Tab.
     */
    const nothingFocused = useRail((s) => s.focusedId === null);
    const expandedIds = useRail((s) => s.expandedIds);
    const editing = useRail((s) => s.editing);
    const sort = useRail((s) => s.sort);
    const select = useRail((s) => s.select);
    const focus = useRail((s) => s.focus);
    const toggle = useRail((s) => s.toggle);
    const expand = useRail((s) => s.expand);
    const collapse = useRail((s) => s.collapse);
    const edit = useRail((s) => s.edit);
    const setSort = useRail((s) => s.setSort);
    const reorder = useReorderFolders();
    const clipboard = usePaste();

    const ordered = useMemo(() => sortTree(nodes, sort), [nodes, sort]);
    const visible = useMemo(() => flatten(ordered, expandedIds), [ordered, expandedIds]);

    // Holds no React state on purpose — see folder-drop.ts. A marker in state
    // would re-render this component on every pointer move of a drag, and
    // re-rendering this component re-creates every Row beneath it.
    const drop = useFolderDrop(visible, ordered);

    const parentOf = useCallback(
        (id: number) => visible.find((v) => v.node.children.some((c) => c.id === id))?.node ?? null,
        [visible]
    );

    /**
     * Collapsing a row that contains the focused one takes focus with it.
     *
     * Otherwise focus is left pointing at a row that is no longer rendered:
     * no row is tabbable, and the whole tree drops out of the tab order
     * silently. Moving focus to the row being collapsed is also what the user
     * means — that row is the thing they just acted on.
     */
    const toggleKeepingFocus = useCallback(
        (node: FolderNode) => {
            const { focusedId: current, focus: moveFocus } = useRail.getState();

            if (expandedIds.has(node.id) && current !== null && contains(node, current)) {
                moveFocus(node.id);
            }

            toggle(node.id);
        },
        [expandedIds, toggle]
    );

    /**
     * Focus never points at a row that is not on screen.
     *
     * The roving tabindex is the tree's only way in from the keyboard, and it
     * is now an emergent property: each row decides for itself whether it is
     * the focused one, and the first row stands in when nothing is. So if
     * `focusedId` ever names a row that is not rendered — a folder deleted in
     * another tab, a refetch that dropped it — *no* row is tabbable and the
     * whole tree silently leaves the tab order.
     *
     * Clearing it restores the fallback. This is an effect on the shape of the
     * tree rather than on focus, so it runs when folders appear or disappear
     * and never on an arrow key — which is the whole reason this component
     * does not subscribe to focus in the first place. Collapsing is handled
     * before it gets here, by toggleKeepingFocus, because moving focus to the
     * row you just collapsed is better than dropping it to the top.
     *
     * tools/verify-tree.mjs asserts the invariant this protects.
     */
    useEffect(() => {
        const { focusedId: current, focus: moveFocus } = useRail.getState();

        if (current !== null && !visible.some((v) => v.node.id === current)) {
            moveFocus(null);
        }
    }, [visible]);

    const onKeyDown = useCallback(
        (event: React.KeyboardEvent) => {
            /**
             * While a row is an input, the tree owns exactly two keys.
             *
             * Everything else — arrows, Home, End, printable characters —
             * belongs to the field, and intercepting them would make the
             * caret unusable in the one place the user is typing.
             */
            if (editing) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    onSaveEdit();
                }

                if (event.key === 'Escape') {
                    event.preventDefault();
                    event.stopPropagation();
                    onCancelEdit();
                }

                return;
            }

            if (visible.length === 0) {
                return;
            }

            // Read at event time rather than from render: this component no
            // longer re-renders when focus moves, so a captured value would be
            // whatever it was when the tree last changed shape.
            const focusedId = useRail.getState().focusedId;
            const index = visible.findIndex((v) => v.node.id === focusedId);
            const current = index === -1 ? visible[0] : visible[index];
            const at = index === -1 ? 0 : index;

            const move = (to: number) => {
                event.preventDefault();
                focus(visible[Math.max(0, Math.min(visible.length - 1, to))].node.id);
            };

            /*
             * Alt + Up/Down rearranges instead of navigating.
             *
             * Not a nicety. A drag is a pointer gesture, and without a
             * keyboard path the arrangement would be a feature only some
             * people can create — on a tree whose entire keyboard contract is
             * otherwise complete.
             *
             * Among siblings only. Changing depth from the keyboard is a
             * second gesture with its own ambiguities, and cut/paste is the
             * better home for it.
             */
            if (event.altKey && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
                event.preventDefault();

                if (!can('rename')) {
                    return;
                }

                // `ordered` and not `nodes`: up means above the row above,
                // which under Name, A to Z is not the row before it in
                // sort_order. Shared with the toolbar's Move up / Move down,
                // which is the same operation reached without a modifier.
                const plan = planSiblingMove(
                    ordered,
                    current.node.id,
                    event.key === 'ArrowDown' ? 1 : -1
                );

                if (!plan) {
                    return;
                }

                // Same as a drop: the arrangement is only visible under
                // Custom, and the user has just made one.
                setSort('custom');
                reorder.mutate(plan);

                return;
            }

            /*
             * Cut, copy and paste — the keyboard half of the ⋮ menu's
             * clipboard group, on the focused row.
             *
             * ⌘ on a Mac, Ctrl elsewhere, and never with Alt or Shift: those
             * are the browser's and the system's. Paste goes *inside* the
             * focused row — Beside is one menu away, and a single key can
             * only mean one of them. Copy with files has no key on purpose:
             * the menu row names what it does, a chord would not.
             */
            if ((event.metaKey || event.ctrlKey) && !event.altKey && !event.shiftKey) {
                const key = event.key.toLowerCase();

                if (key === 'x' && can('rename')) {
                    event.preventDefault();
                    clipboard.take(current.node, 'cut');

                    return;
                }

                if (key === 'c' && can('rename') && can('create')) {
                    event.preventDefault();
                    clipboard.take(current.node, 'copy');

                    return;
                }

                if (key === 'v') {
                    event.preventDefault();
                    clipboard.paste(ordered, current.node.id, 'inside');

                    return;
                }
            }

            // Escape lets go of whatever is held — the one way to un-cut a
            // folder without pasting it somewhere or reloading the page.
            if (event.key === 'Escape' && useRail.getState().clipboard) {
                event.preventDefault();
                clipboard.clear();

                return;
            }

            switch (event.key) {
                case 'ArrowDown':
                    return move(at + 1);

                case 'ArrowUp':
                    return move(at - 1);

                case 'ArrowRight': {
                    event.preventDefault();

                    if (current.node.children.length === 0) {
                        return;
                    }

                    // Expand, or step into what expanding already revealed.
                    if (!expandedIds.has(current.node.id)) {
                        expand(current.node.id);
                    } else {
                        focus(current.node.children[0].id);
                    }

                    return;
                }

                case 'ArrowLeft': {
                    event.preventDefault();

                    if (expandedIds.has(current.node.id)) {
                        // Focus is on this row, not inside it, so collapsing
                        // cannot strand it — no guard needed here.
                        collapse(current.node.id);

                        return;
                    }

                    const parent = parentOf(current.node.id);

                    if (parent) {
                        focus(parent.id);
                    }

                    return;
                }

                case 'Home':
                    return move(0);

                case 'End':
                    return move(visible.length - 1);

                case 'Enter':
                    event.preventDefault();

                    // The one key that filters the library. Everything above
                    // moves focus and nothing else.
                    return select(current.node.id);

                case ' ':
                    event.preventDefault();

                    return toggle(current.node.id);

                case 'F2':
                    event.preventDefault();

                    // The keyboard checks the same abilities the toolbar
                    // buttons do. It did not, which made F2 and Delete a way
                    // around the roles matrix for anyone who knew them — the
                    // request 403s, but the row is already gone from the tree
                    // optimistically, and the block inspector renders this
                    // same component with every ability but `assign` off.
                    if (!can('rename')) {
                        return;
                    }

                    return edit({
                        mode: 'rename',
                        parentId: null,
                        folderId: current.node.id,
                        value: current.node.name,
                    });

                case 'Delete':
                case 'Backspace':
                    event.preventDefault();

                    if (!can('delete')) {
                        return;
                    }

                    // No confirm. The toast is the confirmation, and it is the
                    // kind you can answer after seeing what happened.
                    return onDelete(current.node);

                case '*': {
                    event.preventDefault();

                    // Open every sibling at this level, not the whole tree.
                    const siblings = visible.filter((v) => v.depth === current.depth);
                    siblings.forEach((v) => v.node.children.length > 0 && expand(v.node.id));

                    return;
                }

                default:
                    break;
            }

            // Type-ahead. Deliberately last, and only for single printable
            // characters, so it never swallows a shortcut — and it does not
            // open the search field, which is a separate control.
            if (event.key.length !== 1 || event.ctrlKey || event.metaKey || event.altKey) {
                return;
            }

            const now = Date.now();
            const text = (now - typed.at < 1000 ? typed.text : '') + event.key.toLowerCase();
            typed.text = text;
            typed.at = now;

            // Search forward from the current row and wrap, so repeatedly
            // typing the same letter walks through the matches.
            const order = [...visible.slice(at + 1), ...visible.slice(0, at + 1)];
            const hit = order.find((v) => v.node.name.toLowerCase().startsWith(text));

            if (hit) {
                event.preventDefault();
                focus(hit.node.id);
            }
        },
        [
            visible, ordered, expandedIds, editing,
            focus, select, toggle, expand, collapse, edit, parentOf,
            setSort, reorder, clipboard,
            onSaveEdit, onCancelEdit, onDelete,
        ]
    );

    if (loading) {
        return (
            <ul className="folderfolio-tree" role="tree" aria-busy="true">
                <GhostRows />
            </ul>
        );
    }

    const creatingAtRoot = editing?.mode === 'create' && editing.parentId === null;

    // Nothing in the tree is not an error, and it is not the search's empty
    // state either. Which of the two empty libraries it is — one nobody has
    // filed, or one filed somewhere else — is EmptyTree's question.
    if (ordered.length === 0 && !creatingAtRoot) {
        return <EmptyTree />;
    }

    const firstRowId = visible[0]?.node.id ?? null;

    return (
        <ul
            className="folderfolio-tree"
            role="tree"
            aria-label={t('folders', 'Folders')}
            onKeyDown={onKeyDown}
            {...drop.handlers}
        >
            {/*
              The insertion rule. Always mounted and hidden, because the drag
              moves it by writing to its style rather than by re-rendering —
              and a slot with no height so it contributes nothing to the list.
            */}
            <li role="none" className="folderfolio-tree__marker-slot" aria-hidden="true">
                <span ref={drop.markerRef} className="folderfolio-tree__marker" hidden />
            </li>
            {renderLevel(ordered, 0)}
            {creatingAtRoot ? <CreateRow depth={0} /> : null}
        </ul>
    );

    function renderLevel(level: FolderNode[], depth: number): React.ReactNode {
        return level.map((node) => {
            const expanded = expandedIds.has(node.id);
            const creatingHere = editing?.mode === 'create' && editing.parentId === node.id;

            return (
                <Row
                    key={node.id}
                    node={node}
                    depth={depth}
                    expanded={expanded}
                    renaming={editing?.mode === 'rename' && editing.folderId === node.id}
                    fallbackTabStop={nothingFocused && node.id === firstRowId}
                    ordered={ordered}
                    onSelect={() => select(node.id)}
                    onToggle={() => toggleKeepingFocus(node)}
                    onDelete={() => onDelete(node)}
                >
                    {expanded && node.children.length > 0 ? (
                        <ul role="group">
                            {renderLevel(node.children, depth + 1)}
                            {creatingHere ? <CreateRow depth={depth + 1} /> : null}
                        </ul>
                    ) : creatingHere ? (
                        // A folder with no children yet, or a collapsed one:
                        // the new row still has to appear inside it, so the
                        // group is created for it.
                        <ul role="group">
                            <CreateRow depth={depth + 1} />
                        </ul>
                    ) : null}
                </Row>
            );
        });
    }
}
