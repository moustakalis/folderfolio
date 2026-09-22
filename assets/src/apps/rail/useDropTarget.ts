/**
 * Making a folder row or a drill-down card accept dropped files.
 *
 * Both are drop targets, and the design gives both the same treatment — a 2px
 * frame rather than a fill, and the count tag showing what the folder will
 * hold — so both use this.
 *
 * **Files only.** The frame means "into this container", and it is reserved
 * for the payload that has a container to go into. A folder being dragged is
 * answered by the insertion rule in folder-drop.ts, whose position says which
 * parent and which slot — a frame could say neither.
 */

import { useCallback, useRef, useState } from 'react';

import { useMoveAttachments } from './queries';
import { draggedFiles } from './drag';

export function useDropTarget(folderId: number) {
    /** The number of files hovering over this target, or null for none. */
    const [incoming, setIncoming] = useState<number | null>(null);
    const move = useMoveAttachments();

    /**
     * dragenter and dragleave fire for every child element the pointer
     * crosses, so a row made of five spans reports leaving four times on the
     * way across it. Counting depth is the standard fix; without it the frame
     * flickers off as soon as the pointer moves an inch inside the row.
     */
    const depth = useRef(0);

    const onDragEnter = useCallback((event: React.DragEvent) => {
        const payload = draggedFiles();

        if (!payload) {
            return;
        }

        event.preventDefault();
        depth.current += 1;
        setIncoming(payload.ids.length);
    }, []);

    const onDragOver = useCallback((event: React.DragEvent) => {
        const payload = draggedFiles();

        if (!payload) {
            return;
        }

        // Without preventDefault on *every* dragover the browser refuses the
        // drop, whatever dragenter said.
        event.preventDefault();

        // The cursor tells the truth about which of the two things will
        // happen: a move out of the folder being viewed, or an add.
        event.dataTransfer.dropEffect = payload.sourceFolderId !== null ? 'move' : 'copy';
    }, []);

    const onDragLeave = useCallback(() => {
        depth.current -= 1;

        if (depth.current <= 0) {
            depth.current = 0;
            setIncoming(null);
        }
    }, []);

    const onDrop = useCallback(
        (event: React.DragEvent) => {
            const payload = draggedFiles();

            depth.current = 0;
            setIncoming(null);

            if (!payload) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            // Dropping a folder's own files back into it is a no-op worth
            // catching here rather than sending to the server.
            if (payload.sourceFolderId === folderId) {
                return;
            }

            move.mutate({
                ids: payload.ids,
                sourceFolderId: payload.sourceFolderId,
                destinationFolderId: folderId,
            });
        },
        [folderId, move]
    );

    return {
        incoming,
        isOver: incoming !== null,
        handlers: { onDragEnter, onDragOver, onDragLeave, onDrop },
    };
}
