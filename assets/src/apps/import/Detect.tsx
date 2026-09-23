/**
 * Step 1 — what this site has to import from.
 *
 * Every source is listed, including the ones with nothing in them. A list that
 * silently omits the plugin somebody came here for reads as a plugin that
 * cannot import it, and "Real Media Library — no data found" is the answer to
 * the question they actually had.
 *
 * The status line under each name carries the distinction the whole detection
 * layer exists for: **data present** and **plugin active** are different
 * facts, and the interesting case — the one competitors' importers miss — is a
 * plugin deactivated a year ago whose folders are still in the database.
 */

import { useRef } from 'react';

import { errorMessage, t, tn } from '../../core/api';
import { useImportFile, type DetectedSource } from './queries';

export function Detect({
    sources,
    loading,
    onChoose,
}: {
    sources: DetectedSource[];
    loading: boolean;
    onChoose: (key: string) => void;
}) {
    if (loading) {
        return <p className="folderfolio-wizard__lede">{t('importDetecting', 'Looking for folders to import…')}</p>;
    }

    const withData = sources.filter((source) => source.has_data);

    return (
        <>
            <h3 className="folderfolio-wizard__title">
                {t('importTitle', 'Import from another plugin')}
            </h3>
            <p className="folderfolio-wizard__lede">
                {t(
                    'importLede',
                    'FolderFolio reads the data these plugins left behind. Nothing is written until you have read the preview, and nothing is ever removed from a folder you made.'
                )}
            </p>

            {withData.length === 0 && (
                <p className="folderfolio-wizard__empty">
                    {t(
                        'importNothing',
                        'No folder data from another plugin was found on this site.'
                    )}
                </p>
            )}

            <div className="folderfolio-wizard__sources">
                {sources.map((source) => (
                    <div className="folderfolio-source" key={source.key}>
                        <div className="folderfolio-source__body">
                            <div
                                className={
                                    source.has_data
                                        ? 'folderfolio-source__name'
                                        : 'folderfolio-source__name is-empty'
                                }
                            >
                                {source.label}
                            </div>
                            <div className="folderfolio-source__detail">
                                {source.has_data
                                    ? `${tn(
                                          'importFolderOne',
                                          'importFolderMany',
                                          source.folders,
                                          '%s folder',
                                          '%s folders',
                                          source.folders
                                      )}, ${tn(
                                          'importFileOne',
                                          'importFileMany',
                                          source.assignments,
                                          '%s file assignment',
                                          '%s file assignments',
                                          source.assignments
                                      )} · ${
                                          source.plugin_active
                                              ? t('importActive', 'plugin active')
                                              : t(
                                                    'importInactive',
                                                    'plugin deactivated, data still present'
                                                )
                                      }`
                                    : t('importNoData', 'No data found')}
                            </div>
                        </div>

                        {source.has_data ? (
                            <button
                                type="button"
                                className="button button-primary"
                                onClick={() => onChoose(source.key)}
                            >
                                {t('importPreview', 'Preview import')}
                            </button>
                        ) : (
                            <span className="folderfolio-source__none">
                                {t('importNothingToImport', 'Nothing to import')}
                            </span>
                        )}
                    </div>
                ))}

                <FileRow onChoose={onChoose} />
            </div>

            <p className="folderfolio-wizard__note">
                {t(
                    'importTwoQuestions',
                    'Two questions, kept apart: which sources hold data — including plugins you have since deactivated — and which are worth offering in the rail right now.'
                )}
            </p>
        </>
    );
}

/**
 * The one source that is not detected — tier 1 item 6b.
 *
 * Last in the list and drawn like the others, because once it is chosen it
 * *is* like the others: the same preview, the same run, the same report and
 * the same undo. What differs is only how it arrives — a file the person
 * picks, rather than rows the site already has — so its action is a file
 * button where the others have *Preview import*, and choosing a file is what
 * takes the wizard to step 2.
 *
 * The file input is visually hidden and driven by a real button, not styled
 * in place: a native file input's own text ("No file chosen") cannot be
 * translated from here and does not match the row's other actions.
 */
function FileRow({ onChoose }: { onChoose: (key: string) => void }) {
    const input = useRef<HTMLInputElement>(null);
    const read = useImportFile();

    return (
        <div className="folderfolio-source">
            <div className="folderfolio-source__body">
                <div className="folderfolio-source__name">
                    {t('importFileName', 'FolderFolio export file')}
                </div>
                <div className="folderfolio-source__detail">
                    {t(
                        'importFileDetail',
                        'A .json file saved with Export folders — from this site, or from another one.'
                    )}
                </div>

                {read.error ? (
                    <p className="folderfolio-wizard__error" role="alert">
                        {errorMessage(read.error)}
                    </p>
                ) : null}
            </div>

            <input
                ref={input}
                type="file"
                accept=".json,application/json"
                className="screen-reader-text"
                tabIndex={-1}
                aria-hidden="true"
                onChange={(event) => {
                    const file = event.target.files?.[0];

                    // Cleared, so choosing the same file again after fixing
                    // it still fires a change.
                    event.target.value = '';

                    if (file) {
                        read.mutate(file, { onSuccess: (source) => onChoose(source.key) });
                    }
                }}
            />

            <button
                type="button"
                className="button button-secondary"
                disabled={read.isPending}
                onClick={() => input.current?.click()}
            >
                {read.isPending
                    ? t('importFileReading', 'Reading the file…')
                    : t('importFileChoose', 'Choose file…')}
            </button>
        </div>
    );
}
