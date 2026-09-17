/**
 * Step 3 — the import running.
 *
 * "You can leave this page" is a promise, and what keeps it is that the cursor
 * is a row in the options table rather than a variable in this component. This
 * screen drives the batches while it is open and reports what the server says;
 * it does not own the run.
 *
 * The bar is `progress`, computed on the server from the same cursor the run
 * resumes at, so the bar and the truth cannot drift apart — the same rule the
 * undo toast follows for the same reason.
 */

import { t, tn } from '../../core/api';
import type { RunState } from './queries';

export function Progress({ run, onStop }: { run: RunState; onStop: () => void }) {
    const stopping = run.status === 'stopping';

    return (
        <>
            <h3 className="folderfolio-wizard__title">{t('importRunning', 'Importing')}</h3>
            <p className="folderfolio-wizard__lede">
                {t(
                    'importLeaveLede',
                    'You can leave this page — the import continues and picks up where it left off.'
                )}
            </p>

            <div
                className="folderfolio-wizard__bar"
                role="progressbar"
                aria-valuenow={run.progress}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-label={t('importProgressLabel', 'Import progress')}
            >
                <span style={{ width: `${run.progress}%` }} />
            </div>

            <div className="folderfolio-wizard__barLine">
                <span>
                    {t(
                        'importProgressOf',
                        'Folders — %1$s of %2$s',
                        run.cursor,
                        run.total
                    )}
                </span>
                <span className="folderfolio-wizard__pct">{run.progress}%</span>
            </div>

            <div className="folderfolio-wizard__tally">
                <div>
                    {tn(
                        'importCreatedOne',
                        'importCreatedMany',
                        run.folders_created,
                        '%s folder created',
                        '%s folders created',
                        run.folders_created
                    )}
                </div>
                <div>
                    {tn(
                        'importMergedOne',
                        'importMergedMany',
                        run.folders_merged,
                        '%s folder merged into an existing name',
                        '%s folders merged into existing names',
                        run.folders_merged
                    )}
                </div>
                {run.duplicates_collapsed > 0 && (
                    <div>
                        {tn(
                            'importDupCollapsedOne',
                            'importDupCollapsedMany',
                            run.duplicates_collapsed,
                            '%s duplicate name collapsed',
                            '%s duplicate names collapsed',
                            run.duplicates_collapsed
                        )}
                    </div>
                )}
                <div>
                    {tn(
                        'importAddedOne',
                        'importAddedMany',
                        run.files_added,
                        '%s file added',
                        '%s files added',
                        run.files_added
                    )}
                </div>
            </div>

            <button type="button" className="button" onClick={onStop} disabled={stopping}>
                {stopping
                    ? t('importStopping', 'Stopping…')
                    : t('importStop', 'Stop after this folder')}
            </button>
        </>
    );
}
