/**
 * Many folders from a list — tier 1 item 4, on the Tools tab.
 *
 * ## Why this is not a dialog in the rail
 *
 * The plan called for a modal, and gave the reason in the same breath: so
 * that there is no rail layout to solve and no width sweep to run. This
 * screen delivers that reason more completely than a modal would, and it was
 * a measurement that settled it — the rail header at its narrowest already
 * clips by 12px with the brand and `New folder` on it, so there is no room
 * for the control that would open the dialog. Given a door that has to be
 * built somewhere, the Tools tab is where the other bulk structural
 * operations already are: nine importers and, since yesterday, the export.
 *
 * It also makes the hardest question disappear. In the rail there is a
 * selected folder, so "where does this land" has to be asked, answered and
 * shown. Here there is no selection, so every path is read from the top,
 * which is what `getOrCreateByPath()` has always done.
 *
 * ## Two calls, because the preview is the product
 *
 * `/folders/bulk/plan` writes nothing and says what would happen;
 * `/folders/bulk` does it. Both parse the same text on the server, so the
 * preview is not a second implementation of the rules that could drift from
 * the one that writes — the mistake `import/queries.ts` names at the top of
 * itself and refuses to make.
 */

import { useState } from 'react';

import { apiFetch, t, tn, type ApiEnvelope } from '../../core/api';

interface BulkRow {
    text: string;
    segments: string[];
    /**
     * The first segment that does not exist yet, or null when the line is
     * already there in full. Everything from this index on is new, because
     * nothing can exist under a folder that does not.
     */
    new_from: number | null;
    error: string | null;
}

interface BulkPlan {
    destination: { id: number | null; name: string; depth: number };
    limit: number;
    counts: { lines: number; folders: number; unchanged: number; errors: number };
    rows: BulkRow[];
}

/**
 * The message a failed call actually carries.
 *
 * `wp.apiFetch` rejects with the parsed body rather than an Error, and this
 * plugin's REST layer answers `{ success: false, error: { code, message } }`
 * — so neither `instanceof Error` nor `.message` finds anything, and the
 * wizard next door shows its fallback where a real sentence was sent. The
 * sentences here are the whole point ("up to 500 lines at a time", "fix the
 * ones marked below"), so this reads both shapes.
 */
function messageOf(error: unknown, fallback: string): string {
    if (typeof error === 'object' && error !== null) {
        const body = error as { error?: { message?: string }; message?: string };

        if (typeof body.error?.message === 'string' && body.error.message !== '') {
            return body.error.message;
        }

        if (typeof body.message === 'string' && body.message !== '') {
            return body.message;
        }
    }

    return fallback;
}

export function BulkCreate() {
    const [text, setText] = useState('');
    const [plan, setPlan] = useState<BulkPlan | null>(null);
    const [done, setDone] = useState<BulkPlan | null>(null);
    const [busy, setBusy] = useState<'plan' | 'create' | null>(null);
    const [error, setError] = useState<string | null>(null);

    /**
     * Any edit throws the preview away.
     *
     * A preview of text that has since been changed is worse than no preview:
     * it is a specific, confident, wrong answer, and it is sitting directly
     * under the button that acts on it.
     */
    function edit(value: string) {
        setText(value);
        setPlan(null);
        setDone(null);
        setError(null);
    }

    async function call(path: string, setter: (plan: BulkPlan) => void, fallback: string) {
        setBusy(path.endsWith('/plan') ? 'plan' : 'create');
        setError(null);

        try {
            const response = await apiFetch<ApiEnvelope<BulkPlan>>(path, {
                method: 'POST',
                data: { text },
            });

            setter(response.data);
        } catch (e) {
            setError(messageOf(e, fallback));
        } finally {
            setBusy(null);
        }
    }

    const preview = () =>
        call(
            '/folders/bulk/plan',
            (result) => {
                setPlan(result);
                setDone(null);
            },
            t('bulkPreviewFailed', 'The list could not be read.')
        );

    const create = () =>
        call(
            '/folders/bulk',
            (result) => {
                setDone(result);
                setPlan(null);
            },
            t('bulkCreateFailed', 'The folders could not be created.')
        );

    const shown = done ?? plan;

    return (
        <section className="folderfolio-bulk">
            <div className="folderfolio-wizard__head">
                <p className="folderfolio-wizard__eyebrow">{t('bulkEyebrow', 'Many at once')}</p>
                <p className="folderfolio-wizard__lede">
                    {t(
                        'bulkLede',
                        'Paste a list and get the folders, in the media library. One per line, and a slash nests — Brand/Logos makes both. Nothing is created until you have seen what would be.'
                    )}
                </p>
            </div>

            <label className="folderfolio-bulk__label" htmlFor="folderfolio-bulk-text">
                {t('bulkLabel', 'Folders, one per line')}
            </label>

            <textarea
                id="folderfolio-bulk-text"
                className="folderfolio-bulk__input"
                rows={8}
                spellCheck={false}
                value={text}
                disabled={busy !== null}
                placeholder={'Brand\nBrand/Logos\nBrand/Logos/Primary\n2026/Q1'}
                onChange={(event) => edit(event.target.value)}
            />

            <div className="folderfolio-bulk__actions">
                <button
                    type="button"
                    className="button button-secondary"
                    disabled={busy !== null || text.trim() === ''}
                    onClick={() => void preview()}
                >
                    {busy === 'plan' ? t('bulkPreviewing', 'Reading…') : t('bulkPreview', 'Preview')}
                </button>

                {/* The primary appears only once there is something to be
                    primary about. A Create button beside an empty box is an
                    invitation to press it and find out, which is the habit
                    this screen exists to break. */}
                {plan !== null && plan.counts.folders > 0 && plan.counts.errors === 0 && (
                    <button
                        type="button"
                        className="button button-primary"
                        disabled={busy !== null}
                        onClick={() => void create()}
                    >
                        {busy === 'create'
                            ? t('bulkCreating', 'Creating…')
                            : tn(
                                  'bulkCreateOne',
                                  'bulkCreateMany',
                                  plan.counts.folders,
                                  'Create %s folder',
                                  'Create %s folders',
                                  plan.counts.folders
                              )}
                    </button>
                )}
            </div>

            {error !== null && (
                <p className="folderfolio-wizard__error" role="alert">
                    {error}
                </p>
            )}

            {shown !== null && (
                <div className="folderfolio-bulk__result">
                    <p
                        className="folderfolio-bulk__summary"
                        /* Polite, not assertive: the person pressed a button and is
                           looking at the answer. */
                        role="status"
                    >
                        {done !== null
                            ? summaryOfRun(done)
                            : summaryOfPlan(plan as BulkPlan)}
                    </p>

                    <ul className="folderfolio-bulk__rows">
                        {shown.rows.map((row, index) => (
                            <li
                                // The index is the identity here: two identical
                                // lines are two rows and neither is a mistake.
                                key={index}
                                className={
                                    'folderfolio-bulk__row' +
                                    (row.error !== null
                                        ? ' folderfolio-bulk__row--error'
                                        : row.new_from === null
                                          ? ' folderfolio-bulk__row--unchanged'
                                          : '')
                                }
                            >
                                <span className="folderfolio-bulk__path">
                                    {row.error !== null
                                        ? row.text
                                        : row.segments.map((segment, position) => (
                                              <span key={position}>
                                                  {position > 0 && (
                                                      <span
                                                          className="folderfolio-bulk__sep"
                                                          aria-hidden="true"
                                                      >
                                                          /
                                                      </span>
                                                  )}
                                                  <span
                                                      className={
                                                          row.new_from !== null &&
                                                          position >= row.new_from
                                                              ? 'folderfolio-bulk__new'
                                                              : 'folderfolio-bulk__old'
                                                      }
                                                  >
                                                      {segment}
                                                  </span>
                                              </span>
                                          ))}
                                </span>

                                <span className="folderfolio-bulk__note">
                                    {row.error ??
                                        (row.new_from === null
                                            ? t('bulkRowExists', 'already there')
                                            : done !== null
                                              ? t('bulkRowCreated', 'created')
                                              : t('bulkRowNew', 'new'))}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}

/**
 * What the preview says above the list.
 *
 * Folders and lines are counted separately on purpose: eleven lines that
 * produce two folders is what a second paste of the same list looks like, and
 * a single number could not tell that apart from a mistake.
 */
function summaryOfPlan(plan: BulkPlan): string {
    const { counts } = plan;

    // Each count that appears in a sentence picks that sentence's form. "1 of
    // these lines cannot be used — they are marked below" is what a %s in a
    // fixed string produces, and no translator can fix it from their end.
    if (counts.errors > 0) {
        return tn(
            'bulkPlanErrorsOne',
            'bulkPlanErrorsMany',
            counts.errors,
            'Nothing has been created. %s line cannot be used — it is marked below.',
            'Nothing has been created. %s of these lines cannot be used — they are marked below.',
            counts.errors
        );
    }

    if (counts.folders === 0) {
        return t('bulkPlanNothing', 'Every folder on this list is already there. Nothing to create.');
    }

    const folders = tn(
        'bulkFolderOne',
        'bulkFolderMany',
        counts.folders,
        '%s folder',
        '%s folders',
        counts.folders
    );

    return counts.unchanged > 0
        ? tn(
              'bulkPlanSomeOne',
              'bulkPlanSomeMany',
              counts.unchanged,
              'Nothing has been created yet. This would add %1$s; %2$s line is already there.',
              'Nothing has been created yet. This would add %1$s; %2$s lines are already there.',
              folders,
              counts.unchanged
          )
        : t('bulkPlanAll', 'Nothing has been created yet. This would add %s.', folders);
}

function summaryOfRun(plan: BulkPlan): string {
    const { counts } = plan;

    if (counts.folders === 0) {
        return t('bulkRunNothing', 'Every folder on this list was already there. Nothing was created.');
    }

    return tn(
        'bulkRunOne',
        'bulkRunMany',
        counts.folders,
        '%s folder created.',
        '%s folders created.',
        counts.folders
    );
}
