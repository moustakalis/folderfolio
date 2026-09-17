/**
 * The tree, and the keyboard.
 *
 * The handoff is specific: one tab stop, focus tracked apart from selection so
 * arrowing does not re-filter the library, and the full set of tree keys. All
 * of that needs a flat list of the rows currently on screen — "the next
 * visible row, crossing levels" is not a question a nested render can answer —
 * so the tree is flattened once per render and the key handler works on that.
 */

import { useCallback, useMemo } from 'react';

import { Row, GhostRows } from './Row';
import type { FolderNode } from './queries';
import { useRail } from './store';

interface Visible {
    node: FolderNode;
    depth: number;
}

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

export function Tree({ nodes, loading }: { nodes: FolderNode[]; loading: boolean }) {
    const selectedId = useRail((s) => s.selectedId);
    const focusedId = useRail((s) => s.focusedId);
    const expandedIds = useRail((s) => s.expandedIds);
    const select = useRail((s) => s.select);
    const focus = useRail((s) => s.focus);
    const toggle = useRail((s) => s.toggle);
    const expand = useRail((s) => s.expand);
    const collapse = useRail((s) => s.collapse);

    const visible = useMemo(() => flatten(nodes, expandedIds), [nodes, expandedIds]);


    const parentOf = useCallback(
        (id: number) => visible.find((v) => v.node.children.some((c) => c.id === id))?.node ?? null,
        [visible]
    );

    const onKeyDown = useCallback(
        (event: React.KeyboardEvent) => {
            if (visible.length === 0) {
                return;
            }

            const index = visible.findIndex((v) => v.node.id === focusedId);
            const current = index === -1 ? visible[0] : visible[index];
            const at = index === -1 ? 0 : index;

            const move = (to: number) => {
                event.preventDefault();
                focus(visible[Math.max(0, Math.min(visible.length - 1, to))].node.id);
            };

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
        [visible, focusedId, expandedIds, focus, select, toggle, expand, collapse, parentOf]
    );

    if (loading) {
        return (
            <ul className="folderfolio-tree" role="tree" aria-busy="true">
                <GhostRows />
            </ul>
        );
    }

    // Nothing in the tree is not an error, and it is not the search's empty
    // state either — it is a library nobody has filed yet.
    if (nodes.length === 0) {
        return (
            <p className="folderfolio-rail__empty">
                {window.folderFolio?.i18n?.emptyTree ?? 'No folders yet'}
            </p>
        );
    }

    const focusedIsVisible = visible.some((v) => v.node.id === focusedId);

    return (
        <ul
            className="folderfolio-tree"
            role="tree"
            aria-label={window.folderFolio?.i18n?.folders ?? 'Folders'}
            onKeyDown={onKeyDown}
        >
            {renderLevel(nodes, 0)}
        </ul>
    );

    function renderLevel(level: FolderNode[], depth: number): React.ReactNode {
        return level.map((node) => {
            const expanded = expandedIds.has(node.id);

            return (
                <Row
                    key={node.id}
                    node={node}
                    depth={depth}
                    expanded={expanded}
                    selected={selectedId === node.id}
                    // If the focused row has been collapsed out of sight,
                    // the first row takes the tab stop — otherwise Tab would
                    // land on nothing.
                    focused={
                        focusedIsVisible
                            ? focusedId === node.id
                            : node.id === visible[0]?.node.id
                    }
                    onSelect={() => select(node.id)}
                    onToggle={() => toggle(node.id)}
                >
                    {expanded && node.children.length > 0 ? (
                        <ul role="group">{renderLevel(node.children, depth + 1)}</ul>
                    ) : null}
                </Row>
            );
        });
    }
}
