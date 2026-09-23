/**
 * Everything you can do to one folder — screen 11, §9.8, grown.
 *
 * ## One menu, two doors
 *
 * Above 782px it opens from a ⋮ on the selected row; below, from the control
 * line's last button. Same component, same contents, same gates — the only
 * difference is what it is pinned to. That is the whole point of the split
 * below: `FolderMenuItems` is the menu, and the two exported wrappers are
 * only containers.
 *
 * The rule the shape comes from is Nick's: **no action in two places at one
 * width**. So Rename and Delete left the toolbar entirely and live here, and
 * the toolbar itself is gone — what survived it is the global sort, which is
 * not a folder action and sits beside the search field.
 *
 * ## Two steps, not a submenu
 *
 * *Sort inside* asks two questions — subfolders, and files — with five and
 * four answers. Nine more rows would make this panel taller than the tree it
 * sits over, and a flyout in a 308px rail has nowhere to open but back across
 * the tree. So choosing a scope replaces the panel's body, with one row back.
 * One level, no hover, and it works on a touch screen.
 *
 * ## No heading
 *
 * It said "Colour for <name>" when colour was all it did, then the name alone
 * when it grew. Both are gone: opened from the row, the menu is physically on
 * the folder it acts on, and opened from the control line it acts on the row
 * drawn in the accent colour. A title repeating what the pointer is already
 * touching is a row of chrome. The second step's back row carries the scope's
 * name, which is the one place a heading says something.
 */

import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

import {
    ArrowDownIcon,
    ArrowUpIcon,
    ChevronRightIcon,
    CopyIcon,
    DownloadIcon,
    LockIcon,
    PasteIcon,
    PinIcon,
    StarIcon,
    ScissorsIcon,
    PencilIcon,
    TrashIcon,
} from './icons';
import { downloadFolder } from './download';
import { planSiblingMove } from './move';
import { heldLabel, mayPaste, planPaste, stillThere, usePaste, type Where } from './paste';
import { Menu } from './Menu';
import type { FolderNode } from './queries';
import { useReorderFolders, useSetFolderColor, useSetFolderMark, useSetFolderSort } from './queries';
import { hasLockInside, isBlocked, lockingName } from './locks';
import { SORT_LABELS, isSortOrder, useRail, type SortOrder } from './store';
import { useAnchoredPanel } from './useAnchoredPanel';
import { can } from '../../lib/can';
import { SWATCHES, isSwatch, swatchLabel, type Swatch } from '../../lib/swatches';
import { isMedia, t } from '../../core/api';

type Scope = 'folders' | 'files';

/**
 * What a folder's files can be ordered by: the same five as its folders.
 *
 * Custom joined on 23 Sep (tier 2 item 8) — it is each file's position in the
 * folder, set by a drag between tiles or Move to start / end, and the drag
 * chooses it for you. Kept as its own list so the two steps can part again.
 */
const FILE_ORDERS: readonly SortOrder[] = ['name-asc', 'name-desc', 'newest', 'oldest', 'custom'];

interface FolderMenuProps {
    folder: FolderNode;
    /** The tree as the person is looking at it — see move.ts on why sorted. */
    ordered: FolderNode[];
    onDelete: () => void;
    onClose: () => void;
}

function orderLabel(order: string | null): string {
    if (!isSortOrder(order)) {
        return t('sortSameAsEverywhere', 'Same as everywhere');
    }

    const found = SORT_LABELS.find((option) => option.value === order);

    return found ? t(found.label, found.fallback) : order;
}

function FolderMenuItems({ folder, ordered, onDelete, onClose }: FolderMenuProps) {
    const setColor = useSetFolderColor();
    const setSort = useSetFolderSort();
    const reorder = useReorderFolders();
    const setGlobalSort = useRail((s) => s.setSort);
    const edit = useRail((s) => s.edit);
    const levelId = useRail((s) => s.levelId);
    const openLevel = useRail((s) => s.openLevel);
    const current = isSwatch(folder.color) ? folder.color : null;
    const held = useRail((s) => s.clipboard);
    const globalSort = useRail((s) => s.sort);
    const clipboard = usePaste();

    const [step, setStep] = useState<Scope | null>(null);
    const backRef = useRef<HTMLButtonElement>(null);
    const foldersRef = useRef<HTMLButtonElement>(null);
    const filesRef = useRef<HTMLButtonElement>(null);

    /*
     * Focus follows the step.
     *
     * `Menu` focuses the first button when it opens and puts focus back on
     * the trigger when Escape closes it — but neither covers a panel that
     * replaces its own contents. Without this, choosing a scope removes the
     * button that had focus and drops it on <body>, which is the exact
     * failure the note in Menu.tsx was written about.
     */
    useEffect(() => {
        if (step !== null) {
            backRef.current?.focus();
        }
    }, [step]);

    function leaveStep(from: Scope) {
        setStep(null);

        // After paint, because the row being focused does not exist until
        // this step has been replaced by the main list.
        requestAnimationFrame(() => {
            (from === 'files' ? filesRef : foldersRef).current?.focus();
        });
    }

    // Asked here rather than inside the click, so an item that is enabled and
    // an action that does nothing cannot disagree. A folder alone on its
    // level has both disabled, which is the honest answer to "can I move
    // this" and cheaper than a toast saying no afterwards.
    const up = planSiblingMove(ordered, folder.id, -1);
    const down = planSiblingMove(ordered, folder.id, 1);

    function choose(color: Swatch | null) {
        setColor.mutate({ id: folder.id, color });
        onClose();
    }

    function move(plan: ReturnType<typeof planSiblingMove>) {
        if (!plan) {
            return;
        }

        // Step out first, when the folder being moved is the level we are
        // standing in.
        //
        // In Levels.tsx tapping a folder that has children both selects it
        // and walks into it, so the selection can be the header rather than a
        // row — and its siblings, the only place the move is visible, are one
        // level back. Without this the person taps Move down, the endpoint
        // succeeds, and nothing on screen changes. `levelId` is null in the
        // wide tree, so this never fires there.
        if (levelId !== null && levelId === folder.id) {
            openLevel(plan.parentId);
        }

        // Same as a drop and as Alt+Arrow: the arrangement is only visible
        // under Custom, and the person has just made one.
        setGlobalSort('custom');
        reorder.mutate(plan);
        onClose();
    }

    const organise = can('rename');
    const copying = organise && can('create');

    /*
     * Lock, pin and star — tier 2 item 10, board 3ZU8VGkJemznTvKp8tNnvY.
     *
     * `blocked`: a lock covers this folder (its own or an ancestor's) and
     * this person may not pass it. Everything that changes the folder's shape
     * is disabled rather than hidden — the menu keeps its shape, and the line
     * at the top says why. Copy, colour and Sort inside stay (answer 6).
     * `parentBlocked`: the lock is an ancestor's, so the level this folder
     * sits in is locked too — a paste *beside* it would be made inside it.
     */
    const blocked = isBlocked(folder);
    const parentBlocked = blocked && folder.locked_by !== folder.id;
    const lockedInside = hasLockInside(folder);
    const mark = useSetFolderMark();
    const starred = useRail((s) => s.stars.includes(folder.id));
    const toggleStar = useRail((s) => s.toggleStar);

    // mutateAsync, not mutate: this menu unmounts on press, and TanStack drops
    // a mutate() callback for an unmounted observer (trap 78). The refusal is
    // the MutationCache's notice sheet, so the promise's own is swallowed.
    function setMark(which: 'lock' | 'pin', on: boolean) {
        void mark.mutateAsync({ id: folder.id, mark: which, on }).catch(() => undefined);
        onClose();
    }

    /*
     * One strip of three labelled toggles (answer 1): each keeps its label
     * whether pressed or not — the rule Start here set — and aria-pressed,
     * the filled glyph and the accent wash carry the state.
     */
    // Download a folder as a ZIP — tier 2 item 11. mutate-free: the request
    // is a read, and the menu closes first so the notice sheet it may open is
    // not under it.
    const download = can('download') ? (
        <button
            type="button"
            role="menuitem"
            className="folderfolio-menu__item"
            onClick={() => {
                onClose();
                void downloadFolder(folder.id);
            }}
        >
            <DownloadIcon size={13} />
            {t('downloadZip', 'Download as ZIP')}
        </button>
    ) : null;

    // Nothing to show is no strip at all, not an empty band with padding.
    const marks = !organise && !can('star') && !can('lock') ? null : (
        <div className="folderfolio-marks" role="group" aria-label={t('folderMarks', 'Pin, star and lock')}>
            {organise ? (
                <button
                    type="button"
                    role="menuitemcheckbox"
                    aria-checked={Boolean(folder.pinned)}
                    className="folderfolio-marks__toggle"
                    disabled={blocked}
                    onClick={() => setMark('pin', !folder.pinned)}
                >
                    <PinIcon size={13} filled={Boolean(folder.pinned)} />
                    {t('pin', 'Pin')}
                </button>
            ) : null}

            {can('star') ? (
                <button
                    type="button"
                    role="menuitemcheckbox"
                    aria-checked={starred}
                    className="folderfolio-marks__toggle"
                    onClick={() => {
                        toggleStar(folder.id);
                        onClose();
                    }}
                >
                    <StarIcon size={13} filled={starred} />
                    {t('star', 'Star')}
                </button>
            ) : null}

            {can('lock') ? (
                <button
                    type="button"
                    role="menuitemcheckbox"
                    aria-checked={Boolean(folder.locked)}
                    className="folderfolio-marks__toggle"
                    onClick={() => setMark('lock', !folder.locked)}
                >
                    <LockIcon size={13} filled={Boolean(folder.locked)} />
                    {t('lock', 'Lock')}
                </button>
            ) : null}
        </div>
    );

    /*
     * Each paste row is asked the same question the paste itself will be, so
     * a row that is enabled and a paste that does nothing cannot disagree —
     * the rule Move up and Move down already follow. Inside itself, past the
     * depth limit, or a cut that would land exactly where it is: disabled.
     */
    const pastable = held !== null && mayPaste(held) && stillThere(ordered, held);
    const canPasteInside =
        pastable && !blocked && planPaste(ordered, held, folder.id, 'inside', globalSort) !== null;
    const canPasteBeside =
        pastable && !parentBlocked && planPaste(ordered, held, folder.id, 'beside', globalSort) !== null;

    function paste(where: Where) {
        // Step out first when standing inside the folder being pasted beside,
        // for the reason move() above gives: in Levels its siblings are one
        // level back, and a paste there would be invisible from here.
        if (where === 'beside' && levelId !== null && levelId === folder.id) {
            openLevel(folder.parent_id ?? null);
        }

        clipboard.paste(ordered, folder.id, where);
        onClose();
    }

    // ------------------------------------------------------------ step two
    if (step !== null) {
        const orders: readonly SortOrder[] =
            step === 'files' ? FILE_ORDERS : SORT_LABELS.map((option) => option.value);
        const chosen = step === 'files' ? folder.sort_files : folder.sort_folders;

        return (
            <>
                <button
                    ref={backRef}
                    type="button"
                    role="menuitem"
                    className="folderfolio-menu__back"
                    onClick={() => leaveStep(step)}
                >
                    <span className="folderfolio-menu__back-icon" aria-hidden="true">
                        <ChevronRightIcon size={12} />
                    </span>
                    {step === 'files'
                        ? t('sortFiles', 'Files')
                        : t('sortSubfolders', 'Subfolders')}
                </button>

                {/*
                  "Same as everywhere" first, and a real choice rather than an
                  absence: it is the state every folder starts in, and the one
                  a person needs to get back to after trying an order they did
                  not want. Clearing is a DELETE on the server, not a stored
                  default — see FolderSorts::set.
                */}
                <button
                    type="button"
                    role="menuitemradio"
                    aria-checked={!isSortOrder(chosen)}
                    className="folderfolio-menu__item"
                    onClick={() => {
                        setSort.mutate({ id: folder.id, scope: step, order: null });
                        onClose();
                    }}
                >
                    <span className="folderfolio-menu__tick" aria-hidden="true">
                        {!isSortOrder(chosen) ? '✓' : ''}
                    </span>
                    {t('sortSameAsEverywhere', 'Same as everywhere')}
                </button>

                <div className="folderfolio-menu__rule" role="separator" />

                {orders.map((value) => {
                    const option = SORT_LABELS.find((o) => o.value === value);

                    return (
                        <button
                            key={value}
                            type="button"
                            role="menuitemradio"
                            aria-checked={chosen === value}
                            className="folderfolio-menu__item"
                            onClick={() => {
                                setSort.mutate({ id: folder.id, scope: step, order: value });
                                onClose();
                            }}
                        >
                            <span className="folderfolio-menu__tick" aria-hidden="true">
                                {chosen === value ? '✓' : ''}
                            </span>
                            {option ? t(option.label, option.fallback) : value}
                        </button>
                    );
                })}
            </>
        );
    }

    // ------------------------------------------------------------ step one
    return (
        <>
            {blocked ? (
                <p className="folderfolio-menu__why" role="note">
                    <LockIcon size={13} />
                    {t(
                        'lockedBy',
                        '“%s” is locked. Someone who can lock folders can unlock it.',
                        lockingName(ordered, folder)
                    )}
                </p>
            ) : null}

            {organise ? null : marks}

            {organise || !download ? null : (
                <>
                    {marks ? <div className="folderfolio-menu__rule" role="separator" /> : null}
                    {download}
                </>
            )}

            {organise ? (
                <>
                    <button
                        type="button"
                        role="menuitem"
                        className="folderfolio-menu__item"
                        disabled={blocked}
                        onClick={() => {
                            edit({
                                mode: 'rename',
                                parentId: null,
                                folderId: folder.id,
                                value: folder.name,
                            });
                            onClose();
                        }}
                    >
                        <PencilIcon size={13} />
                        {t('rename', 'Rename')}
                    </button>

                    <button
                        type="button"
                        role="menuitem"
                        className="folderfolio-menu__item"
                        disabled={up === null || blocked}
                        onClick={() => move(up)}
                    >
                        <ArrowUpIcon size={13} />
                        {t('moveUp', 'Move up')}
                    </button>

                    <button
                        type="button"
                        role="menuitem"
                        className="folderfolio-menu__item"
                        disabled={down === null || blocked}
                        onClick={() => move(down)}
                    >
                        <ArrowDownIcon size={13} />
                        {t('moveDown', 'Move down')}
                    </button>

                    <div className="folderfolio-menu__rule" role="separator" />

                    {marks}

                    <div className="folderfolio-menu__rule" role="separator" />

                    {/*
                      The clipboard — tier 1 item 5. Between the rows that
                      move a folder and the rows that arrange what is inside
                      it, because a paste is the first kind: it puts a folder
                      somewhere.

                      Two copy rows rather than a question afterwards. Whether
                      a copy brings its files is decided looking at the
                      source, which is the moment a person knows which they
                      mean — and a wrong default costs a manual pass per
                      subfolder in either direction. Nick's call, board
                      AU6ezv9WsPVHVk7UmzHNGJ.
                    */}
                    <button
                        type="button"
                        role="menuitem"
                        className="folderfolio-menu__item"
                        disabled={blocked}
                        onClick={() => {
                            clipboard.take(folder, 'cut');
                            onClose();
                        }}
                    >
                        <ScissorsIcon size={13} />
                        {t('cut', 'Cut')}
                    </button>

                    {copying ? (
                        <button
                            type="button"
                            role="menuitem"
                            className="folderfolio-menu__item"
                            onClick={() => {
                                clipboard.take(folder, 'copy');
                                onClose();
                            }}
                        >
                            <CopyIcon size={13} />
                            {t('copy', 'Copy')}
                        </button>
                    ) : null}

                    {/* Media only (tier 3 item 12): a post filed in two folders is
                        still one post, and "with files" names what a post
                        folder does not hold. */}
                    {copying && can('assign') && isMedia() ? (
                        <button
                            type="button"
                            role="menuitem"
                            className="folderfolio-menu__item"
                            onClick={() => {
                                clipboard.take(folder, 'copy', true);
                                onClose();
                            }}
                        >
                            <CopyIcon size={13} />
                            {t('copyWithFiles', 'Copy with files')}
                        </button>
                    ) : null}

                    {/*
                      Only while something is held, and only for someone who
                      may paste it — a person who can cut but not create is
                      never shown a copy they could not paste. The label names
                      what is held, the way Sort inside labels its two rows,
                      because the clipboard is otherwise invisible.
                    */}
                    {pastable ? (
                        <>
                            <p
                                className="folderfolio-menu__label folderfolio-menu__label--held"
                                title={heldLabel(held)}
                            >
                                {heldLabel(held)}
                            </p>

                            <button
                                type="button"
                                role="menuitem"
                                className="folderfolio-menu__item"
                                disabled={!canPasteInside}
                                onClick={() => paste('inside')}
                            >
                                <PasteIcon size={13} />
                                {t('pasteInside', 'Inside this folder')}
                            </button>

                            <button
                                type="button"
                                role="menuitem"
                                className="folderfolio-menu__item"
                                disabled={!canPasteBeside}
                                onClick={() => paste('beside')}
                            >
                                <PasteIcon size={13} />
                                {t('pasteBeside', 'Beside this folder')}
                            </button>
                        </>
                    ) : null}

                    {/*
                      Download in a group of its own, after the clipboard: Cut,
                      Copy and Paste change the tree and a download does not.
                      A lock does not stop it either — a lock protects the
                      folder's shape, and reading it changes nothing.
                    */}
                    {download ? (
                        <>
                            <div className="folderfolio-menu__rule" role="separator" />
                            {download}
                        </>
                    ) : null}

                    <div className="folderfolio-menu__rule" role="separator" />

                    {/*
                      Each row says what it is set to without being opened,
                      which is the whole reason a two-step panel is bearable:
                      the answer is on the first screen, and only changing it
                      costs a second.
                    */}
                    <p className="folderfolio-menu__label">
                        {t('sortInside', 'Sort inside')}
                    </p>

                    <button
                        ref={foldersRef}
                        type="button"
                        role="menuitem"
                        className="folderfolio-menu__item folderfolio-menu__item--step"
                        aria-haspopup="menu"
                        onClick={() => setStep('folders')}
                    >
                        {t('sortSubfolders', 'Subfolders')}
                        <span className="folderfolio-menu__value">
                            {orderLabel(folder.sort_folders)}
                        </span>
                        <ChevronRightIcon size={12} />
                    </button>

                    {/* The file order is the media library's: a post list
                        keeps its own column sorting. */}
                    {isMedia() ? (
                        <button
                            ref={filesRef}
                            type="button"
                            role="menuitem"
                            className="folderfolio-menu__item folderfolio-menu__item--step"
                            aria-haspopup="menu"
                            onClick={() => setStep('files')}
                        >
                            {t('sortFiles', 'Files')}
                            <span className="folderfolio-menu__value">
                                {orderLabel(folder.sort_files)}
                            </span>
                            <ChevronRightIcon size={12} />
                        </button>
                    ) : null}

                    <div className="folderfolio-menu__rule" role="separator" />

                    {/*
                      role="group" inside the menu, so a screen reader
                      announces the eleven choices as one set with one of them
                      checked, rather than as eleven unrelated menu items. The
                      eleventh is "No colour", which is a value in this set and
                      not an escape from it — which is why it is a radio and
                      not a separate command.
                    */}
                    <div
                        className="folderfolio-swatches"
                        role="group"
                        aria-label={t('folderColor', 'Folder colour')}
                    >
                        {SWATCHES.map((swatch) => (
                            <button
                                key={swatch}
                                type="button"
                                role="menuitemradio"
                                aria-checked={current === swatch}
                                aria-label={swatchLabel(swatch)}
                                title={swatchLabel(swatch)}
                                className="folderfolio-swatches__swatch"
                                style={{ background: `var(--ff-folder-${swatch})` }}
                                onClick={() => choose(swatch)}
                            />
                        ))}
                    </div>

                    <button
                        type="button"
                        role="menuitemradio"
                        aria-checked={current === null}
                        className="folderfolio-swatches__none"
                        onClick={() => choose(null)}
                    >
                        <span className="folderfolio-swatches__empty" aria-hidden="true" />
                        {t('noColor', 'No colour')}
                    </button>
                </>
            ) : null}

            {/*
              Delete last and behind its own rule, and gated on `delete`
              rather than on `rename` — the two abilities are separate columns
              in the roles matrix and a role can hold either one alone. Since
              23 Sep every rail user has this menu (anyone can star), and an
              action the role does not hold is hidden, not greyed — grey here
              means "not right now" (a lock, the end of the list), never "not
              for you". Nick's call.
            */}
            {can('delete') ? (
                <>
                    {organise ? <div className="folderfolio-menu__rule" role="separator" /> : null}

                    <button
                        type="button"
                        role="menuitem"
                        className="folderfolio-menu__item folderfolio-menu__item--danger"
                        disabled={blocked || lockedInside}
                        onClick={() => {
                            onDelete();
                            onClose();
                        }}
                    >
                        <TrashIcon size={13} />
                        {t('delete', 'Delete')}
                    </button>
                </>
            ) : null}
        </>
    );
}

/**
 * The control line's door — below 782px, where there is no ⋮.
 *
 * Absolutely positioned inside the control's wrapper and right-aligned,
 * because it is the last control in a 300px rail and a 240px panel opening
 * from its left edge would hang off the rail entirely. `Menu` supplies
 * Escape, the outside click, and focus returning to the button.
 */
export function FolderMenu(props: FolderMenuProps) {
    return (
        <Menu className="folderfolio-menu--folder" onClose={props.onClose}>
            <FolderMenuItems {...props} />
        </Menu>
    );
}

/**
 * The row's door — above 782px.
 *
 * Portaled to the body rather than rendered in the row, for the reason
 * AddToFolder's flyout is: the tree is a scroller with its own overflow, and
 * a panel inside it is clipped by the row two below the one that opened it.
 * `useAnchoredPanel` pins it, clamps it to the window, flips it above the
 * trigger when there is no room underneath, follows a scroll, and closes on
 * Escape or a pointer outside — all of it already written for the two flyouts
 * that use it.
 */
export function RowMenu({
    anchor,
    ...props
}: FolderMenuProps & { anchor: HTMLElement | null }) {
    const { ref, style } = useAnchoredPanel<HTMLDivElement>(anchor, props.onClose);

    return createPortal(
        <div
            ref={ref}
            style={style}
            className="folderfolio folderfolio-menu folderfolio-menu--row"
            role="menu"
            aria-label={props.folder.name}
        >
            <FolderMenuItems {...props} />
        </div>,
        document.body
    );
}
