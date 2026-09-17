/**
 * Drill-down: the current folder's children, above the files.
 *
 * Two shapes for the same list, because the two library modes have different
 * furniture. In grid mode they are cards on the same grid rhythm as the
 * thumbnails below them. In list mode they are a single 28px chip strip —
 * cards would fight the table, which is a block of aligned rows and does not
 * want a second block of boxes above it competing for the same reading.
 *
 * When the folder has no children, nothing is rendered — not an eyebrow
 * standing alone over empty space. That was left open in the handoff; this is
 * the answer, and it is the one that costs the user no vertical space for
 * information they did not ask for.
 */

import { FolderIcon } from './icons';
import type { FolderNode } from './queries';
import { useDropTarget } from './useDropTarget';
import { useRail } from './store';
import { t } from '../../core/api';

export function Cards({ children, list }: { children: FolderNode[]; list: boolean }) {
    if (children.length === 0) {
        return null;
    }

    if (list) {
        return (
            <div className="folderfolio-chips">
                <span className="folderfolio-eyebrow">{t('foldersHere', 'Folders here')}</span>

                {children.map((node) => (
                    <Chip key={node.id} node={node} />
                ))}
            </div>
        );
    }

    return (
        <>
            <span className="folderfolio-eyebrow">{t('foldersHere', 'Folders here')}</span>

            <div className="folderfolio-cards">
                {children.map((node) => (
                    <Card key={node.id} node={node} />
                ))}
            </div>
        </>
    );
}

/**
 * A card is a drop target as well as a way in — the same 2px frame the tree
 * row gets, because they are the same affordance in two places and looking
 * different would suggest they behave differently.
 */
function Card({ node }: { node: FolderNode }) {
    const select = useRail((s) => s.select);
    const drop = useDropTarget(node.id);

    return (
        <button
            type="button"
            className={`folderfolio-card${drop.isOver ? ' is-dragover' : ''}`}
            onClick={() => select(node.id)}
            style={node.color ? ({ '--ff-folder': node.color } as React.CSSProperties) : undefined}
            {...drop.handlers}
        >
            <span className="folderfolio-card__icon">
                <FolderIcon size={20} />
            </span>
            <span className="folderfolio-card__name">{node.name}</span>
            <span className="folderfolio-card__count">
                {drop.isOver ? `+${drop.incoming}` : node.total_count}
            </span>
        </button>
    );
}

function Chip({ node }: { node: FolderNode }) {
    const select = useRail((s) => s.select);
    const drop = useDropTarget(node.id);

    return (
        <button
            type="button"
            className={`folderfolio-chip${drop.isOver ? ' is-dragover' : ''}`}
            onClick={() => select(node.id)}
            style={node.color ? ({ '--ff-folder': node.color } as React.CSSProperties) : undefined}
            {...drop.handlers}
        >
            <span className="folderfolio-chip__icon">
                <FolderIcon size={14} />
            </span>
            <span className="folderfolio-chip__name">{node.name}</span>
            <span className="folderfolio-chip__count">
                {drop.isOver ? `+${drop.incoming}` : node.total_count}
            </span>
        </button>
    );
}
