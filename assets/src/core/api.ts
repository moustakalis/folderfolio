/**
 * API wrapper using wp.apiFetch.
 */

export async function apiFetch<T>(path: string, options?: RequestInit): Promise<T> {
    const response = await window.wp.apiFetch({
        path: `/folderfolio/v1${path}`,
        ...options,
    });
    return response as T;
}

export interface Folder {
    id: number;
    parent_id: number | null;
    name: string;
    slug: string | null;
    color: string | null;
    icon: string | null;
    sort_order: number;
    created_at: string;
    updated_at: string;
}

export interface AttachmentFolderAssignment {
    folder_id: number;
    attachment_id: number;
    sort_order: number;
    assigned_at: string;
}
