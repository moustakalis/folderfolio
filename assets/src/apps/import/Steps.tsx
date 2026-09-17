/**
 * The four-step rail across the top of the wizard.
 *
 * Not clickable. Screen 07 draws the steps as buttons, and they are labels
 * here: step 3 is an import in flight and step 4 is a report on one, so three
 * of the four "go back" gestures the design implies are either impossible or
 * destructive. A control that does nothing when pressed is worse than a label
 * that never claimed to be a control.
 */

import { t } from '../../core/api';

const STEPS: Array<{ n: number; key: string; fallback: string }> = [
    { n: 1, key: 'importStepDetect', fallback: 'Choose a source' },
    { n: 2, key: 'importStepPreview', fallback: 'Preview' },
    { n: 3, key: 'importStepRun', fallback: 'Import' },
    { n: 4, key: 'importStepReport', fallback: 'Finished' },
];

export function Steps({ current }: { current: number }) {
    return (
        <ol className="folderfolio-wizard__steps">
            {STEPS.map((step) => (
                <li
                    key={step.n}
                    className="folderfolio-wizard__step"
                    aria-current={step.n === current ? 'step' : undefined}
                >
                    <span className="folderfolio-wizard__num" aria-hidden="true">
                        {step.n}
                    </span>
                    {t(step.key, step.fallback)}
                </li>
            ))}
        </ol>
    );
}
