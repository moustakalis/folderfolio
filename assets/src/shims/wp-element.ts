/**
 * The `react` module, served from WordPress's own bundle.
 *
 * WordPress ships React as the `wp-element` script handle, so the plugin does
 * not ship a second copy. A React app in wp-admin that bundles its own React
 * pays ~45KB for a library already on the page, and — worse — puts a second
 * reconciler on it. Two reconcilers do not share context, so the moment
 * anything of ours renders inside a core tree (the media modal, a block
 * inspector: both on the roadmap) hooks resolve against the wrong instance and
 * the failure is bizarre rather than obvious.
 *
 * Third-party packages import from `react`, not from `@wordpress/element`, so
 * the build aliases `react`, `react-dom`, `react-dom/client`,
 * `react/jsx-runtime` and `react/jsx-dev-runtime` onto these two files.
 * Nothing in our own source should import this file by path: import `react`
 * and let the alias do its work — that is what keeps a dependency like
 * TanStack Query, which has never heard of WordPress, resolving to the same
 * React as we do.
 *
 * The types come from `@types/react`, which stays a devDependency and ships
 * nothing. `import('react')` in type position is erased before esbuild sees
 * it, so this does not alias back onto itself.
 */

const element = window.wp?.element as typeof import('react') | undefined;

if (!element) {
    // Loud, at load, rather than at first render. A forgotten `wp-element`
    // in a wp_enqueue_script() dependency array otherwise surfaces as
    // "undefined is not a function" somewhere deep inside a component, three
    // files from the actual mistake.
    throw new Error(
        'FolderFolio: wp.element is not on the page. Every script handle that '
            + 'loads a FolderFolio bundle must declare "wp-element" as a dependency — '
            + 'tools/esbuild.mjs writes that into the .asset.php file automatically.'
    );
}

export default element;

/**
 * Named re-exports.
 *
 * Written out rather than generated, because the list is the contract: if a
 * future WordPress drops one of these, the build breaks here — at our
 * boundary, by name — instead of shipping `undefined` into a render.
 *
 * `Profiler` is deliberately absent. React exports it; @wordpress/element (as
 * of 6.46) does not re-export it. Leaving it off means an `import { Profiler }
 * from 'react'` is a build error rather than a silent `undefined`.
 */
export const {
    Children,
    Component,
    Fragment,
    PureComponent,
    StrictMode,
    Suspense,
    cloneElement,
    createContext,
    createElement,
    createRef,
    forwardRef,
    isValidElement,
    lazy,
    memo,
    startTransition,
    useCallback,
    useContext,
    useDebugValue,
    useDeferredValue,
    useEffect,
    useId,
    useImperativeHandle,
    useInsertionEffect,
    useLayoutEffect,
    useMemo,
    useReducer,
    useRef,
    useState,

    // The reason this file has to be exhaustive rather than a default export.
    // Both Zustand and TanStack Query subscribe through useSyncExternalStore,
    // and both import it from `react` by name. If it were missing the store
    // layer would not work at all, so it is checked in the pipeline test.
    useSyncExternalStore,
    useTransition,
} = element;
