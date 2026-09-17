/**
 * The wizard — screen 07's four steps.
 *
 * The step is not state the user sets; it is read off what the server says is
 * true. No source chosen means step 1, a plan on screen means step 2, a run in
 * flight means step 3, a finished run means step 4. That is why closing the
 * tab mid-import and coming back lands on the progress bar rather than on the
 * first step with the import still going on underneath.
 */

import { useCallback, useEffect, useRef, useState } from 'react';

import { Detect } from './Detect';
import { Preview } from './Preview';
import { Progress } from './Progress';
import { Report } from './Report';
import { Steps } from './Steps';
import { usePlan, useRunAction, useSources, type RunState } from './queries';
import { t } from '../../core/api';

export function Wizard() {
    const sources = useSources();
    const [chosen, setChosen] = useState<string | null>(null);
    const [run, setRun] = useState<RunState | null>(null);
    const plan = usePlan(chosen);
    const action = useRunAction();

    // A run that was already going when the page loaded. Adopted once, not on
    // every render, so a finished run the user has dismissed does not come
    // back each time the source list refetches.
    const [adopted, setAdopted] = useState(false);
    const serverRun = sources.data?.run ?? null;

    useEffect(() => {
        if (adopted || !sources.data) {
            return;
        }

        setAdopted(true);

        if (serverRun && serverRun.status !== 'undone') {
            setRun(serverRun);
        }
    }, [adopted, sources.data, serverRun]);

    const active = run !== null && (run.status === 'running' || run.status === 'stopping');

    /**
     * Drive the run, one batch at a time, until the server says it is done.
     *
     * A loop that awaits each batch rather than an effect that re-fires on the
     * previous one's result. The effect version worked on paper and did not
     * work at all: its own `isPending` flip re-ran the effect, whose cleanup
     * marked the in-flight batch cancelled, so the response that would have
     * advanced the cursor was thrown away and the bar sat at 0% forever.
     *
     * One request in flight at a time either way. An interval that fires
     * faster than a batch completes stacks up requests that each re-read the
     * same cursor, which on the hosting this matters for is how an import ends
     * up running three times over.
     */
    const driving = useRef(false);

    useEffect(() => {
        if (!active || driving.current) {
            return;
        }

        driving.current = true;
        let cancelled = false;

        void (async () => {
            try {
                let current: RunState | null = run;

                while (
                    !cancelled &&
                    current !== null &&
                    (current.status === 'running' || current.status === 'stopping')
                ) {
                    // Awaited, not read from the closure: `run` here is the
                    // state this effect started with, and the cursor moves.
                    current = await action.mutateAsync('run');

                    if (!cancelled) {
                        setRun(current);
                    }
                }
            } catch {
                // Surfaced through the mutation's own error state, below.
            } finally {
                driving.current = false;
            }
        })();

        return () => {
            cancelled = true;
        };
        // Deliberately only `active`: the loop reads the server's answer each
        // time round, so it does not need re-running when the run changes.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [active]);

    const start = useCallback(() => {
        if (!chosen) {
            return;
        }

        action.mutate(`${chosen}/start`, { onSuccess: setRun });
    }, [action, chosen]);

    const stop = useCallback(() => {
        action.mutate('stop', { onSuccess: setRun });
    }, [action]);

    const undo = useCallback(() => {
        action.mutate('undo', { onSuccess: setRun });
    }, [action]);

    const restart = useCallback(() => {
        setRun(null);
        setChosen(null);
        void sources.refetch();
    }, [sources]);

    const step = run !== null ? (active ? 3 : 4) : chosen !== null ? 2 : 1;

    return (
        <div className="folderfolio-wizard">
            <Steps current={step} />

            <div className="folderfolio-wizard__body">
                {step === 1 && (
                    <Detect
                        sources={sources.data?.sources ?? []}
                        loading={sources.isPending}
                        onChoose={setChosen}
                    />
                )}

                {step === 2 && (
                    <Preview
                        plan={plan.data ?? null}
                        loading={plan.isPending}
                        error={plan.error instanceof Error ? plan.error.message : null}
                        starting={action.isPending}
                        onImport={start}
                        onCancel={restart}
                    />
                )}

                {step === 3 && run && <Progress run={run} onStop={stop} />}

                {step === 4 && run && (
                    <Report
                        run={run}
                        working={action.isPending}
                        onUndo={undo}
                        onDone={restart}
                    />
                )}
            </div>

            {action.error instanceof Error && (
                <div className="folderfolio-wizard__error" role="alert">
                    {action.error.message || t('importFailed', 'The import could not continue.')}
                </div>
            )}
        </div>
    );
}
