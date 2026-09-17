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

export function ImageIcon(props: IconProps) {
    return (
        <Svg {...props}>
            <rect width="18" height="18" x="3" y="3" rx="0" />
            <circle cx="9" cy="9" r="2" />
            <path d="m21 15-3.09-3.09a2 2 0 0 0-2.82 0L6 21" />
        </Svg>
    );
}

export function InboxIcon(props: IconProps) {
    return (
        <Svg {...props}>
            <path d="M22 12h-6l-2 3h-4l-2-3H2" />
            <path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z" />
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

export function EllipsisIcon(props: IconProps) {
    return (
        <Svg {...props}>
            <circle cx="12" cy="12" r="1" />
            <circle cx="19" cy="12" r="1" />
            <circle cx="5" cy="12" r="1" />
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
