<?php

declare(strict_types=1);

namespace FolderFolio\Modules\Import;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sources that keep folders as WordPress taxonomy terms.
 *
 * Six of the nine plugins CatFolders' own importer knows about, which makes
 * this one reader the best-value hundred lines in the module:
 *
 * | Plugin | Taxonomy |
 * |---|---|
 * | Folders (Premio) | `media_folder` |
 * | Wicked Folders | `wf_attachment_folders` |
 * | Enhanced Media Library | `media_category` |
 * | Media Library Assistant | `attachment_category` |
 * | WP Media Folder | `wpmf-category` |
 * | HappyFiles | `happyfiles_category` |
 *
 * Read from `term_taxonomy` and `term_relationships` directly rather than
 * through `get_terms()`, for the reason this whole module exists: the plugin
 * that registered the taxonomy is very often deactivated by the time somebody
 * wants their folders out of it, and an unregistered taxonomy is invisible to
 * every WordPress API while its rows sit untouched in the database.
 *
 * Hierarchy comes from `term_taxonomy.parent`, where `0` is the root — a
 * fourth convention to normalise, and the reason `SourceFolder` insists on
 * `null`.
 */
final class TaxonomySource extends Source
{
    public function __construct(
        string $key,
        string $label,
        string $pluginFile,
        private readonly string $taxonomy
    ) {
        parent::__construct($key, $label, $pluginFile);
    }

    public function hasData(): bool
    {
        return $this->folderCount() > 0;
    }

    public function folderCount(): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the source plugin may be inactive, its taxonomy unregistered, so no term API can read it; read once to plan an import.
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE taxonomy = %s',
                $wpdb->term_taxonomy,
                $this->taxonomy
            )
        );
    }

    public function assignmentCount(): int
    {
        global $wpdb;

        // Joined to posts because a taxonomy can be shared with other post
        // types — Media Library Assistant's `attachment_category` is
        // attachments only, but Enhanced Media Library's is not necessarily,
        // and importing a post's categories into a media library is the kind
        // of mess that is easier to avoid than to undo.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the source plugin may be inactive, its taxonomy unregistered, so no term API can read it; read once to plan an import.
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM %i r
                 INNER JOIN %i tt
                     ON tt.term_taxonomy_id = r.term_taxonomy_id
                 INNER JOIN %i p
                     ON p.ID = r.object_id AND p.post_type = 'attachment'
                 WHERE tt.taxonomy = %s",
                $wpdb->term_relationships,
                $wpdb->term_taxonomy,
                $wpdb->posts,
                $this->taxonomy
            )
        );
    }

    /**
     * @return list<SourceFolder>
     */
    public function folders(): array
    {
        global $wpdb;

        /** @var list<array{term_id: string, parent: string, name: string}> $rows */
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the source plugin may be inactive, its taxonomy unregistered, so no term API can read it; read once to plan an import.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT tt.term_id, tt.parent, t.name
                 FROM %i tt
                 INNER JOIN %i t ON t.term_id = tt.term_id
                 WHERE tt.taxonomy = %s
                 ORDER BY tt.term_id ASC',
                $wpdb->term_taxonomy,
                $wpdb->terms,
                $this->taxonomy
            ),
            ARRAY_A
        ) ?: [];

        $folders = [];

        foreach ($rows as $row) {
            $parent = (int) $row['parent'];

            $folders[] = new SourceFolder(
                (int) $row['term_id'],
                0 === $parent ? null : $parent,
                // Decoded (review M7): WordPress stores a term's name through
                // `_wp_specialchars()`, so "Sales & Marketing" sits in the table
                // as "Sales &amp; Marketing" — and was imported that way, and
                // failed to match a folder of yours with the real name.
                html_entity_decode((string) $row['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')
            );
        }

        return $folders;
    }

    /**
     * @return list<int>
     */
    public function attachmentIdsFor(int $sourceFolderId): array
    {
        global $wpdb;

        // `$sourceFolderId` is a term_id, not a term_taxonomy_id — the two are
        // usually equal on a site that has never had a term in two taxonomies,
        // which is exactly why joining rather than assuming matters here.
        /** @var list<string> $ids */
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the source plugin may be inactive, its taxonomy unregistered, so no term API can read it; read once to plan an import.
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT r.object_id
                 FROM %i r
                 INNER JOIN %i tt
                     ON tt.term_taxonomy_id = r.term_taxonomy_id
                 INNER JOIN %i p
                     ON p.ID = r.object_id AND p.post_type = 'attachment'
                 WHERE tt.taxonomy = %s AND tt.term_id = %d",
                $wpdb->term_relationships,
                $wpdb->term_taxonomy,
                $wpdb->posts,
                $this->taxonomy,
                $sourceFolderId
            )
        ) ?: [];

        return array_values(array_map('intval', $ids));
    }
}
