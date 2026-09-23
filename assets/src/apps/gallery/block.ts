export const NAME = 'folderfolio/gallery';

export interface GalleryAttributes {
    folderIds: number[];
    includeDescendants: boolean;
    layout: 'grid' | 'masonry';
    columns: number;
    gap: number;
    orderBy: 'date' | 'title' | 'menu_order' | 'rand' | 'folder';
    order: 'asc' | 'desc';
    limit: number;
    linkTo: 'none' | 'media' | 'attachment';
    lightbox: boolean;
}
