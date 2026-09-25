/**
 * The Folders panel in the block editor — tier 3 item 12, Nick's 12d.
 *
 * A post is filed from where it is written: a panel in the document sidebar
 * listing this type's folders as checkboxes, the ones the post is in ticked.
 * Many-to-many (12f), so ticking a second folder adds, it does not move.
 *
 * Its own bundle, its own config global (`window.folderFolioPost`) and no
 * shared store: the block editor also loads the media picker's bundle, whose
 * `window.folderFolio` is media's — its abilities, its tree. Two trees of two
 * types on one page is exactly the collision a separate global avoids.
 *
 * WordPress's packages are read off `wp`, the codebase's convention, and
 * declared as script dependencies in Admin\PostFolders.
 */

import { useCallback, useEffect, useMemo, useState } from 'react';
import { pickForm } from '../core/plural';

interface PostFoldersConfig {
    objectType: string;
    canAssign: boolean;
    manageUrl: string;
    pluralRule?: string;
    i18n: Record<string, string | string[]>;
}

interface Node {
    id: number;
    name: string;
    children: Node[];
    locked_by?: number | null;
}

interface Flat {
    id: number;
    name: string;
    depth: number;
}

type Envelope<T> = { success: boolean; data: T };

interface EditorGlobals {
    apiFetch<T>(options: { path: string; method?: string; data?: unknown }): Promise<T>;
    plugins?: { registerPlugin(name: string, settings: { render: () => unknown; icon?: unknown }): void };
    editor?: { PluginDocumentSettingPanel?: React.ComponentType<{ name: string; title: string; className?: string; children?: React.ReactNode }> };
    editPost?: { PluginDocumentSettingPanel?: React.ComponentType<{ name: string; title: string; className?: string; children?: React.ReactNode }> };
    data?: {
        select(store: string): { getCurrentPostId(): number | null };
        dispatch(store: string): { createErrorNotice(message: string, options?: Record<string, unknown>): void };
    };
    components?: {
        CheckboxControl: React.ComponentType<{
            __nextHasNoMarginBottom?: boolean;
            label: string;
            checked: boolean;
            disabled?: boolean;
            onChange: (checked: boolean) => void;
        }>;
        Spinner: React.ComponentType;
    };
}

const config = (window as unknown as { folderFolioPost?: PostFoldersConfig }).folderFolioPost;
const wp = (window as unknown as { wp?: EditorGlobals }).wp;

function t(key: string, fallback: string): string {
    const entry = config?.i18n?.[key];

    return typeof entry === 'string' ? entry : fallback;
}

/** A counted label: the server's forms and rule (Support\Plurals), or English's two. */
function tn(one: string, many: string, count: number, fallbackOne: string, fallbackMany: string): string {
    const forms = config?.i18n?.[one];
    const template = Array.isArray(forms) && forms.length > 0
        ? pickForm(forms, count, config?.pluralRule)
        : (count === 1 ? t(one, fallbackOne) : t(many, fallbackMany));

    return template.replace('%s', String(count));
}

function flatten(nodes: Node[], depth = 0, out: Flat[] = []): Flat[] {
    for (const node of nodes) {
        out.push({ id: node.id, name: node.name, depth });
        flatten(node.children ?? [], depth + 1, out);
    }

    return out;
}

function api<T>(path: string, options: { method?: string; data?: unknown } = {}): Promise<T> {
    if (!wp) {
        return Promise.reject(new Error('wp.apiFetch is missing'));
    }

    return wp.apiFetch<T>({ path: `/folderfolio/v1${path}`, ...options });
}

function errorText(error: unknown): string {
    const message = (error as { error?: { message?: string }; message?: string } | null)?.error?.message
        ?? (error as { message?: string } | null)?.message;

    return typeof message === 'string' && message !== '' ? message : t('saveFailed', 'The folder could not be changed.');
}

/** The post's id, which a new post only has once the editor has loaded it. */
function usePostId(): number | null {
    const [id, setId] = useState<number | null>(() => wp?.data?.select('core/editor').getCurrentPostId() ?? null);

    useEffect(() => {
        if (id) {
            return;
        }

        const timer = setInterval(() => {
            const next = wp?.data?.select('core/editor').getCurrentPostId() ?? null;

            if (next) {
                setId(next);
            }
        }, 250);

        return () => clearInterval(timer);
    }, [id]);

    return id;
}

function Panel() {
    const postId = usePostId();
    const [folders, setFolders] = useState<Flat[] | null>(null);
    const [filed, setFiled] = useState<Set<number>>(new Set());
    const [busy, setBusy] = useState<Set<number>>(new Set());
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (!postId || !config) {
            return;
        }

        let live = true;
        const type = encodeURIComponent(config.objectType);

        Promise.all([
            api<Envelope<Node[]>>(`/folders?object_type=${type}&counts=none`),
            api<Envelope<{ folders: Array<{ id: number }> }>>(`/attachments/${postId}/folders`),
        ])
            .then(([tree, mine]) => {
                if (!live) {
                    return;
                }

                setFolders(flatten(tree.data ?? []));
                setFiled(new Set((mine.data?.folders ?? []).map((folder) => folder.id)));
            })
            .catch(() => live && setFailed(true));

        return () => {
            live = false;
        };
    }, [postId]);

    const toggle = useCallback(
        (folderId: number, on: boolean) => {
            if (!postId) {
                return;
            }

            // Optimistic: the box moves under the pointer, and goes back with
            // a notice if the server refuses.
            setFiled((current) => {
                const next = new Set(current);
                on ? next.add(folderId) : next.delete(folderId);

                return next;
            });
            setBusy((current) => new Set(current).add(folderId));

            api('/assignments', {
                method: on ? 'POST' : 'DELETE',
                data: { folder_id: folderId, attachment_ids: [postId], ...(on ? { mode: 'add' } : {}) },
            })
                .catch((error: unknown) => {
                    setFiled((current) => {
                        const next = new Set(current);
                        on ? next.delete(folderId) : next.add(folderId);

                        return next;
                    });
                    wp?.data?.dispatch('core/notices').createErrorNotice(errorText(error), {
                        type: 'snackbar',
                        id: 'folderfolio-post-folders',
                    });
                })
                .finally(() =>
                    setBusy((current) => {
                        const next = new Set(current);
                        next.delete(folderId);

                        return next;
                    })
                );
        },
        [postId]
    );

    const Checkbox = wp?.components?.CheckboxControl;
    const Spinner = wp?.components?.Spinner;
    const count = filed.size;

    const summary = useMemo(() => {
        if (folders === null) {
            return '';
        }

        return count === 0
            ? t('inNoFolder', 'Not in any folder.')
            : tn('inOneFolder', 'inFolders', count, 'In %s folder.', 'In %s folders.');
    }, [count, folders]);

    if (!config || !Checkbox) {
        return null;
    }

    if (failed) {
        return <p className="folderfolio-post-folders__note">{t('loadFailed', 'The folders could not be loaded.')}</p>;
    }

    if (folders === null) {
        return Spinner ? <Spinner /> : null;
    }

    if (folders.length === 0) {
        return (
            <p className="folderfolio-post-folders__note">
                {t('noFolders', 'There are no folders here yet.')}{' '}
                <a href={config.manageUrl}>{t('manage', 'Make one on the list screen')}</a>
            </p>
        );
    }

    return (
        <div className="folderfolio-post-folders">
            <p className="folderfolio-post-folders__note" aria-live="polite">
                {summary}
            </p>
            <ul className="folderfolio-post-folders__list" aria-label={t('panel', 'Folders')}>
                {folders.map((folder) => (
                    <li key={folder.id} style={{ paddingInlineStart: `${folder.depth * 16}px` }}>
                        <Checkbox
                            __nextHasNoMarginBottom
                            label={folder.name}
                            checked={filed.has(folder.id)}
                            disabled={!config.canAssign || busy.has(folder.id)}
                            onChange={(checked) => toggle(folder.id, checked)}
                        />
                    </li>
                ))}
            </ul>
            <p className="folderfolio-post-folders__manage">
                <a href={config.manageUrl}>{t('manageAll', 'Manage folders')}</a>
            </p>
        </div>
    );
}

const SettingPanel = wp?.editor?.PluginDocumentSettingPanel ?? wp?.editPost?.PluginDocumentSettingPanel;

if (config && wp?.plugins && SettingPanel) {
    wp.plugins.registerPlugin('folderfolio-post-folders', {
        // The panel carries its own title; no toolbar icon.
        icon: null,
        render: () => (
            <SettingPanel name="folderfolio-post-folders" title={t('panel', 'Folders')} className="folderfolio-post-folders-panel">
                <Panel />
            </SettingPanel>
        ),
    });
}
