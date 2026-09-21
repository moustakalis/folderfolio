/**
 * Step 4 — what happened, and the way back.
 *
 * Two things make this screen worth the code. The **skipped list names ids**,
 * because "2 files skipped" is a number and "attachments 118 and 204 no longer
 * exist in the media library" is something a person can act on. And **Undo**
 * exists at all: running a competitor's importer over an organised library is
 * a one-way door, and the reason this module has a run record is so that this
 * door opens both ways.
 *
 * Undo is conservative by design — it removes the folders this run created and
 * the assignments it made, and keeps any folder somebody has put their own
 * files into since. That is said on the screen, because an undo whose limits
 * are a surprise is worse than no undo.
 *
 * ## And the line about turning the other plugin off
 *
 * This is the only moment at which "you can deactivate FileBird now" is both
 * **safe** and **obviously right**: their folders are already here, the import
 * added and never moved, and the person is looking at the proof. Saying it on
 * install would be asking somebody to take a working system down to try an
 * unknown one, which is the moment they deactivate ours instead — so we share
 * the screen for as long as it takes to earn the switch, and offer the switch
 * only once it has been earned. None of the four plugins on the market does
 * this; three ask you to import and none of them tells you that you are now
 * carrying two.
 *
 * Gated on the plugin still being on, which is a fact about the site now
 * rather than part of the stored run — see
 * `Rest\ImportController::runPayload()`.
 */

import { t, tn } from '../../core/api';
import type { RunState } from './queries';

/**
 * The finished sentence, assembled from the parts that are not zero.
 *
 * A fixed template gave "26 folders created, 0 merged by name, 36 files
 * added" on the first real run — and a zero in a summary is a fact nobody
 * asked for taking up the space of one they did.
 */
function summary(run: RunState): string {
    const parts: string[] = [];

    if (run.folders_created > 0) {
        parts.push(
            tn(
                'importDoneCreatedOne',
                'importDoneCreatedMany',
                run.folders_created,
                '%s folder created',
                '%s folders created',
                run.folders_created
            )
        );
    }

    if (run.folders_merged > 0) {
        parts.push(
            tn(
                'importDoneMergedOne',
                'importDoneMergedMany',
                run.folders_merged,
                '%s merged by name',
                '%s merged by name',
                run.folders_merged
            )
        );
    }

    if (run.files_added > 0) {
        parts.push(
            tn(
                'importDoneAddedOne',
                'importDoneAddedMany',
                run.files_added,
                '%s file added',
                '%s files added',
                run.files_added
            )
        );
    }

    if (parts.length === 0) {
        return t('importDoneNothing', 'Nothing needed importing — everything was already here.');
    }

    return t(
        'importDoneSentence',
        '%s. No file left a folder you had already made.',
        parts.join(', ')
    );
}

export function Report({
    run,
    working,
    onUndo,
    onDone,
}: {
    run: RunState;
    working: boolean;
    onUndo: () => void;
    onDone: () => void;
}) {
    const undone = run.status === 'undone';

    return (
        <>
            <h3 className="folderfolio-wizard__title">
                {undone
                    ? t('importUndoneTitle', 'Import undone')
                    : t('importDoneTitle', 'Import finished')}
            </h3>

            <p className="folderfolio-wizard__lede">
                {undone
                    ? t(
                          'importUndoneLede',
                          'The folders this import created are gone, and so are the files it filed. Anything you had already made was left alone.'
                      )
                    : summary(run)}
            </p>

            {!undone && run.source_plugin_active && (
                <p className="folderfolio-wizard__note folderfolio-wizard__retire">
                    {t(
                        'importRetire',
                        '%s is still switched on. Its folders are here now, and nothing in FolderFolio needs it running — so you can deactivate it whenever you like.',
                        run.label
                    )}{' '}
                    <a href={window.folderFolio?.pluginsUrl ?? 'plugins.php'}>
                        {t('importRetireLink', 'Open Plugins')}
                    </a>
                </p>
            )}

            {run.duplicates_collapsed > 0 && !undone && (
                <p className="folderfolio-wizard__note">
                    {t(
                        'importDupNote',
                        '%1$s had %2$s duplicate folder names in the same place. Each set became one folder here, holding all of their files.',
                        run.label,
                        run.duplicates_collapsed
                    )}
                </p>
            )}

            {run.error !== '' && (
                <div className="folderfolio-wizard__error" role="alert">
                    {run.error}
                </div>
            )}

            {run.skipped_total > 0 && (
                <div className="folderfolio-wizard__panel">
                    <div className="folderfolio-wizard__panelTitle">
                        {tn(
                            'importSkipOne',
                            'importSkipMany',
                            run.skipped_total,
                            'Skipped — %s file',
                            'Skipped — %s files',
                            run.skipped_total
                        )}
                    </div>
                    <div className="folderfolio-wizard__panelBody">
                        {t(
                            'importSkippedIds',
                            'Attachments %1$s are referenced by %2$s but no longer exist in the media library.',
                            run.skipped.slice(0, 20).join(', ') +
                                (run.skipped_total > 20 ? '…' : ''),
                            run.label
                        )}
                    </div>
                </div>
            )}

            {run.unreachable.length > 0 && (
                <div className="folderfolio-wizard__panel">
                    <div className="folderfolio-wizard__panelTitle">
                        {tn(
                            'importStrandedOne',
                            'importStrandedMany',
                            run.unreachable.length,
                            'Not placed — %s folder',
                            'Not placed — %s folders',
                            run.unreachable.length
                        )}
                    </div>
                    <div className="folderfolio-wizard__panelBody">
                        {t(
                            'importStrandedNames',
                            'Their parent is missing, or they sit in a loop: %s. They were brought over at the top level rather than dropped.',
                            run.unreachable.map((row) => row.name).join(', ')
                        )}
                    </div>
                </div>
            )}

            <div className="folderfolio-wizard__actions">
                <a className="button button-primary" href={window.folderFolio?.uploadUrl ?? 'upload.php'}>
                    {t('importOpenLibrary', 'Open Media Library')}
                </a>

                {run.can_undo && (
                    <button
                        type="button"
                        className="button folderfolio-wizard__undo"
                        onClick={onUndo}
                        disabled={working}
                    >
                        {t('importUndo', 'Undo this import')}
                    </button>
                )}

                <button type="button" className="button" onClick={onDone} disabled={working}>
                    {t('importBack', 'Back to the sources')}
                </button>
            </div>

            <p className="folderfolio-wizard__note">
                {run.can_undo
                    ? t(
                          'importUndoNote',
                          'Undo removes the folders this import created and the files it filed. A folder you have added your own files to since is kept.'
                      )
                    : t(
                          'importProvenanceNote',
                          'Every folder records where it came from, so a second run reconciles instead of duplicating.'
                      )}
            </p>
        </>
    );
}
