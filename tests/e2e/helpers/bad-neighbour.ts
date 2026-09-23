import type { Page } from '@playwright/test';

/**
 * A synthetic hostile neighbour, for the coexistence guards.
 *
 * ## Why this is not a competitor
 *
 * The e2e harness installs no folder plugin but ours, which is the whole
 * reason the two silent data bugs of 21 Sep reached `main`: nothing in CI has
 * ever shared a media library with anybody. Shipping FileBird into the rig
 * would answer that and buy four new problems — third-party code in the
 * repository, a licence question, a fixture that drifts with their releases,
 * and a suite that fails when they ship a fix.
 *
 * So the neighbour is ours, and it reproduces the three behaviours that were
 * measured in their source rather than the plugins that have them:
 *
 * 1. **it takes `wp.Uploader.prototype.init` without chaining** — FileBird,
 *    CatFolders and Premio all do this, all three by `jQuery.extend`, which is
 *    a plain assignment;
 * 2. **it refetches the content column** — `jQuery('#wpbody').load(url + '
 *    #wpbody-content')`, which is Premio's list-mode folder click;
 * 3. **it claims the content column's inline-start padding** — Premio's
 *    `#wpcontent { padding-left: 305px }`.
 *
 * ## Why it is injected rather than a mu-plugin
 *
 * The plan called for a mu-plugin. WordPress Playground keeps its SQLite
 * integration at `wp-content/mu-plugins/sqlite-database-integration`, so
 * mounting a directory onto `mu-plugins` takes the site's database away with
 * it; the ways round that are a Blueprint file-write or redefining
 * `WPMU_PLUGIN_DIR`, and both put the boot at risk to gain nothing here.
 *
 * Nothing is lost by injecting. The part of the hazard that has to be real is
 * the *response*: the bug in (2) is that WordPress serves our own
 * server-rendered rail inside the fetched `#wpbody-content`, because Rail.php
 * prints on `all_admin_notices`. That is real either way — the `.load()` below
 * fetches a real `upload.php` from a real WordPress.
 *
 * What injection adds is control of the lifecycle stage, and the stage is the
 * whole substance of (1). Each listener here is registered at document-start,
 * which is *earlier* than any enqueued script can manage, so at every stage
 * the neighbour writes first and we have to come back after it. That is the
 * hostile case, not the average one.
 */

/**
 * When the neighbour takes the slot.
 *
 * - `interactive` — a `readystatechange` at `interactive`, which is the moment
 *   before deferred scripts run: `wp.domReady()` calls its callback
 *   synchronously at this readyState, so this is FileBird's stage.
 * - `dcl` — a `DOMContentLoaded` listener. CatFolders' stage.
 * - `load` — `window.load`, later than anything either of them does.
 * - `late` — 2.5 seconds after `load`, past the last of upload-target.ts's
 *   retries, so that only the first-interaction re-assert can answer it.
 */
export type Stage = 'interactive' | 'dcl' | 'load' | 'late';

/** What the neighbour records about itself, readable from the page. */
export interface NeighbourState {
    assigned: Stage[];
}

/**
 * Take `wp.Uploader.prototype.init`, once per stage, without calling through.
 *
 * Must be called before `page.goto`.
 */
export async function takesTheUploaderSlot(page: Page, stages: Stage[]): Promise<void> {
    await page.addInitScript((wanted: Stage[]) => {
        const state: NeighbourState = { assigned: [] };

        (window as unknown as Record<string, unknown>).__badNeighbour = state;

        const take = (stage: Stage): void => {
            const w = window as unknown as {
                wp?: { Uploader?: { prototype?: Record<string, unknown> } };
                jQuery?: (...a: unknown[]) => unknown;
            };

            if (!w.wp?.Uploader?.prototype || !w.jQuery) {
                return;
            }

            // Their shape exactly: an object literal through jQuery.extend,
            // with no reference kept to whatever was there.
            const theirs = function (): void {
                // Deliberately empty. A neighbour that called through would
                // not be the bug.
            };

            (theirs as unknown as Record<string, unknown>).__badNeighbour = stage;

            (w.jQuery as unknown as { extend: (t: unknown, s: unknown) => void }).extend(
                w.wp.Uploader.prototype,
                { init: theirs }
            );

            state.assigned.push(stage);
        };

        if (wanted.includes('interactive')) {
            document.addEventListener('readystatechange', () => {
                if (document.readyState === 'interactive') {
                    take('interactive');
                }
            });
        }

        if (wanted.includes('dcl')) {
            document.addEventListener('DOMContentLoaded', () => take('dcl'));
        }

        if (wanted.includes('load')) {
            window.addEventListener('load', () => take('load'));
        }

        if (wanted.includes('late')) {
            window.addEventListener('load', () => {
                window.setTimeout(() => take('late'), 2_500);
            });
        }
    }, stages);
}

/**
 * Premio's list-mode folder click, as a function the test can call.
 *
 * Resolves when jQuery has finished replacing `#wpbody`'s children, which is
 * before our MutationObserver's microtask has run — so a test still has to
 * wait for the repair rather than read the DOM straight after.
 *
 * Must be called before `page.goto`.
 */
export async function refetchesTheContentColumn(page: Page): Promise<void> {
    await page.addInitScript(() => {
        (window as unknown as Record<string, unknown>).__badNeighbourRefetch = () =>
            new Promise<void>((done) => {
                const jq = (window as unknown as { jQuery: (s: string) => { load: (u: string, cb: () => void) => void } }).jQuery;

                jq('#wpbody').load(`${window.location.href} #wpbody-content`, () => done());
            });
    });
}

/**
 * `#wpcontent { padding-left: 305px }`, at the same specificity Premio writes
 * it with (1,1,1) — used by the phase 2.1 guard, where the point is that we
 * must stop *winning* this one.
 *
 * Must be called before `page.goto`.
 */
export async function claimsTheContentPadding(page: Page): Promise<void> {
    await page.addInitScript(() => {
        const style = document.createElement('style');

        style.id = 'bad-neighbour-padding';
        style.textContent = 'body.wp-admin #wpcontent { padding-left: 305px; }';

        /*
         * At document-start, not on `DOMContentLoaded`: a real plugin enqueues
         * this in the head, so it is in force before Rail.php's inline script
         * reads the padding to decide how far to pull the rail back. Injecting
         * it later would test a page the user never sees — and would pass for
         * the wrong reason, because our own `window.load` re-read would clean
         * it up afterwards.
         *
         * Nor does `documentElement`, in Chromium: an init script runs on the
         * new, still empty document, so the first version's
         * `(document.head ?? document.documentElement).append()` threw on
         * null and the claim was never made — found on the suite's first run,
         * 23 Sep, as a 20px padding where 305 was expected. So the style goes
         * in the moment `<html>` exists, before any script in the page runs.
         */
        const place = (): boolean => {
            const root = document.head ?? document.documentElement;

            if (!root) {
                return false;
            }

            root.append(style);

            return true;
        };

        if (!place()) {
            const watch = new MutationObserver(() => {
                if (place()) {
                    watch.disconnect();
                }
            });

            watch.observe(document, { childList: true, subtree: true });
        }
    });
}
