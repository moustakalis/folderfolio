/**
 * The Lucide subset the rail uses, inline.
 *
 * Inline rather than a sprite or an icon font, because every one of these is
 * drawn in `currentColor` and takes its colour from the admin scheme through
 * the token on its parent. A sprite would work; a font would not, and a file
 * with baked fills would be the one wrong-coloured thing on seven of core's
 * eight schemes.
 *
 * 2px stroke, square caps, 24-unit viewBox — the design's own values. Size is
 * passed per use: 16px in rows, 14px in controls, 20px in toolbars.
 */

interface IconProps {
    size?: number;
    className?: string;
    /**
     * Overridden only by the chevrons, which the design draws at 2.25 so they
     * hold their weight at 14px next to a 16px folder at 2.
     */
    strokeWidth?: number;
}

function Svg({
    size = 16,
    className,
    strokeWidth = 2,
    children,
}: IconProps & { children: React.ReactNode }) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={strokeWidth}
            strokeLinecap="square"
            aria-hidden="true"
            focusable="false"
            className={className}
        >
            {children}
        </svg>
    );
}

/**
 * The product's mark, beside the eyebrow in the rail header.
 *
 * The one icon here that is **not** Lucide and not stroked: it is the plugin's
 * own mark, kept byte-for-byte identical to `assets/brand/mark.svg` — two
 * nested folder silhouettes on a 20-unit grid — so the wordmark in wp-admin,
 * the block icon and the wordpress.org listing are drawing the same shape.
 * Filled rather than stroked for the same reason; it is a logo, not an icon in
 * the set.
 *
 * Inlined rather than referenced as a file so it needs no request, no
 * `plugins_url()`, and takes its colour from the header like everything else
 * in this file.
 */
export function BrandMark({ size = 14, className }: { size?: number; className?: string }) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 20 20"
            fill="currentColor"
            aria-hidden="true"
            focusable="false"
            className={className}
        >
            <path d="M2 2H6L8 4H2Z" />
            <rect x="2" y="4" width="12" height="2" />
            <rect x="2" y="4" width="2" height="11" />
            <rect x="4" y="8" width="7" height="2" />
            <rect x="6" y="16" width="12" height="2" />
            <rect x="16" y="7" width="2" height="11" />
            <rect x="9" y="12" width="7" height="2" />
        </svg>
    );
}

export function FolderIcon(props: IconProps) {
    return (
        <Svg {...props}>
            <path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z" />
        </Svg>
    );
}

export function FolderOpenIcon(props: IconProps) {
    return (
        <Svg {...props}>
            <path d="m6 14 1.45-2.9A2 2 0 0 1 9.24 10H20a2 2 0 0 1 1.94 2.5l-1.55 6a2 2 0 0 1-1.94 1.5H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3.93a2 2 0 0 1 1.66.9l.82 1.2a2 2 0 0 0 1.66.9H18a2 2 0 0 1 2 2v2" />
        </Svg>
    );
}

export function ChevronRightIcon(props: IconProps) {
    return (
        <Svg strokeWidth={2.25} {...props}>
            <path d="m9 18 6-6-6-6" />
        </Svg>
    );
}

export function ChevronDownIcon(props: IconProps) {
    return (
        <Svg strokeWidth={2.25} {...props}>
            <path d="m6 9 6 6 6-6" />
        </Svg>
    );
}

export function SearchIcon(props: IconProps) {
    return (
        <Svg {...props}>
            <circle cx="11" cy="11" r="8" />
            <path d="m21 21-4.3-4.3" />
        </Svg>
    );
}

export function PlusIcon(props: IconProps) {
    return (
        <Svg strokeWidth={2.5} {...props}>
            <path d="M5 12h14M12 5v14" />
        </Svg>
    );
}

export function PencilIcon(props: IconProps) {
    return (
        <Svg {...props}>
            <path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z" />
        </Svg>
    );
}

export function TrashIcon(props: IconProps) {
    return (
        <Svg {...props}>
            <path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
        </Svg>
    );
}

export function ArrowUpDownIcon(props: IconProps) {
    return (
        <Svg {...props}>
            <path d="m21 16-4 4-4-4M17 20V4M3 8l4-4 4 4M7 4v16" />
        </Svg>
    );
}

/**
 * Plain up and down, for Move up / Move down.
 *
 * ArrowUpDownIcon above is the Sort button's glyph — both directions at once,
 * because sorting is a choice of order. These are one direction each, because
 * moving is a step.
 */
export function ArrowUpIcon(props: IconProps) {
    return (
        <Svg {...props}>
            <path d="m5 12 7-7 7 7M12 19V5" />
        </Svg>
    );
}

export function ArrowDownIcon(props: IconProps) {
    return (
        <Svg {...props}>
            <path d="M12 5v14M19 12l-7 7-7-7" />
        </Svg>
    );
}

export function FilterIcon(props: IconProps) {
    return (
        <Svg {...props}>
            <path d="M3 4h18l-7 8v6l-4 2v-8Z" />
        </Svg>
    );
}

export function EllipsisIcon(props: IconProps) {
    return (
        <Svg {...props}>
            <circle cx="12" cy="12" r="1" />
            <circle cx="19" cy="12" r="1" />
            <circle cx="5" cy="12" r="1" />
        </Svg>
    );
}

/**
 * Three dots stacked, for the row's menu.
 *
 * Vertical, while the toolbar's More is horizontal. They open the same menu,
 * so the argument for one glyph is real — but they are never on screen at the
 * same time (More exists only below 782px, the row button only above), and a
 * column of dots sitting at the end of a row reads as "this row" in a way a
 * row of dots inside a row does not.
 */
export function EllipsisVerticalIcon(props: IconProps) {
    return (
        <Svg {...props}>
            <circle cx="12" cy="12" r="1" />
            <circle cx="12" cy="5" r="1" />
            <circle cx="12" cy="19" r="1" />
        </Svg>
    );
}

export function CloseIcon(props: IconProps) {
    return (
        <Svg {...props}>
            <path d="M18 6 6 18M6 6l12 12" />
        </Svg>
    );
}

export function UndoIcon(props: IconProps) {
    return (
        <Svg {...props}>
            <path d="M3 2v6h6M21 12A9 9 0 0 0 6 5.3L3 8" />
        </Svg>
    );
}
