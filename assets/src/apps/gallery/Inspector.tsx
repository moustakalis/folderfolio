/**
 * The block's settings — screen 09's inspector.
 *
 * Order is the handoff's: the tree first, because the folder is the block's
 * subject and everything below it is a treatment of that folder. Then Include
 * subfolders, Layout, Columns, Order and Link to.
 *
 * The controls are WordPress's own, read off `wp.components`. A plugin that
 * draws its own sliders in a block inspector is a plugin that looks wrong in
 * the next editor release; these follow whatever core does.
 */

import type { GalleryAttributes } from './block';
import { FolderTree } from './FolderTree';
import { t } from '../../core/api';

const components = window.wp?.components;
const blockEditor = window.wp?.blockEditor;

export interface InspectorProps {
    attributes: GalleryAttributes;
    setAttributes: (next: Partial<GalleryAttributes>) => void;
}

export function Inspector({ attributes, setAttributes }: InspectorProps) {
    if (!components || !blockEditor) {
        return null;
    }

    const { InspectorControls } = blockEditor;
    const { PanelBody, ToggleControl, SelectControl, RangeControl } = components;

    return (
        <InspectorControls>
            <PanelBody title={t('folder', 'Folder')} initialOpen>
                <FolderTree />

                <ToggleControl
                    __nextHasNoMarginBottom
                    label={t('includeSubfolders', 'Include subfolders')}
                    checked={attributes.includeDescendants}
                    onChange={(includeDescendants: boolean) =>
                        setAttributes({ includeDescendants })
                    }
                />
            </PanelBody>

            <PanelBody title={t('gallerySettings', 'Gallery')} initialOpen>
                <SelectControl
                    __nextHasNoMarginBottom
                    label={t('layout', 'Layout')}
                    value={attributes.layout}
                    options={[
                        { label: t('layoutGrid', 'Grid'), value: 'grid' },
                        { label: t('layoutMasonry', 'Masonry'), value: 'masonry' },
                    ]}
                    onChange={(layout: string) =>
                        setAttributes({ layout: 'masonry' === layout ? 'masonry' : 'grid' })
                    }
                />

                <RangeControl
                    __nextHasNoMarginBottom
                    label={t('columns', 'Columns')}
                    value={attributes.columns}
                    min={1}
                    max={8}
                    onChange={(columns?: number) => setAttributes({ columns: columns ?? 3 })}
                />

                <RangeControl
                    __nextHasNoMarginBottom
                    label={t('gap', 'Gap')}
                    value={attributes.gap}
                    min={0}
                    max={48}
                    step={4}
                    onChange={(gap?: number) => setAttributes({ gap: gap ?? 16 })}
                />

                <SelectControl
                    __nextHasNoMarginBottom
                    label={t('orderBy', 'Order by')}
                    value={`${attributes.orderBy}:${attributes.order}`}
                    options={[
                        // First: the order somebody arranged by hand in the
                        // library is the one a gallery of that folder most
                        // often means (tier 2 item 8).
                        { label: t('orderFolder', 'Folder order'), value: 'folder:asc' },
                        { label: t('orderNewest', 'Newest first'), value: 'date:desc' },
                        { label: t('orderOldest', 'Oldest first'), value: 'date:asc' },
                        { label: t('orderTitle', 'Title, A to Z'), value: 'title:asc' },
                        {
                            label: t('orderMenu', 'Media library order'),
                            value: 'menu_order:asc',
                        },
                        { label: t('orderRandom', 'Random'), value: 'rand:desc' },
                    ]}
                    onChange={(value: string) => {
                        const [orderBy, order] = value.split(':');

                        setAttributes({
                            orderBy: orderBy as GalleryAttributes['orderBy'],
                            order: 'asc' === order ? 'asc' : 'desc',
                        });
                    }}
                />

                {/*
                  0 is "all of them", and the control says so rather than
                  showing a zero. A folder with four thousand files in it is
                  still capped server-side — see GalleryQuery::MAX — because a
                  page is not an export.
                */}
                <RangeControl
                    __nextHasNoMarginBottom
                    label={t('limit', 'Maximum images')}
                    value={attributes.limit}
                    min={0}
                    max={100}
                    onChange={(limit?: number) => setAttributes({ limit: limit ?? 0 })}
                    help={0 === attributes.limit ? t('limitAll', 'All of them') : undefined}
                />

                {/*
                  Core's lightbox, not one of ours — and only offered when the
                  images are not already links, because a click cannot do both
                  and core disables it in exactly that case.
                */}
                {'none' === attributes.linkTo ? (
                    <ToggleControl
                        __nextHasNoMarginBottom
                        label={t('lightbox', 'Expand on click')}
                        checked={attributes.lightbox}
                        help={t(
                            'lightboxHelp',
                            'Uses the lightbox WordPress already ships, so the page stays free of extra scripts until someone clicks.'
                        )}
                        onChange={(lightbox: boolean) => setAttributes({ lightbox })}
                    />
                ) : null}

                <SelectControl
                    __nextHasNoMarginBottom
                    label={t('linkTo', 'Link to')}
                    value={attributes.linkTo}
                    options={[
                        { label: t('linkNone', 'Nothing'), value: 'none' },
                        { label: t('linkMedia', 'The image file'), value: 'media' },
                        { label: t('linkAttachment', 'The attachment page'), value: 'attachment' },
                    ]}
                    onChange={(linkTo: string) =>
                        setAttributes({ linkTo: linkTo as GalleryAttributes['linkTo'] })
                    }
                />
            </PanelBody>
        </InspectorControls>
    );
}
