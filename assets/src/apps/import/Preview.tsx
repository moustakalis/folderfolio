/**
 * Step 2 — what pressing Import would do.
 *
 * The heading says "nothing has been written yet" because that is the claim
 * the screen is making, and the planner is read-only so that the claim is
 * true. Every line is a count somebody would have wanted before running
 * CatFolders' importer over a library they had already organised.
 *
 * The detail beside each count is not decoration. "Added, never moved" is the
 * single most important sentence on this screen: it is the property that makes
 * this import safe to run twice, and it is the one every competitor's importer
 * gets wrong.
 */

import { t, tn } from '../../core/api';
import type { PlanState } from './queries';

export function Preview({
    plan,
    loading,
    error,
    starting,
    onImport,
    onCancel,
}: {
    plan: PlanState | null;
    loading: boolean;
    error: string | null;
    starting: boolean;
    onImport: () => void;
    onCancel: () => void;
}) {
    if (error) {
        return (
            <div className="folderfolio-wizard__error" role="alert">
                {error}
            </div>
        );
    }

    if (loading || !plan) {
        return <p className="folderfolio-wizard__lede">{t('importPlanning', 'Reading the folders…')}</p>;
    }

    const { counts, samples } = plan;

    const lines: Array<{ label: string; detail: string } | null> = [
        /*
         * A file's own line — what it is, and whether its files come too.
         * Assignments name one site's attachments by number, so from another
         * site they are left out rather than filed onto whatever carries the
         * same numbers here (see JsonSource). First, so "0 files added" further
         * down is never the first hint.
         */
        plan.file
            ? plan.file.same_site
                ? {
                      label: t('importFileSame', 'An export of this site'),
                      detail: t(
                          'importFileSameDetail',
                          'Colours, galleries and orders come with the folders it creates; folders that already exist keep their own.'
                      ),
                  }
                : {
                      label: t('importFileOther', 'An export of another site'),
                      detail:
                          plan.file.assignments_in_file > 0
                              ? tn(
                                    'importFileOtherFile',
                                    'importFileOtherFiles',
                                    plan.file.assignments_in_file,
                                    'Only the folders are imported. Its %1$s file assignment names a file on %2$s by number, and that number is a different file here.',
                                    'Only the folders are imported. Its %1$s file assignments name files on %2$s by number, and those numbers are different files here.',
                                    plan.file.assignments_in_file,
                                    plan.file.site
                                )
                              : t(
                                    'importFileOtherNone',
                                    'Only the folders are imported — it carries no file assignments. Colours, galleries and orders come with the folders it creates.'
                                ),
                  }
            : null,
        counts.create > 0
            ? {
                  label: tn(
                      'importCreateOne',
                      'importCreateMany',
                      counts.create,
                      '%s folder created',
                      '%s folders created',
                      counts.create
                  ),
                  detail: samples.create.join(', ') + (counts.create > samples.create.length ? '…' : ''),
              }
            : null,
        counts.merge > 0
            ? {
                  label: tn(
                      'importMergeOne',
                      'importMergeMany',
                      counts.merge,
                      '%s folder merged by name',
                      '%s folders merged by name',
                      counts.merge
                  ),
                  detail: t(
                      'importMergeDetail',
                      '%s already exist — files are added to yours, not duplicated.',
                      samples.merge.join(', ')
                  ),
              }
            : null,
        counts.duplicate > 0
            ? {
                  label: tn(
                      'importDupOne',
                      'importDupMany',
                      counts.duplicate,
                      '%s duplicate name collapsed',
                      '%s duplicate names collapsed',
                      counts.duplicate
                  ),
                  detail: t(
                      'importDupDetail',
                      '%1$s has more than one folder called %2$s in the same place. They become one folder here, holding both sets of files.',
                      plan.label,
                      samples.duplicate.join(', ')
                  ),
              }
            : null,
        counts.reconcile > 0
            ? {
                  label: tn(
                      'importAgainOne',
                      'importAgainMany',
                      counts.reconcile,
                      '%s folder already imported',
                      '%s folders already imported',
                      counts.reconcile
                  ),
                  detail: t(
                      'importAgainDetail',
                      'A previous import made these. They are matched by where they came from, so renaming one did not break the link.'
                  ),
              }
            : null,
        {
            label: tn(
                'importFilesOne',
                'importFilesMany',
                counts.files,
                '%s file added to folders',
                '%s files added to folders',
                counts.files
            ),
            detail:
                counts.already > 0
                    ? t(
                          'importFilesDetailAlready',
                          'Added, never moved: nothing leaves a folder you made. %s are already filed where this would put them.',
                          counts.already
                      )
                    : t('importFilesDetail', 'Added, never moved: nothing leaves a folder you made.'),
        },
        (counts.not_images ?? 0) > 0
            ? {
                  label: tn(
                      'importNotImagesOne',
                      'importNotImagesMany',
                      counts.not_images ?? 0,
                      '%s file left out of a gallery',
                      '%s files left out of a gallery',
                      counts.not_images ?? 0
                  ),
                  detail: t(
                      'importNotImagesDetail',
                      'A gallery holds images only. These are not images, so they stay where they are.'
                  ),
              }
            : null,
        counts.skipped > 0
            ? {
                  label: tn(
                      'importSkipOne',
                      'importSkipMany',
                      counts.skipped,
                      '%s file skipped',
                      '%s files skipped',
                      counts.skipped
                  ),
                  detail: t(
                      'importSkipDetail',
                      'Attachments %s are referenced but no longer exist in the media library.',
                      samples.skipped.join(', ')
                  ),
              }
            : null,
        counts.unreachable > 0
            ? {
                  label: tn(
                      'importStrandedOne',
                      'importStrandedMany',
                      counts.unreachable,
                      '%s folder could not be placed',
                      '%s folders could not be placed',
                      counts.unreachable
                  ),
                  detail: t(
                      'importStrandedDetail',
                      'Their parent is missing in %1$s, or they sit in a loop. %2$s',
                      plan.label,
                      samples.unreachable.join(', ')
                  ),
              }
            : null,
    ];

    return (
        <>
            <div className="folderfolio-wizard__head">
                <h3 className="folderfolio-wizard__title">
                    {t('importPreviewTitle', 'Preview — %s', plan.label)}
                </h3>
                <span className="folderfolio-wizard__eyebrow">
                    {t('importNothingWritten', 'Nothing has been written yet')}
                </span>
            </div>
            <p className="folderfolio-wizard__lede">
                {t('importPreviewLede', 'This is what pressing Import would do.')}
            </p>

            <div className="folderfolio-wizard__lines">
                {lines.filter((line) => line !== null).map((line) => (
                    <div className="folderfolio-wizard__line" key={line.label}>
                        <div className="folderfolio-wizard__lineLabel">{line.label}</div>
                        <div className="folderfolio-wizard__lineDetail">{line.detail}</div>
                    </div>
                ))}
            </div>

            <div className="folderfolio-wizard__actions">
                <button
                    type="button"
                    className="button button-primary"
                    onClick={onImport}
                    disabled={starting}
                >
                    {counts.create > 0
                        ? tn(
                              'importRunOne',
                              'importRunMany',
                              counts.create,
                              'Import %s folder',
                              'Import %s folders',
                              counts.create
                          )
                        : t('importRunFiles', 'Import')}
                </button>
                <button type="button" className="button" onClick={onCancel} disabled={starting}>
                    {t('importCancel', 'Cancel')}
                </button>
                <span className="folderfolio-wizard__note">
                    {t('importNoAccount', 'No account, no licence, no telemetry.')}
                </span>
            </div>
        </>
    );
}
