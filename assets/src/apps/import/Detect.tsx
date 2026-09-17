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

import { t, tn } from '../../core/api';
import type { DetectedSource } from './queries';

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
