/**
 * The automatic JSX runtime, over wp.element.createElement.
 *
 * With `jsx: 'automatic'` esbuild does not emit createElement calls; it emits
 * `import { jsx, jsxs } from "react/jsx-runtime"`. WordPress exposes no such
 * module, so this is it.
 *
 * ## jsx and jsxs are not the same function
 *
 * They were, until 23 Sep, and that was the source of a "unique key" warning
 * on every rail load under SCRIPT_DEBUG. The compiler calls `jsxs` when an
 * element has **several static children** — `<p><b/>{x}</p>` — and hands them
 * over as one `props.children` array. That array is not a list anybody built
 * with `.map()`; it is just how the compiler packs siblings. React's own
 * `jsxs` knows that and marks them validated. `createElement` does not: it
 * only validates children passed as *arguments*, so an array arriving inside
 * props reached the reconciler looking exactly like a list with no keys.
 *
 * So `jsxs` spreads the static children back into arguments, which is what
 * `createElement` has always taken them as, and React validates each one as a
 * single child. A **dynamic** array among them — a `.map()` in the middle of
 * the markup — is still one argument that is an array, and still gets the
 * warning if its items have no keys, which is the warning worth having.
 *
 * `jsx` (one child, or none) leaves children in props: a single element is
 * not a list, and a lone `.map()` array there is a list and should be keyed.
 *
 * Key is the other thing the runtime moves: it is a separate argument to
 * jsx() and a prop to createElement().
 */

import { createElement } from './wp-element';

type Props = Record<string, unknown> | null;

const create = createElement as unknown as (t: unknown, p: Props, ...children: unknown[]) => unknown;

function withKey(props: Props, key: unknown): Props {
    return key === undefined ? props : { ...(props ?? {}), key };
}

function jsx(type: unknown, props: Props, key?: unknown): unknown {
    return create(type, withKey(props, key));
}

function jsxs(type: unknown, props: Props, key?: unknown): unknown {
    const { children, ...rest } = props ?? {};

    return Array.isArray(children)
        ? create(type, withKey(rest, key), ...children)
        : create(type, withKey(props, key));
}

export { jsx, jsxs, jsx as jsxDEV };
export { Fragment } from './wp-element';
