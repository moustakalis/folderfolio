/**
 * The `react-dom` and `react-dom/client` modules, from wp.element.
 *
 * @wordpress/element flattens both into one namespace, so one file serves both
 * aliases. See wp-element.ts for why this indirection exists at all.
 */

const element = window.wp?.element as
    | (typeof import('react-dom') & typeof import('react-dom/client'))
    | undefined;

if (!element) {
    throw new Error(
        'FolderFolio: wp.element is not on the page. Every script handle that '
            + 'loads a FolderFolio bundle must declare "wp-element" as a dependency.'
    );
}

export default element;

export const {
    createPortal,
    createRoot,
    findDOMNode,
    flushSync,
    hydrateRoot,
    render,
    unmountComponentAtNode,
} = element;
