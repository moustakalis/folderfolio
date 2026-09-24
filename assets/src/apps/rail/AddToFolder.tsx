/**
 * The bulk folder action — one trigger in WordPress's toolbar, and the flyout
 * drawn on screen 11.
 *
 * ## Why there is one, having been two
 *
 * With a pointer this plugin has two verbs: dragging files onto a folder
 * *moves* them out of the folder being viewed, and this flyout *adds*. With a
 * keyboard there was only one, because a drag has no keyboard form — so a
 * keyboard user could file a copy but could not do the thing every mouse user
 * can. That gap is real and is still closed here; what changed is where.
 *
 * It used to be closed with a second toolbar trigger, `Move to folder`, sitting
 * beside this one and disabled unless a real folder was being viewed. Three
 * things were wrong with that. Screens 03 and 06 draw **one** button, and so
 * does the only competitor that puts a folder action in the toolbar at all —
 * the other three put none. The pair cost about 250px of a row that has no
 * room to spare, and it spent it while **disabled**, which is the state both
 * buttons are in whenever nothing is selected, which is most of the time. And a
 * verb that is only available sometimes reads better as an option inside the
 * panel than as a button that is usually grey.
 *
 * So the verb moved into the flyout, where it is a choice between two named
 * things rather than a control whose absence has to be explained. The rest is
 * unchanged: a move still takes one destination and offers radios, an add takes
 * many and offers checkboxes, and both still call the same mutations the drag
 * calls, so the pointer and the keyboard cannot drift apart.
 *
 * ## Add adds, never moves
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

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

import { SearchIcon } from './icons';
import {
    flattenTree,
    useAddToFolders,
    useMoveAttachments,
    useOrderFiles,
    type FolderNode,
} from './queries';
import { sortTree, useRail } from './store';
import { useAnchoredPanel } from './useAnchoredPanel';
import { can } from '../../lib/can';
import { watchSelection } from '../../lib/selection';
import { isMedia, t, tn } from '../../core/api';

/**
 * How many folder rows the flyout renders at once.
 *
 * Six are visible; 100 is enough that scrolling still feels like a list rather
 * than a page of results, and cheap enough to be at the floor of what a render
 * costs at all. See the ordering note in Flyout.
 */
const LIST_LIMIT = 100;

export type PickerMode = 'add' | 'move';

export function AddToFolder({ nodes }: { nodes: FolderNode[] }) {
    const [ids, setIds] = useState<number[]>([]);
    const [open, setOpen] = useState(false);
    const triggerRef = useRef<HTMLButtonElement>(null);

    /**
     * Only a real folder is a source.
     *
     * `null` is All media, which is not a folder; `0` is Unassigned, where
     * "move out of" and "add to" are the same operation, so Add is the only
     * verb that means anything and Move is offered greyed with a reason.
     */
    const selectedId = useRail((s) => s.selectedId);
    const source = selectedId !== null && selectedId > 0 ? selectedId : null;

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
                // One reason to be disabled now, and it needs no explanation:
                // nothing is selected, which is visible on the screen behind it.
                disabled={ids.length === 0}
                aria-haspopup="dialog"
                aria-expanded={open}
                onClick={() => setOpen((was) => !was)}
            >
                {/*
                  An ellipsis, not a caret.

                  Something has to say this opens a picker rather than filing
                  immediately, because `Apply` sits right beside it in list mode
                  and does act immediately. The board draws a caret here; an
                  ellipsis carries the same meaning by an older and quieter
                  convention, and costs about 6px of a row with none to spare
                  instead of about 21. Recorded as a deliberate deviation.
                */}
                {t('addToFolderOpens', 'Add to folder…')}
            </button>

            {open ? (
                createPortal(
                    <Flyout
                        nodes={nodes}
                        ids={ids}
                        source={source}
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
    /** The folder being moved out of, when there is one. */
    source: number | null;
    anchor: HTMLElement | null;
    onClose: () => void;
}

function Flyout({ nodes, ids, source, anchor, onClose }: FlyoutProps) {
    const sort = useRail((s) => s.sort);

    /**
     * Always opens on Add.
     *
     * Add is the verb that works from anywhere — All media, Unassigned, or
     * inside a folder — and it is the one that cannot lose anything. A panel
     * that remembered `move` from last time would be a panel that sometimes
     * takes files out of a folder because of something you did ten minutes ago.
     */
    const [mode, setMode] = useState<PickerMode>('add');
    const [checked, setChecked] = useState<ReadonlySet<number>>(new Set());
    const [query, setQuery] = useState('');
    const add = useAddToFolders();
    const move = useMoveAttachments();
    const order = useOrderFiles();
    const pending = mode === 'move' ? move.isPending : add.isPending;
    const failed = mode === 'move' ? move.isError : add.isError;

    /* Pinned to the trigger, dismissed on Escape or a pointer outside.
       Both are useAnchoredPanel's, shared with the narrow-width folder
       picker so the two panels cannot drift apart. */
    const { ref, style } = useAnchoredPanel<HTMLDivElement>(anchor, onClose);

    // Sorting and flattening the whole tree is the one cost here that does not
    // depend on what is typed, so it must not be paid per keystroke. At 20,000
    // folders that alone was most of the 348ms a character cost.
    const rows = useMemo(() => {
        const all = flattenTree(sortTree(nodes, sort));

        // Moving a folder's files into the folder they are already in is a
        // no-op the drag catches before it sends. Here there is room to simply
        // not offer it.
        return source === null ? all : all.filter((row) => row.node.id !== source);
    }, [nodes, sort, source]);
    const needle = query.trim().toLowerCase();
    const matches = needle === ''
        ? rows
        : rows
            // A ticked folder always matches. It is about to be written to,
            // and a list that hides what it is about to do is worse than a
            // list that shows one row you did not search for.
            .filter(
                (row) =>
                    checked.has(row.node.id)
                    || row.node.name.toLowerCase().includes(needle)
            )
            // A filtered list is not a tree any more — the parents that gave
            // the indent its meaning are not all there. Flattening it is
            // honest; keeping the indent would draw a hierarchy that is not
            // on screen.
            .map((row) => ({ ...row, depth: 0 }));

    /*
     * Ticked folders first, then the rest, then a cap.
     *
     * The list box is 168px — six rows — and a library filed one folder per
     * product has thousands. Rendering them all to show six cost 227ms to open
     * at 1,000 folders and 154ms per keystroke in the search above, measured;
     * at 5,000 it is 413ms and 257ms. This is a picker with a search field
     * directly above it, so the answer is to render a page of it and say so,
     * not to render all of it.
     *
     * Capping rather than windowing is deliberate. A windowed list only has
     * the rows near the scroll position in the DOM, and a checkbox that is not
     * in the DOM cannot be reached with Tab — so windowing would trade a
     * measurable delay for an invisible keyboard dead end. Everything rendered
     * here is tabbable, which is the contract the rest of this plugin keeps.
     *
     * What makes the cap safe is that a ticked folder can never be the thing
     * the cap drops — it is pulled to the front first. Only when the cap is
     * actually in play, though: reordering the list the instant you tick
     * something would make the row you just clicked jump out from under the
     * pointer, which is a worse bug than the one it prevents.
     */
    const ordered =
        matches.length > LIST_LIMIT
            ? [
                  ...matches.filter((row) => checked.has(row.node.id)),
                  ...matches.filter((row) => !checked.has(row.node.id)),
              ]
            : matches;

    const shown = ordered.slice(0, LIST_LIMIT);
    const hidden = ordered.length - shown.length;

    const sourceName =
        source === null
            ? ''
            : (flattenTree(nodes).find((row) => row.node.id === source)?.node.name ?? '');

    /**
     * Adding takes any number of destinations; a move takes exactly one.
     *
     * Which is why the rows are checkboxes in one mode and radios in the
     * other — the control says how many answers it wants before you give it
     * the wrong number.
     */
    const toggle = useCallback(
        (id: number) => {
            setChecked((was) => {
                if (mode === 'move') {
                    return was.has(id) ? new Set<number>() : new Set([id]);
                }

                const next = new Set(was);

                if (!next.delete(id)) {
                    next.add(id);
                }

                return next;
            });
        },
        [mode]
    );

    const submit = () => {
        if (checked.size === 0 || pending) {
            return;
        }

        const folderIds = [...checked];
        const names = rows
            .filter((row) => folderIds.includes(row.node.id))
            .map((row) => row.node.name)
            .join(', ');

        if (mode === 'move') {
            move.mutate(
                {
                    ids,
                    sourceFolderId: source,
                    destinationFolderId: folderIds[0],
                },
                {
                    onSuccess: () => {
                        window.wp?.a11y?.speak(
                            tn(
                                'movedFile',
                                'movedFiles',
                                ids.length,
                                'Moved %s file to %s',
                                'Moved %s files to %s',
                                ids.length,
                                names
                            ),
                            'polite'
                        );
                        onClose();
                    },
                }
            );

            return;
        }

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
            aria-label={
                mode === 'move'
                    ? t('moveToFolder', 'Move to folder')
                    : t('addToFolder', 'Add to folder')
            }
            style={style}
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

            {/*
              Where the selection sits in the folder being viewed — tier 2
              item 8. The keyboard's and a phone's way to arrange a folder,
              and the way to move a file further than a drag can reach. Like
              the drag, it makes the folder Custom.
            */}
            {source !== null && can('rename') && isMedia() ? (
                <div
                    className="folderfolio-flyout__arrange"
                    role="group"
                    aria-label={t('arrangeIn', 'In %s', sourceName)}
                >
                    <span className="folderfolio-flyout__arrange-label" aria-hidden="true">
                        {t('arrangeIn', 'In %s', sourceName)}
                    </span>
                    {(['start', 'end'] as const).map((place) => (
                        <button
                            key={place}
                            type="button"
                            className="folderfolio-flyout__place"
                            disabled={order.isPending}
                            onClick={() => {
                                void order
                                    .mutateAsync({ folderId: source, ids, place })
                                    .then(() => {
                                        window.wp?.a11y?.speak(
                                            place === 'start'
                                                ? tn(
                                                      'placedFileStart',
                                                      'placedFilesStart',
                                                      ids.length,
                                                      'Moved %s file to the start of %s',
                                                      'Moved %s files to the start of %s',
                                                      ids.length,
                                                      sourceName
                                                  )
                                                : tn(
                                                      'placedFileEnd',
                                                      'placedFilesEnd',
                                                      ids.length,
                                                      'Moved %s file to the end of %s',
                                                      'Moved %s files to the end of %s',
                                                      ids.length,
                                                      sourceName
                                                  ),
                                            'polite'
                                        );
                                        onClose();
                                    })
                                    // Shown by the rail's MutationCache.
                                    .catch(() => undefined);
                            }}
                        >
                            {place === 'start'
                                ? t('moveToStart', 'Move to start')
                                : t('moveToEnd', 'Move to end')}
                        </button>
                    ))}
                </div>
            ) : null}

            {/*
              The verb, where the verb's consequences are.

              A radiogroup rather than two buttons or a select: there are
              exactly two, both are worth reading, and which one is chosen
              changes what the rows below do — a select would hide half the
              answer behind a click, and two plain buttons would not say that
              picking one unpicks the other.

              `Move to` is disabled outside a folder rather than absent. Its
              absence would be unexplainable; greyed with a title, it says
              what to do about it — the same sentence the old second trigger
              carried, now attached to the thing it is actually about.
            */}
            <div
                className="folderfolio-flyout__verbs"
                role="radiogroup"
                aria-label={t('folderAction', 'What to do with the selection')}
            >
                <button
                    type="button"
                    role="radio"
                    aria-checked={mode === 'add'}
                    className={`folderfolio-flyout__verb${mode === 'add' ? ' is-on' : ''}`}
                    onClick={() => setMode('add')}
                >
                    {t('verbAdd', 'Add to')}
                </button>
                <button
                    type="button"
                    role="radio"
                    aria-checked={mode === 'move'}
                    disabled={source === null}
                    title={
                        source === null
                            ? t(
                                  'moveNeedsFolder',
                                  'Open a folder first — a move needs a folder to move out of.'
                              )
                            : undefined
                    }
                    className={`folderfolio-flyout__verb${mode === 'move' ? ' is-on' : ''}`}
                    onClick={() => {
                        setMode('move');

                        // A move has one destination. Coming from Add with
                        // three ticked, keeping them would arm a button that
                        // cannot do what the ticks say — so the extras go now,
                        // while the list is on screen to show it happening.
                        setChecked((was) =>
                            was.size > 1 ? new Set([[...was][0] as number]) : was
                        );
                    }}
                >
                    {t('verbMove', 'Move to')}
                </button>
            </div>

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
                                type={mode === 'move' ? 'radio' : 'checkbox'}
                                name={mode === 'move' ? 'folderfolio-move-to' : undefined}
                                checked={checked.has(node.id)}
                                onChange={() => toggle(node.id)}
                            />
                            <span className="folderfolio-flyout__name">{node.name}</span>
                        </label>
                    ))
                )}

                {/*
                  Never a silent cap. A picker that quietly stops listing is a
                  picker that appears not to contain the folder you are looking
                  for.
                */}
                {hidden > 0 ? (
                    <p className="folderfolio-flyout__more">
                        {t(
                            'andMoreFolders',
                            '%s more — keep typing to narrow',
                            hidden
                        )}
                    </p>
                ) : null}
            </div>

            <div className="folderfolio-flyout__foot">
                <button
                    type="button"
                    className="button button-primary"
                    disabled={checked.size === 0 || pending}
                    onClick={submit}
                >
                    {mode === 'move'
                        ? t('moveToFolder', 'Move to folder')
                        : t('addToFolder', 'Add to folder')}
                </button>

                {/*
                  The sentence is the one place the screen says which of the
                  two verbs this is. For a move it names the folder the files
                  are leaving, because that is the part that is not visible
                  anywhere else in the panel.
                */}
                <span className="folderfolio-flyout__note">
                    {failed
                        ? mode === 'move'
                            ? t('moveFailed', 'Could not move those files.')
                            : t('addFailed', 'Could not file those files.')
                        : mode === 'move'
                          ? t('movesOutOf', 'Moves them out of “%s”', sourceName)
                          : t('addsACopy', 'Keeps them in their other folders too')}
                </span>
            </div>
        </div>
    );
}
