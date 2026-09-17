<?php

declare(strict_types=1);

namespace FolderFolio\Modules\Import;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One plugin FolderFolio can import from.
 *
 * ## Two questions, kept apart
 *
 * `hasData()` asks whether the rows are still there. `isPluginActive()` asks
 * whether the plugin is running. They are different questions and the wizard
 * needs both: a site that deactivated FileBird a year ago still has its
 * folders in the database and is exactly who this feature is for, while
 * "should we offer to migrate you right now" is about what is installed.
 *
 * CatFolders' importer conflates them one way and v0.2.0's conflated them the
 * other — `SHOW TABLES LIKE` reports a table that will outlive the plugin
 * forever, so the Import page claimed FileBird was importable on a site that
 * had never had it.
 *
 * ## Reading is folder-at-a-time
 *
 * `folders()` returns the whole tree, which is hundreds of rows at worst.
 * Assignments are not returned in bulk: `attachmentIdsFor()` is asked per
 * folder, so the runner can work in batches and a library with a hundred
 * thousand files never has to fit in memory. The counts are COUNT queries.
 */
abstract class Source
{
    /**
     * @param string $key        Stable identifier — appears in REST paths, in
     *                           folder provenance, and in the run record, so it
     *                           must never change once shipped.
     * @param string $label      The plugin's own name, as its authors spell it.
     * @param string $pluginFile Its `plugin_basename()`, for the active check.
     */
    public function __construct(
        protected readonly string $key,
        protected readonly string $label,
        protected readonly string $pluginFile
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * Is the source plugin running right now?
     *
     * Read from the options rather than through `is_plugin_active()`, which
     * lives in wp-admin/includes/plugin.php and would have to be required on
     * a REST request that has no other reason to load it.
     */
    public function isPluginActive(): bool
    {
        $active = get_option('active_plugins', []);

        if (is_array($active) && in_array($this->pluginFile, $active, true)) {
            return true;
        }

        if (!is_multisite()) {
            return false;
        }

        $network = get_site_option('active_sitewide_plugins', []);

        return is_array($network) && isset($network[$this->pluginFile]);
    }

    /**
     * Are there rows to import, whether or not the plugin is still here?
     */
    abstract public function hasData(): bool;

    abstract public function folderCount(): int;

    abstract public function assignmentCount(): int;

    /**
     * The source's folders, in no particular order.
     *
     * Ordering is the reader's business only so far as `sortOrder` carries
     * what the source recorded; the planner sorts into parent-before-child
     * order itself, because it cannot trust a source to have done so.
     *
     * @return list<SourceFolder>
     */
    abstract public function folders(): array;

    /**
     * Attachment ids filed in one source folder.
     *
     * Ids only, and not filtered against the media library — whether an
     * attachment still exists is the planner's question, asked once for the
     * whole import rather than once per folder.
     *
     * @return list<int>
     */
    abstract public function attachmentIdsFor(int $sourceFolderId): array;
}
