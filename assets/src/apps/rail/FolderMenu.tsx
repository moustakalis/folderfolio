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
 * ## A submenu beside the row's menu; two steps in the narrow one
 *
 * *Sort inside* asks two questions — subfolders, and files — with five and
 * four answers, and *Colour* (since 24 Sep) eleven: nested, so the first
 * panel stays short enough to show Delete. Each row says what it is set to.
 *
 * From the row's ⋮ they are submenus, opened on hover — or a click, or →
 * — beside the panel, which stays where it is (Nick, 24 Sep: "on hover only
 * is the right way", and a panel replaced by another "in nowhere" was not).
 * That menu is portaled beside the rail with the library to its right, so a
 * flyout has room; it opens left of the panel when the window does not.
 *
 * The narrow door's menu is the other case: inside a rail that is the whole
 * width of a phone, with no side to open on and no hover to open with. There
 * choosing a row replaces the panel's body, with one row back.
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

import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

import {
    ArrowDownIcon,
    ArrowUpIcon,
    ChevronRightIcon,
    CopyIcon,
    DownloadIcon,
    ImageIcon,
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
import { AnchoredMenu, Menu } from './Menu';
import type { FolderNode } from './queries';
import { useReorderFolders, useSetFolderColor, useSetFolderKind, useSetFolderMark, useSetFolderSort } from './queries';
import { hasLockInside, isBlocked, lockingName } from './locks';
import { SORT_LABELS, isSortOrder, useRail, type SortOrder } from './store';
import { useAnchoredPanel } from './useAnchoredPanel';
import { can } from '../../lib/can';
import { SWATCHES, isSwatch, swatchLabel, type Swatch } from '../../lib/swatches';
import { isMedia, t } from '../../core/api';

type Scope = 'folders' | 'files';

/** A second step: one of the two sorts, or the colour (24 Sep, Nick: the
    ten swatches in the first step put Delete below the fold). */
type Step = Scope | 'color';

/**
 * What a folder's files can be ordered by: the same five as its folders.
 *
 * Custom joined on 23 Sep (tier 2 item 8) — it is each file's position in the
 * folder, set by a drag between tiles or Move to start / end, and the drag
 * chooses it for you. Kept as its own list so the two steps can part again.
 */
const FILE_ORDERS: readonly SortOrder[] = ['name-asc', 'name-desc', 'newest', 'oldest', 'custom'];

interface FolderMenuProps {
    /** Submenus beside the panel (the row's ⋮), rather than steps in it. */
    flyout?: boolean;
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

/**
 * A submenu, beside the ⋮ menu's panel and level with the row that opened it.
 *
 * Rendered inside the panel — `useAnchoredPanel` closes the menu on a pointer
 * outside its element — and fixed to the window, which the panel's own scroll
 * does not clip. Right of the panel, or left when the window has no room;
 * slid up as far as it must to stay in the window.
 */
function Flyout({
    trigger,
    label,
    onBack,
    onEnter,
    children,
}: {
    trigger: HTMLElement | null;
    label: string;
    onBack: () => void;
    onEnter: () => void;
    children: React.ReactNode;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const [at, setAt] = useState<{ left: number; top: number } | null>(null);

    useLayoutEffect(() => {
        const panel = trigger?.closest('.folderfolio-menu--row');
        const self = ref.current;

        if (!trigger || !panel || !self) {
            return;
        }

        const box = panel.getBoundingClientRect();
        const row = trigger.getBoundingClientRect();
        const width = self.offsetWidth;
        const height = self.offsetHeight;
        const right = box.right - 1;
        const left = right + width <= window.innerWidth - MARGIN ? right : Math.max(MARGIN, box.left - width + 1);
        // The first item level with the row: the flyout's 4px padding and 1px border.
        const top = Math.max(MARGIN, Math.min(row.top - 5, window.innerHeight - MARGIN - height));

        setAt({ left, top });
    }, [trigger]);

    return (
        <div
            ref={ref}
            className="folderfolio-menu--sub"
            role="menu"
            aria-label={label}
            style={at ? { left: `${at.left}px`, top: `${at.top}px` } : { left: '-9999px', top: '-9999px' }}
            onPointerEnter={onEnter}
            onKeyDown={(event) => {
                if (event.key === 'ArrowLeft') {
                    event.preventDefault();
                    onBack();
                }
            }}
        >
            {children}
        </div>
    );
}

const MARGIN = 8;

function FolderMenuItems({ flyout = false, folder, ordered, onDelete, onClose }: FolderMenuProps) {
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

    const [step, setStep] = useState<Step | null>(null);
    const backRef = useRef<HTMLButtonElement>(null);
    const foldersRef = useRef<HTMLButtonElement>(null);
    const filesRef = useRef<HTMLButtonElement>(null);
    const colorRef = useRef<HTMLButtonElement>(null);

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

    function refOf(which: Step) {
        return which === 'files' ? filesRef : which === 'color' ? colorRef : foldersRef;
    }

    function leaveStep(from: Step) {
        setStep(null);

        // After paint, because the row being focused does not exist until
        // this step has been replaced by the main list.
        requestAnimationFrame(() => {
            refOf(from).current?.focus();
        });
    }

    /*
     * The flyouts — the row menu's submenus.
     *
     * Open on hover, on a click (a touch screen, or a person who clicks), and
     * on → or Enter from the keyboard, which also moves focus into it. Hovering
     * another row of the panel closes it after a moment, so a pointer cutting
     * the corner on its way into the flyout does not lose it; the panel
     * scrolling closes it at once, since it would no longer be level with its
     * row. ← in the flyout goes back to the row.
     */
    const [sub, setSub] = useState<Step | null>(null);
    const closing = useRef<number | undefined>(undefined);
    const focusSub = useRef(false);

    function openSub(which: Step, withFocus: boolean) {
        window.clearTimeout(closing.current);
        focusSub.current = withFocus;
        setSub(which);
    }

    function closeSub(returnFocus: Step | null) {
        window.clearTimeout(closing.current);
        setSub(null);

        if (returnFocus !== null) {
            refOf(returnFocus).current?.focus();
        }
    }

    useEffect(() => {
        if (sub !== null && focusSub.current) {
            refOf(sub)
                .current?.parentElement?.querySelector<HTMLElement>('.folderfolio-menu--sub button:not(:disabled)')
                ?.focus();
        }
    }, [sub]);

    useEffect(() => {
        if (!flyout) {
            return;
        }

        const panel = (foldersRef.current ?? colorRef.current)?.closest<HTMLElement>('.folderfolio-menu--row');

        if (!panel) {
            return;
        }

        const onOver = (event: PointerEvent) => {
            const target = event.target as HTMLElement;

            if (target.closest('.folderfolio-menu--sub')) {
                window.clearTimeout(closing.current);

                return;
            }

            if (target.closest('[data-flyout]')) {
                return;
            }

            if (target.closest('button')) {
                window.clearTimeout(closing.current);
                closing.current = window.setTimeout(() => setSub(null), 250);
            }
        };
        const onScroll = () => setSub(null);

        panel.addEventListener('pointerover', onOver);
        panel.addEventListener('scroll', onScroll);

        return () => {
            window.clearTimeout(closing.current);
            panel.removeEventListener('pointerover', onOver);
            panel.removeEventListener('scroll', onScroll);
        };
    }, [flyout]);

    /** The props of a row that opens a submenu — or, in the narrow menu, a step. */
    function opens(which: Step) {
        if (!flyout) {
            return { onClick: () => setStep(which) };
        }

        return {
            'data-flyout': which,
            'aria-expanded': sub === which,
            // A click from the keyboard (Enter, Space) has no pointer: detail 0.
            onClick: (event: React.MouseEvent) => openSub(which, event.detail === 0),
            onPointerEnter: (event: React.PointerEvent) => {
                if (event.pointerType === 'mouse') {
                    openSub(which, false);
                }
            },
            onKeyDown: (event: React.KeyboardEvent) => {
                if (event.key === 'ArrowRight') {
                    event.preventDefault();
                    openSub(which, true);
                }
            },
        };
    }

    function flyoutOf(which: Step, label: string) {
        return flyout && sub === which ? (
            <Flyout
                trigger={refOf(which).current}
                label={label}
                onBack={() => closeSub(which)}
                onEnter={() => window.clearTimeout(closing.current)}
            >
                {options(which)}
            </Flyout>
        ) : null;
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
    const kind = useSetFolderKind();
    const gallery = folder.kind === 'gallery';
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

    /** What a submenu, or a step, offers — the same in both. */
    function options(which: Step) {
        if (which === 'color') {
            return (
                <>
                    {/*
                      role="group" inside the menu, so a screen reader announces
                      the eleven choices as one set with one of them checked,
                      rather than as eleven unrelated menu items. The eleventh
                      is "No colour", which is a value in this set and not an
                      escape from it — which is why it is a radio and not a
                      separate command.
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
            );
        }

        const orders: readonly SortOrder[] =
            which === 'files' ? FILE_ORDERS : SORT_LABELS.map((option) => option.value);
        const chosen = which === 'files' ? folder.sort_files : folder.sort_folders;

        return (
            <>
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
                        setSort.mutate({ id: folder.id, scope: which, order: null });
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
                                setSort.mutate({ id: folder.id, scope: which, order: value });
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

    function stepName(which: Step): string {
        return which === 'files'
            ? t('sortFiles', 'Files')
            : which === 'color'
              ? t('colourRow', 'Colour')
              : t('sortSubfolders', 'Subfolders');
    }

    // ------------------------------------------- step two (the narrow menu)
    if (!flyout && step !== null) {
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
                    {stepName(step)}
                </button>

                {options(step)}
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

                    {/*
                      The kind — tier 3 item 14. One checkbox row rather than
                      the board's two radios under a label: the menu is 497px
                      already, and "a gallery, or not" is one question. Among
                      the action rows, with their icon column, because a
                      tick slot among the Sort inside rows indented its label
                      18px past theirs (seen on the dev site). The label
                      never changes when pressed (the rule Start here set);
                      aria-checked and the tick beside the value carry the
                      state. Under a lock it is disabled like the other rows
                      that change what a folder is.
                    */}
                    {isMedia() ? (
                        <button
                            type="button"
                            role="menuitemcheckbox"
                            aria-checked={gallery}
                            className="folderfolio-menu__item"
                            disabled={blocked}
                            onClick={() => {
                                void kind
                                    .mutateAsync({ id: folder.id, kind: gallery ? 'folder' : 'gallery' })
                                    .catch(() => undefined);
                                onClose();
                            }}
                        >
                            <ImageIcon size={13} />
                            {t('galleryKind', 'Gallery')}
                            <span className="folderfolio-menu__value">
                                {gallery ? (
                                    <span className="folderfolio-menu__on" aria-hidden="true">
                                        ✓{' '}
                                    </span>
                                ) : null}
                                {t('galleryImagesOnly', 'images only')}
                            </span>
                        </button>
                    ) : null}

                    {/*
                      Colour — a step since 24 Sep (see the head of this file).
                      Among the action rows, its chip in their icon column: the
                      chip is the colour, or the dashed square No colour draws.
                      The value names it; with no colour it says nothing, the
                      dashed square already has. Allowed under a lock (answer 6).
                    */}
                    {download || isMedia() ? null : <div className="folderfolio-menu__rule" role="separator" />}

                    <button
                        ref={colorRef}
                        type="button"
                        role="menuitem"
                        className="folderfolio-menu__item folderfolio-menu__item--step"
                        aria-haspopup="menu"
                        {...opens('color')}
                    >
                        {current === null ? (
                            <span className="folderfolio-swatches__empty" aria-hidden="true" />
                        ) : (
                            <span
                                className="folderfolio-menu__chip"
                                aria-hidden="true"
                                style={{ background: `var(--ff-folder-${current})` }}
                            />
                        )}
                        {t('colourRow', 'Colour')}
                        <span className="folderfolio-menu__value">
                            {current === null ? '' : swatchLabel(current)}
                        </span>
                        <ChevronRightIcon size={12} />
                    </button>
                    {flyoutOf('color', t('folderColor', 'Folder colour'))}

                    <div className="folderfolio-menu__rule" role="separator" />

                    {/*
                      Each row says what it is set to without being opened:
                      the answer is on the first panel, and only changing it
                      costs a submenu (or, in the narrow menu, a step).
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
                        {...opens('folders')}
                    >
                        {t('sortSubfolders', 'Subfolders')}
                        <span className="folderfolio-menu__value">
                            {orderLabel(folder.sort_folders)}
                        </span>
                        <ChevronRightIcon size={12} />
                    </button>
                    {flyoutOf('folders', t('sortSubfolders', 'Subfolders'))}

                    {/* The file order is the media library's: a post list
                        keeps its own column sorting. */}
                    {isMedia() ? (
                        <button
                            ref={filesRef}
                            type="button"
                            role="menuitem"
                            className="folderfolio-menu__item folderfolio-menu__item--step"
                            aria-haspopup="menu"
                            {...opens('files')}
                        >
                            {t('sortFiles', 'Files')}
                            <span className="folderfolio-menu__value">
                                {orderLabel(folder.sort_files)}
                            </span>
                            <ChevronRightIcon size={12} />
                        </button>
                    ) : null}
                    {isMedia() ? flyoutOf('files', t('sortFiles', 'Files')) : null}


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
export function FolderMenu({ anchor, ...props }: FolderMenuProps & { anchor?: HTMLElement | null }) {
    // The control line's door below 782px is pinned to its button on the
    // body — see AnchoredMenu for why a menu cannot stay inside that line.
    if (anchor !== undefined) {
        return (
            <AnchoredMenu anchor={anchor} className="folderfolio-menu--folder" label={props.folder.name} onClose={props.onClose}>
                <FolderMenuItems {...props} />
            </AnchoredMenu>
        );
    }

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
    onDismiss,
    ...props
}: FolderMenuProps & {
    anchor: HTMLElement | null;
    /** Closed because focus went elsewhere: close, and leave focus there. */
    onDismiss: () => void;
}) {
    const { ref, style } = useAnchoredPanel<HTMLDivElement>(anchor, props.onClose);

    /*
     * Keyboard entry and exit — review H3.
     *
     * Focus goes to the first item when the menu opens, as `Menu` and
     * `AnchoredMenu` already do; Escape (from `useAnchoredPanel`) closes it
     * and `Row` puts focus back on the ⋮. Tabbing to anything outside closes
     * it too, and leaves focus where it went. That is a `focusin` somewhere
     * else rather than a `blur` here: a click on a button does not focus it
     * in Safari, so a blur-closed menu would close under the pointer before
     * the click that chose a swatch.
     */
    useEffect(() => {
        ref.current?.querySelector<HTMLElement>('button:not(:disabled)')?.focus({ preventScroll: true });

        const onFocusIn = (event: FocusEvent) => {
            const target = event.target as Node | null;

            if (target && !ref.current?.contains(target) && !anchor?.contains(target)) {
                onDismiss();
            }
        };

        document.addEventListener('focusin', onFocusIn);

        return () => document.removeEventListener('focusin', onFocusIn);
    }, [anchor, onDismiss]);

    return createPortal(
        <div
            ref={ref}
            style={style}
            className="folderfolio folderfolio-menu folderfolio-menu--row"
            role="menu"
            aria-label={props.folder.name}
        >
            <FolderMenuItems {...props} flyout />
        </div>,
        document.body
    );
}
