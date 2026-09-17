/**
 * The FolderFolio mark, for the block.
 *
 * The same seven shapes as `Admin\Brand::MARK` on a 20-unit grid — two F's,
 * the second rotated 180° about the pair's centre, the folder being the volume
 * they enclose. Duplicated as JSX rather than fetched, because a placeholder
 * that waits on a network request to draw its own icon is a placeholder that
 * flickers.
 *
 * `currentColor` throughout: this is ink in the editor, never the red field.
 * The red is for the directory listing and nowhere else.
 */
export function Mark({ size = 24 }: { size?: number }) {
    return (
        <svg
            xmlns="http://www.w3.org/2000/svg"
            width={size}
            height={size}
            viewBox="0 0 20 20"
            aria-hidden="true"
            focusable="false"
        >
            <path d="M2 2H6L8 4H2Z" fill="currentColor" />
            <rect x="2" y="4" width="12" height="2" fill="currentColor" />
            <rect x="2" y="4" width="2" height="11" fill="currentColor" />
            <rect x="4" y="8" width="7" height="2" fill="currentColor" />
            <rect x="6" y="16" width="12" height="2" fill="currentColor" />
            <rect x="16" y="7" width="2" height="11" fill="currentColor" />
            <rect x="9" y="12" width="7" height="2" fill="currentColor" />
        </svg>
    );
}
