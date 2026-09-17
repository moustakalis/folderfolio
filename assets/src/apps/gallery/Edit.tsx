/**
 * The block in the editor — screen 09.
 *
 * Two states. Unconfigured, it is the dashed placeholder the handoff draws:
 * the mark, one sentence, and Choose folder. Configured, it is what a visitor
 * will see, rendered by the same `render.php` that will render it on the front
 * end — `wp.serverSideRender` asks the server for the markup rather than the
 * editor drawing its own approximation of it. One renderer, so a preview
 * cannot promise a layout the page does not deliver. (That lesson is from the
 * import wizard, where a preview and a run disagreed by nine folders.)
 *
 * **One delta from the board**, recorded here because it is a deviation: the
 * placeholder's Choose folder reveals the tree *inside the placeholder* rather
 * than opening the block sidebar. Opening the sidebar means dispatching to
 * `core/edit-post`, which is the post editor's store and not the site
 * editor's, so the button would work in one editor and silently do nothing in
 * the other. A tree in the placeholder works in both, and in the widget screen
 * too.
 */

import { useEffect, useState } from 'react';

import type { GalleryAttributes } from './block';
import { FolderTree } from './FolderTree';
import { Inspector } from './Inspector';
import { Mark } from './Mark';
import { NAME } from './block';
import { useRail } from '../rail/store';
import { t } from '../../core/api';

const blockEditor = window.wp?.blockEditor;
const components = window.wp?.components;
const ServerSideRender = window.wp?.serverSideRender;

export interface EditProps {
    attributes: GalleryAttributes;
    setAttributes: (next: Partial<GalleryAttributes>) => void;
    isSelected: boolean;
}

export function Edit({ attributes, setAttributes, isSelected }: EditProps) {
    const folderId = attributes.folderIds[0] ?? null;

    /*
     * The tree's selection is module state, shared by every instance of this
     * block on the page — the same arrangement the rail uses, and the reason
     * arrowing through the tree is cheap. So the rule is: the block being
     * edited owns that selection. It writes its own folder in when it becomes
     * the selected block, and listens for changes only while it is.
     *
     * One effect, and a subscription rather than a second effect on
     * `selectedId`. Two effects is how this was written first, and they wrote
     * to each other: the one that pushed the attribute into the store and the
     * one that read the store back into the attribute each ran with the value
     * the *other* had already replaced, so the block flipped between "folder
     * 188" and "no folder" until React gave up — "this block has encountered
     * an error and cannot be previewed", and error #185 in the console.
     *
     * Subscribing gives the listener both the new and the previous value, so
     * it can tell a selection the user just made from the one this effect
     * wrote a line earlier, which is the distinction the whole loop turned on.
     */
    useEffect(() => {
        if (!isSelected) {
            return;
        }

        useRail.setState({ selectedId: folderId, focusedId: folderId });

        return useRail.subscribe((state, previous) => {
            if (state.selectedId === previous.selectedId) {
                return;
            }

            setAttributes({
                folderIds:
                    null !== state.selectedId && state.selectedId > 0
                        ? [state.selectedId]
                        : [],
            });
        });
    }, [isSelected, folderId, setAttributes]);

    const blockProps = blockEditor?.useBlockProps ? blockEditor.useBlockProps() : {};

    return (
        <div {...blockProps}>
            <Inspector attributes={attributes} setAttributes={setAttributes} />

            {null === folderId ? (
                <Placeholder />
            ) : ServerSideRender ? (
                <ServerSideRender
                    block={NAME}
                    /*
                     * The same object, deliberately — not a copy.
                     *
                     * ServerSideRender re-fetches whenever this prop changes
                     * identity, so `{{ ...attributes }}` is a new object on
                     * every render and therefore a fetch on every render,
                     * each of which sets state and causes the next one. The
                     * editor showed it as "this block has encountered an
                     * error and cannot be previewed" and the console as React
                     * #185, maximum update depth exceeded.
                     *
                     * The cast is because the block's attributes are a real
                     * interface here — narrower than the index signature the
                     * component asks for — and widening the interface to
                     * match would give away every typo in every
                     * setAttributes call in this directory.
                     */
                    attributes={attributes as unknown as Record<string, unknown>}
                />
            ) : null}
        </div>
    );
}

/**
 * The unconfigured block.
 *
 * Dashed, the mark, one sentence, one button — and the tree behind the button
 * rather than a second screen. Nothing here is styled by core's `Placeholder`
 * component: the board draws this one, and it is two rules of CSS.
 */
function Placeholder() {
    const [choosing, setChoosing] = useState(false);
    const Button = components?.Button;

    return (
        <div className="folderfolio folderfolio-gallery-placeholder">
            <div className="folderfolio-gallery-placeholder__mark">
                <Mark size={28} />
            </div>

            <p className="folderfolio-gallery-placeholder__text">
                {t('galleryPrompt', 'Pick a folder and every image in it becomes this gallery.')}
            </p>

            {choosing ? (
                <div className="folderfolio-gallery-placeholder__tree">
                    <FolderTree />
                </div>
            ) : Button ? (
                <Button variant="primary" onClick={() => setChoosing(true)}>
                    {t('chooseFolder', 'Choose folder')}
                </Button>
            ) : null}
        </div>
    );
}
