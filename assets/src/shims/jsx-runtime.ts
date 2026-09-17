/**
 * The automatic JSX runtime, over wp.element.createElement.
 *
 * With `jsx: 'automatic'` esbuild does not emit createElement calls; it emits
 * `import { jsx, jsxs } from "react/jsx-runtime"`. WordPress exposes no such
 * module, so this is it.
 *
 * `children` arrives inside props, and createElement reads props.children when
 * no child arguments follow it — so the two runtime forms need no different
 * handling and jsxs is jsx. Key is the one thing the runtime moves: it is a
 * separate argument to jsx() and a prop to createElement().
 */

import { createElement } from './wp-element';

type Props = Record<string, unknown> | null;

function jsx(type: unknown, props: Props, key?: unknown): unknown {
    return (createElement as unknown as (t: unknown, p: Props) => unknown)(
        type,
        key === undefined ? props : { ...(props ?? {}), key }
    );
}

export { jsx, jsx as jsxs, jsx as jsxDEV };
export { Fragment } from './wp-element';
