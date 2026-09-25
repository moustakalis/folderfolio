<?php

declare(strict_types=1);

namespace FolderFolio\Domain;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Database\Transaction;
use FolderFolio\Support\Capabilities;
use FolderFolio\Support\PostTypes;
use FolderFolio\Support\Settings;
use FolderFolio\Support\Swatches;
use WP_Error;

/**
 * Every folder invariant lives here.
 *
 * ## Hooks
 *
 * The actions and filters below are part of the public API and are covered by
 * the compatibility promise in docs/architecture-plan.md §8.4. They fire from
 * this class rather than from the REST controllers on purpose: a folder
 * created by WP-CLI, by an importer, or by another plugin calling the facade
 * must fire the same hooks as one created by a click. Firing them at the edge
 * would mean three of those four paths silently skipped them.
 *
 * Actions:
 *   folderfolio_folder_created           (Folder $folder)
 *   folderfolio_folder_renamed           (Folder $folder, string $previousName)
 *   folderfolio_folder_moved             (Folder $folder, ?int $previousParentId)
 *   folderfolio_folder_deleted           (int $id, string $children, list<int> $deletedIds)
 *   folderfolio_folder_duplicated        (Folder $copy, Folder $source, array<int, int> $idMap, bool $withFiles)
 *   folderfolio_attachments_assigned     (list<int> $ids, int $folderId, string $mode)
 *   folderfolio_attachments_unassigned   (list<int> $ids, ?int $folderId)
 *
 * Filters:
 *   folderfolio_max_depth                (int)
 *   folderfolio_count_mode               (string 'inherited'|'direct')
 *
 * @phpstan-type FolderInput array{
 *     name?: mixed,
 *     parent_id?: int|string|null,
 *     color?: string|null,
 *     icon?: string|null,
 *     sort_order?: int|string,
 *     slug?: string|null,
 *     object_type?: string
 * }
 *
 * @phpstan-type FolderData array{
 *     name?: string,
 *     parent_id?: int|null,
 *     color?: string|null,
 *     icon?: string|null,
 *     sort_order?: int,
 *     slug?: string|null,
 *     object_type?: string
 * }
 */
class FolderService
{
    public const MODE_ADD = 'add';
    public const MODE_MOVE = 'move';

    public const CHILDREN_REPARENT = 'reparent';
    public const CHILDREN_CASCADE = 'cascade';

    public function __construct(
        private readonly FolderRepository $folders = new FolderRepository(),
        private readonly AttachmentFolderRepository $assignments = new AttachmentFolderRepository(),
        private readonly FolderSorts $sorts = new FolderSorts(),
        private readonly FolderLocks $locks = new FolderLocks(),
        private readonly FolderKinds $kinds = new FolderKinds()
    ) {
    }

    /**
     * A folder's name for a lock's refusal — `FolderLocks::guard()` asks.
     *
     * @return callable(int): ?string
     */
    private function nameOf(): callable
    {
        return function (int $id): ?string {
            $row = $this->folders->find($id);

            return $row === null ? null : (string) $row['name'];
        };
    }

    /**
     * A folder given as a parent, a destination or a reassignment that holds
     * another kind of content — tier 3 item 12.
     *
     * One tree per object type (Nick's 12b): a post folder never sits inside a
     * media folder, and a post is never filed where images are. The rail never
     * offers either — each screen shows one type's tree — so this is the rule
     * for a request that did not come from the rail.
     *
     * @param array<string, mixed>|null $folder A row from the repository.
     */
    private function wrongType(?array $folder, string $objectType): ?WP_Error
    {
        if ($folder === null || (string) $folder['object_type'] === $objectType) {
            return null;
        }

        return new WP_Error(
            'folderfolio_wrong_type',
            __('That folder holds a different kind of content.', 'folderfolio'),
            ['status' => 400]
        );
    }

    /**
     * Mark a folder locked or pinned — tier 2 item 10.
     *
     * Locking needs the `lock` ability, which the route asks. Pinning moves a
     * folder within its level, so on a locked folder it is refused like any
     * other move unless this person may lock (`FolderLocks::guard()`).
     */
    public function mark(int $id, string $mark, bool $on): Folder|WP_Error
    {
        $folder = $this->get($id);

        if ($folder === null) {
            return new WP_Error(
                'folderfolio_folder_not_found',
                __('Folder not found.', 'folderfolio')
            );
        }

        if ($mark === FolderLocks::PINNED) {
            $locked = $this->locks->guard($folder->path, $this->nameOf());

            if ($locked !== null) {
                return $locked;
            }
        }

        $written = $this->locks->set($id, $mark, $on);

        if (is_wp_error($written)) {
            return $written;
        }

        do_action('folderfolio_folder_marked', $folder, $mark, $on);

        return $folder;
    }

    /**
     * Set or clear one of a folder's two orders — tier 1 item 2.
     *
     * `FolderSorts::set()` does the writing; this is where the folder is
     * found and the hook fires, so a sort set from the rail, the facade or
     * WP-CLI is announced the same way (24 Sep — the route wrote straight to
     * FolderSorts and nothing heard it). Not guarded by a lock: how a folder
     * shows what is inside it is not its shape (answer 6, board
     * 3ZU8VGkJemznTvKp8tNnvY), like its colour.
     *
     * @param string      $scope `folders` or `files`.
     * @param string|null $order One of FolderSorts' orders, or null to follow the global sort.
     */
    public function sort(int $id, string $scope, ?string $order): Folder|WP_Error
    {
        $folder = $this->get($id);

        if ($folder === null) {
            return new WP_Error(
                'folderfolio_folder_not_found',
                __('Folder not found.', 'folderfolio'),
                ['status' => 404]
            );
        }

        $written = $this->sorts->set($id, $scope, $order);

        if (is_wp_error($written)) {
            return $written;
        }

        do_action('folderfolio_folder_sort_changed', $folder, $scope, $order);

        return $folder;
    }

    /**
     * Make a folder a gallery, or a plain folder again — tier 3 item 14.
     *
     * Media only: a gallery is a folder of images, and a post tree has none.
     * Refused on a locked folder unless this person may lock — a lock keeps
     * what a folder *is*, and the kind is that, where colour and *Sort
     * inside* are only how it is shown (answer 6 on board
     * 3ZU8VGkJemznTvKp8tNnvY).
     *
     * Refused while the folder holds a file that is not an image, with the
     * count: a gallery that already broke its own rule would be a mark that
     * says nothing. The person moves them out first.
     *
     * A folder that becomes a gallery with no file order of its own opens in
     * Custom — the order somebody arranged by hand, which is the one a
     * gallery on a page most often means (tier 2 item 8). An order already
     * chosen for it is kept.
     */
    public function setKind(int $id, string $kind): Folder|WP_Error
    {
        $folder = $this->get($id);

        if ($folder === null) {
            return new WP_Error(
                'folderfolio_folder_not_found',
                __('Folder not found.', 'folderfolio')
            );
        }

        if (!in_array($kind, FolderKinds::KINDS, true)) {
            return new WP_Error(
                'folderfolio_kind_unknown',
                __('A folder is either a folder or a gallery.', 'folderfolio'),
                ['status' => 400]
            );
        }

        if ($kind === FolderKinds::GALLERY && $folder->objectType !== FolderRepository::DEFAULT_OBJECT_TYPE) {
            return new WP_Error(
                'folderfolio_kind_media_only',
                __('Only a media folder can be a gallery.', 'folderfolio'),
                ['status' => 400]
            );
        }

        if ($this->kinds->kindOf($id) === $kind) {
            return $folder;
        }

        $locked = $this->locks->guard($folder->path, $this->nameOf());

        if ($locked !== null) {
            return $locked;
        }

        if ($kind === FolderKinds::GALLERY) {
            $others = FolderKinds::notImages($this->assignments->attachmentIdsForFolder($id));

            if ($others !== []) {
                return new WP_Error(
                    'folderfolio_gallery_has_files',
                    sprintf(
                        /* translators: 1: the folder's name, 2: how many of its files are not images. */
                        _n(
                            '“%1$s” holds %2$d file that is not an image. Move it out first, then make the folder a gallery.',
                            '“%1$s” holds %2$d files that are not images. Move them out first, then make the folder a gallery.',
                            count($others),
                            'folderfolio'
                        ),
                        $folder->name,
                        count($others)
                    ),
                    ['status' => 400]
                );
            }
        }

        $written = Transaction::run(function () use ($id, $kind): bool|WP_Error {
            $set = $this->kinds->set($id, $kind);

            if (is_wp_error($set)) {
                return $set;
            }

            if ($kind === FolderKinds::GALLERY && ($this->sorts->for($id)['files'] ?? null) === null) {
                $sorted = $this->sorts->set($id, 'files', 'custom');

                if (is_wp_error($sorted)) {
                    return $sorted;
                }
            }

            return true;
        });

        if (is_wp_error($written)) {
            return $written;
        }

        do_action('folderfolio_folder_kind_changed', $folder, $kind);

        return $folder;
    }

    /**
     * Refuse files that are not images for a gallery; null otherwise.
     *
     * @param array<string, mixed> $folder A row from the repository.
     * @param list<int>            $ids
     */
    private function guardGallery(array $folder, array $ids): ?WP_Error
    {
        if ($ids === [] || !$this->kinds->isGallery((int) $folder['id'])) {
            return null;
        }

        $others = FolderKinds::notImages($ids);

        return $others === []
            ? null
            : FolderKinds::refusal((string) $folder['name'], count($others), count($ids));
    }

    /**
     * The folder tree, with counts.
     *
     * Three queries: the folders, one GROUP BY for the counts, and — for the
     * inherited total — the files filed more than once. The roll-up happens in
     * PHP. Competitors either omit inherited counts or default to
     * direct-only; we default to inherited because a parent reading `0` while
     * holding a hundred files is worse than no badge at all.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(
        string $objectType = FolderRepository::DEFAULT_OBJECT_TYPE,
        ?string $countMode = null
    ): array {
        // Sorts before counts, and before the `none` branch below: a folder's
        // own order is not a count mode's business, and returning early
        // without it would hand the client a tree whose nodes are missing two
        // keys depending on a setting.
        $rows = $this->folders->all($objectType);
        $nodes = FolderTree::withKinds(
            FolderTree::withMarks(
                FolderTree::withSorts(FolderTree::fromRows($rows), $this->sorts->all()),
                $this->locks->locked(),
                $this->locks->pinned()
            ),
            $this->kinds->galleries()
        );

        // No argument means "whatever the site is set to". The literal
        // default used to live here, which made the setting unreachable from
        // every caller that did not know to pass it.
        /** @var string $mode */
        $mode = apply_filters('folderfolio_count_mode', $countMode ?? Settings::get()['count_mode']);

        if ($mode === 'none') {
            return $nodes;
        }

        $inherited = $mode !== 'direct';

        // Files filed in more than one folder: the input to both corrections
        // below, asked once. Empty — and one GROUP BY — on a library filed one
        // file to one folder.
        $multiFiled = $this->assignments->multiFiled($objectType);

        // Only the inherited total can count a file twice.
        $overcount = [];

        if ($inherited) {
            $paths = [];

            foreach ($rows as $row) {
                $paths[(int) $row['id']] = (string) $row['path'];
            }

            $overcount = FolderTree::overcount($multiFiled, $paths);
        }

        return FolderTree::withCounts(
            $nodes,
            $this->assignments->directCounts($objectType),
            $inherited,
            $overcount,
            FolderTree::shared($multiFiled)
        );
    }

    public function get(int $id): ?Folder
    {
        $row = $this->folders->find($id);

        return $row === null ? null : Folder::fromRow($row);
    }

    /**
     * Ancestors of a folder, outermost first. No queries for the chain itself.
     *
     * @return list<Folder>
     */
    public function ancestors(int $id): array
    {
        $folder = $this->get($id);

        if ($folder === null) {
            return [];
        }

        $ancestors = [];

        foreach ($folder->ancestorIds() as $ancestorId) {
            $row = $this->folders->find($ancestorId);

            if ($row !== null) {
                $ancestors[] = Folder::fromRow($row);
            }
        }

        return $ancestors;
    }

    /**
     * Ids of a folder and every folder beneath it.
     *
     * @return list<int>
     */
    public function subtreeIds(int $id): array
    {
        $folder = $this->get($id);

        return $folder === null ? [] : $this->folders->subtreeIds($folder->path);
    }

    /**
     * Create a folder.
     *
     * @param FolderInput $data
     * @return Folder|WP_Error
     */
    public function create(array $data): Folder|WP_Error
    {
        $data = $this->sanitize($data);
        $validation = $this->validate($data, true);

        if (is_wp_error($validation)) {
            return $validation;
        }

        $parentId = $data['parent_id'] ?? null;
        $parentPath = null;
        $objectType = $data['object_type'] ?? FolderRepository::DEFAULT_OBJECT_TYPE;

        if ($parentId !== null) {
            $parent = $this->folders->find($parentId);

            if ($parent === null) {
                return new WP_Error(
                    'folderfolio_invalid_parent',
                    __('The selected parent folder does not exist.', 'folderfolio')
                );
            }

            $mixed = $this->wrongType($parent, $objectType);

            if ($mixed !== null) {
                return $mixed;
            }

            $parentPath = (string) $parent['path'];

            // Nothing is made inside a locked folder — tier 2 item 10. Here,
            // so the rail, paste, bulk-create, the importer, a dropped
            // directory and the CLI are all refused by the same sentence.
            $locked = $this->locks->guard($parentPath, $this->nameOf());

            if ($locked !== null) {
                return $locked;
            }

            $tooDeep = $this->guardDepth(FolderPath::depth($parentPath) + 1);

            if (is_wp_error($tooDeep)) {
                return $tooDeep;
            }
        }

        // A type with folders turned off has no tree to add to. The REST routes
        // never reach here for one (their permission asks the same), but the
        // facade and WP-CLI did, and made folders nobody could see (review L6).
        if (!PostTypes::isEnabled($objectType)) {
            return new WP_Error(
                'folderfolio_type_without_folders',
                __('Folders are not turned on for that content type.', 'folderfolio')
            );
        }

        if ($this->folders->siblingNameExists($data['name'] ?? '', $parentId, null, $objectType)) {
            return new WP_Error(
                'folderfolio_duplicate_name',
                __('A folder with that name already exists here.', 'folderfolio')
            );
        }

        $id = $this->folders->create($data, $parentPath);

        if (is_wp_error($id)) {
            return $id;
        }

        $folder = $this->get($id);

        if ($folder === null) {
            return new WP_Error(
                'folderfolio_folder_create_failed',
                __('The folder could not be created.', 'folderfolio')
            );
        }

        // After the commit, like every other hook here (review M12): inside a
        // bulk create or a paste this runs in an open transaction, and a
        // listener heard about folders a later refusal rolled back — or ended
        // the transaction early by committing. Outside one it runs at once.
        Transaction::after(static function () use ($folder): void {
            do_action('folderfolio_folder_created', $folder);
        });

        return $folder;
    }

    /**
     * Update a folder's own attributes. Use move() to change its parent.
     *
     * @param FolderInput $data
     * @return Folder|WP_Error
     */
    public function update(int $id, array $data): Folder|WP_Error
    {
        $existing = $this->get($id);

        if ($existing === null) {
            return new WP_Error(
                'folderfolio_folder_not_found',
                __('Folder not found.', 'folderfolio')
            );
        }

        $data = $this->sanitize($data, false);
        unset($data['parent_id'], $data['object_type']);

        $validation = $this->validate($data, false);

        if (is_wp_error($validation)) {
            return $validation;
        }

        // A rename changes a locked folder's shape; a colour or an icon does
        // not, and stays allowed (Nick's answer 6, board 3ZU8VGkJemznTvKp8tNnvY).
        // A new sort_order moves it within its level, which reorder and pin
        // both refuse on a locked folder, so this does too (review L1).
        if (
            (isset($data['name']) && $data['name'] !== $existing->name)
            || (array_key_exists('sort_order', $data) && $data['sort_order'] !== $existing->sortOrder)
        ) {
            $locked = $this->locks->guard($existing->path, $this->nameOf());

            if ($locked !== null) {
                return $locked;
            }
        }

        if (
            isset($data['name'])
            && $data['name'] !== $existing->name
            && $this->folders->siblingNameExists(
                $data['name'],
                $existing->parentId,
                $id,
                $existing->objectType
            )
        ) {
            return new WP_Error(
                'folderfolio_duplicate_name',
                __('A folder with that name already exists here.', 'folderfolio')
            );
        }

        $updated = $this->folders->update($id, $data);

        if (is_wp_error($updated)) {
            return $updated;
        }

        $folder = $this->get($id) ?? $existing;

        if (isset($data['name']) && $data['name'] !== $existing->name) {
            do_action('folderfolio_folder_renamed', $folder, $existing->name);
        }

        if (array_key_exists('color', $data) && $data['color'] !== $existing->color) {
            do_action('folderfolio_folder_color_changed', $folder, $existing->color);
        }

        return $folder;
    }

    /**
     * Move a folder, and its whole subtree with it.
     *
     * The cycle check is a string prefix test rather than a walk up the tree,
     * and re-pointing the descendants is one UPDATE however deep the subtree
     * goes. Both are what the path column is for.
     *
     * @return Folder|WP_Error
     */
    public function move(int $id, ?int $parentId): Folder|WP_Error
    {
        $folder = $this->get($id);

        if ($folder === null) {
            return new WP_Error(
                'folderfolio_folder_not_found',
                __('Folder not found.', 'folderfolio')
            );
        }

        if ($parentId === $id) {
            return new WP_Error(
                'folderfolio_circular_parent',
                __('A folder cannot be its own parent.', 'folderfolio')
            );
        }

        if ($parentId === $folder->parentId) {
            return $folder;
        }

        // Out of a locked folder, or a locked folder itself.
        $locked = $this->locks->guard($folder->path, $this->nameOf());

        if ($locked !== null) {
            return $locked;
        }

        $parentPath = null;
        $newDepth = 0;

        if ($parentId !== null) {
            $parent = $this->folders->find($parentId);

            if ($parent === null) {
                return new WP_Error(
                    'folderfolio_invalid_parent',
                    __('The selected parent folder does not exist.', 'folderfolio')
                );
            }

            $mixed = $this->wrongType($parent, $folder->objectType);

            if ($mixed !== null) {
                return $mixed;
            }

            $parentPath = (string) $parent['path'];

            // And into one.
            $locked = $this->locks->guard($parentPath, $this->nameOf());

            if ($locked !== null) {
                return $locked;
            }

            // The whole cycle check: is the proposed parent inside the subtree
            // we are about to move? One prefix comparison, no queries.
            if (FolderPath::isWithin($parentPath, $folder->path)) {
                return new WP_Error(
                    'folderfolio_circular_parent',
                    __('A folder cannot be moved inside one of its descendants.', 'folderfolio')
                );
            }

            $newDepth = FolderPath::depth($parentPath) + 1;
        }

        $subtreeDepth = $this->deepestDepthIn($folder->path) - $folder->depth;

        $tooDeep = $this->guardDepth($newDepth + $subtreeDepth);

        if (is_wp_error($tooDeep)) {
            return $tooDeep;
        }

        if (
            $this->folders->siblingNameExists($folder->name, $parentId, $id, $folder->objectType)
        ) {
            return new WP_Error(
                'folderfolio_duplicate_name',
                __('A folder with that name already exists there.', 'folderfolio')
            );
        }

        $newPath = FolderPath::build($parentPath, $id);

        // Two statements, and the pair is the invariant: `path`/`depth` on the
        // whole subtree, then `parent_id` on this folder. Apply one without the
        // other and the derived columns disagree with the source of truth —
        // which every subtree read then trusts, because they are all
        // `WHERE path LIKE '<path>%'`.
        return Transaction::run(function () use ($id, $parentId, $folder, $newPath, $newDepth): Folder|WP_Error {
            $rewritten = $this->folders->rewriteSubtreePaths(
                $folder->path,
                $newPath,
                $newDepth - $folder->depth
            );

            if (is_wp_error($rewritten)) {
                return $rewritten;
            }

            $updated = $this->folders->update($id, ['parent_id' => $parentId]);

            if (is_wp_error($updated)) {
                return $updated;
            }

            $moved = $this->get($id) ?? $folder;

            Transaction::after(static function () use ($moved, $folder): void {
                do_action('folderfolio_folder_moved', $moved, $folder->parentId);
            });

            return $moved;
        });
    }

    /**
     * Arrange a set of siblings.
     *
     * Takes the whole level in its new order rather than a delta. Whole-list
     * is idempotent, needs no index arithmetic, and cannot half-apply: a
     * client working from a stale tree gets a rejection instead of an order
     * that silently drops the folder somebody else just created.
     *
     * A drag that crosses parents is a move *and* a reorder, so the move is
     * folded in here rather than left to a second request. Two requests leave
     * the folder in its new parent at the wrong position when the second one
     * fails, and that wrong position is already on the screen.
     *
     * The move is delegated to move() rather than reimplemented: the cycle
     * check, the depth guard, the duplicate-name check and the subtree path
     * rewrite all live there, and Transaction nests by savepoint so the two
     * are still one unit of work.
     *
     * @param list<int> $ids The level's folders, in the order they should sit.
     * @return int|WP_Error Size of the level that was arranged.
     */
    public function reorder(?int $parentId, array $ids): int|WP_Error
    {
        $ids = array_values(array_map('intval', $ids));

        if ($ids === []) {
            return new WP_Error(
                'folderfolio_reorder_empty',
                __('No folders were given to arrange.', 'folderfolio')
            );
        }

        if (count($ids) !== count(array_unique($ids))) {
            return new WP_Error(
                'folderfolio_reorder_duplicate',
                __('The same folder was listed more than once.', 'folderfolio')
            );
        }

        if ($parentId !== null && in_array($parentId, $ids, true)) {
            return new WP_Error(
                'folderfolio_circular_parent',
                __('A folder cannot be its own parent.', 'folderfolio')
            );
        }

        /** @var array<int, Folder> $folders */
        $folders = [];

        foreach ($ids as $id) {
            $folder = $this->get($id);

            if ($folder === null) {
                return new WP_Error(
                    'folderfolio_folder_not_found',
                    __('Folder not found.', 'folderfolio')
                );
            }

            $folders[$id] = $folder;
        }

        $objectType = $folders[$ids[0]]->objectType;

        if ($parentId !== null) {
            $parent = $this->folders->find($parentId);

            if ($parent === null) {
                return new WP_Error(
                    'folderfolio_invalid_parent',
                    __('The selected parent folder does not exist.', 'folderfolio')
                );
            }

            $objectType = (string) $parent['object_type'];

            // Arranging a locked folder's children rearranges its shape.
            $locked = $this->locks->guard((string) $parent['path'], $this->nameOf());

            if ($locked !== null) {
                return $locked;
            }
        }

        foreach ($folders as $folder) {
            if ($folder->objectType !== $objectType) {
                return new WP_Error(
                    'folderfolio_reorder_mixed',
                    __('Those folders do not all belong to the same level.', 'folderfolio')
                );
            }
        }

        // The list has to describe the level as it will be, not a subset of
        // it. Anything the caller left out would keep whatever sort_order it
        // has and land somewhere nobody chose.
        $existing = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->folders->siblingsOf($parentId, $objectType)
        );

        if (array_values(array_diff($existing, $ids)) !== []) {
            return new WP_Error(
                'folderfolio_reorder_stale',
                __('This folder list is out of date. Reload the page and try again.', 'folderfolio')
            );
        }

        $incoming = array_values(array_diff($ids, $existing));

        $moved = $this->lockedFolderMoved($existing, $ids);

        if ($moved !== null) {
            return $moved;
        }

        return Transaction::run(function () use ($ids, $parentId, $incoming): int|WP_Error {
            foreach ($incoming as $id) {
                $moved = $this->move($id, $parentId);

                if (is_wp_error($moved)) {
                    return $moved;
                }
            }

            $written = $this->folders->applySortOrder($ids);

            if (is_wp_error($written)) {
                return $written;
            }

            Transaction::after(static function () use ($ids, $parentId): void {
                do_action('folderfolio_folders_reordered', $ids, $parentId);
            });

            return $written;
        });
    }

    /**
     * A reorder whose one change is a locked folder changing place.
     *
     * The client sends a whole level, never a delta, so which folder moved is
     * read back: for each locked folder in the level, take it out of both the
     * stored order and the requested one — if what is left is identical and
     * its own place differs, it is the folder that moved. Another folder
     * moving past it shifts its index but is not it moving, and stays
     * allowed. The rail never offers the move (its rows are disabled); this
     * is the rule for a request that did not come from the rail.
     *
     * @param list<int> $existing The level as stored.
     * @param list<int> $requested The level as asked for.
     */
    private function lockedFolderMoved(array $existing, array $requested): ?WP_Error
    {
        $first = $existing === [] ? null : $this->get($existing[0]);

        if ($this->locks->exempt($first->objectType ?? PostTypes::MEDIA)) {
            return null;
        }

        $locked = $this->locks->locked();

        foreach ($existing as $index => $id) {
            if (!isset($locked[$id])) {
                continue;
            }

            $without = static fn (array $list): array => array_values(array_diff($list, [$id]));
            $newIndex = array_search($id, $requested, true);

            if ($newIndex !== false && $newIndex !== $index && $without($existing) === $without($requested)) {
                return FolderLocks::refusal((string) ($this->folders->find($id)['name'] ?? ''));
            }
        }

        return null;
    }

    /**
     * Paste a copy of a folder, and everything beneath it.
     *
     * Names, colours, icons, each folder's place among its siblings and both
     * of its own orders, at every depth — and, when `$withFiles`, every file
     * filed in the same folders of the copy, in the same positions. Nothing is
     * duplicated in the media library itself: membership is many-to-many, so
     * "with files" means the same attachments filed in two places.
     *
     * Everything that can refuse the paste is asked **before** the transaction
     * opens, the way `FolderBulk::plan()` does: the parent, pasting a folder
     * inside itself, the depth of the whole subtree at its new home, and — with
     * files — whether this person may file every one of them. A paste that is
     * going to be refused should not cost a half-built tree and a rollback.
     * Then one `Transaction`, the rule every batch write here follows: a
     * failure at the fortieth folder undoes the first thirty-nine.
     *
     * Only the top folder's name can collide — `FolderCopy::name()` — because
     * every folder beneath it lands under a parent that did not exist a moment
     * ago. It goes last in its level unless `$order` places it: the level as
     * the person sees it with a `0` where the copy goes, which is handed to
     * `reorder()` inside the same unit of work. The client sends that only
     * when the level is in Custom order, the one sort in which a position is
     * something a person can see.
     *
     * @param list<int>|null $order
     * @return Folder|WP_Error The copy of the top folder.
     */
    public function duplicate(
        int $id,
        ?int $parentId,
        bool $withFiles = false,
        ?array $order = null
    ): Folder|WP_Error {
        $source = $this->get($id);

        if ($source === null) {
            return new WP_Error(
                'folderfolio_folder_not_found',
                __('Folder not found.', 'folderfolio')
            );
        }

        $objectType = $source->objectType;
        $newDepth = 0;

        if ($parentId !== null) {
            $parent = $this->folders->find($parentId);

            if ($parent === null || (string) $parent['object_type'] !== $objectType) {
                return new WP_Error(
                    'folderfolio_invalid_parent',
                    __('The selected parent folder does not exist.', 'folderfolio')
                );
            }

            $parentPath = (string) $parent['path'];

            // A copy is made inside its destination, and nothing is made
            // inside a locked folder. The source may be locked — copying
            // changes nothing about it — and the copy comes out unlocked.
            $locked = $this->locks->guard($parentPath, $this->nameOf());

            if ($locked !== null) {
                return $locked;
            }

            // Finder refuses this too. A copy taken as a snapshot could be
            // pasted inside itself, but "Brand inside Brand/Logos" is almost
            // always a slip of the pointer, and refusing it keeps one rule for
            // cut and copy alike.
            if (FolderPath::isWithin($parentPath, $source->path)) {
                return new WP_Error(
                    'folderfolio_paste_into_itself',
                    __('A folder cannot be pasted inside itself.', 'folderfolio')
                );
            }

            $newDepth = FolderPath::depth($parentPath) + 1;
        }

        // Depth ascending, so every folder's parent has been copied before it
        // is — the id map below is always ready when a child asks it.
        $rows = $this->folders->subtree($source->path);
        $deepest = $source->depth;

        foreach ($rows as $row) {
            $deepest = max($deepest, (int) $row['depth']);
        }

        $tooDeep = $this->guardDepth($newDepth + ($deepest - $source->depth));

        if (is_wp_error($tooDeep)) {
            return $tooDeep;
        }

        if ($order !== null) {
            $order = array_values(array_map('intval', $order));

            if (FolderCopy::place($order, PHP_INT_MAX) === null) {
                return new WP_Error(
                    'folderfolio_reorder_malformed',
                    __('This folder list is out of date. Reload the page and try again.', 'folderfolio')
                );
            }
        }

        /** @var array<int, list<int>> $files */
        $files = [];

        if ($withFiles) {
            $everything = [];

            foreach ($rows as $row) {
                $files[(int) $row['id']] = $this->assignments->attachmentIdsForFolder((int) $row['id']);
                array_push($everything, ...$files[(int) $row['id']]);
            }

            // The same question assignAttachments() asks, over the whole
            // subtree at once and before anything is written: one file this
            // person may not organise refuses the paste, rather than a copy
            // that silently leaves it out and looks complete.
            $permitted = $this->guardAttachments(array_values(array_unique($everything)), $objectType);

            if (is_wp_error($permitted)) {
                return $permitted;
            }
        }

        $name = FolderCopy::name(
            $source->name,
            fn (string $candidate): bool => $this->folders->siblingNameExists(
                $candidate,
                $parentId,
                null,
                $objectType
            ),
            /* translators: %s: the name of the folder that was copied. */
            __('%s copy', 'folderfolio'),
            /* translators: 1: the name of the folder that was copied, 2: a number, 2 or more. */
            __('%1$s copy %2$d', 'folderfolio')
        );

        // Last in its level: one past the highest sort_order there. Under any
        // sort but Custom the position is not visible and this is harmless;
        // under Custom it is what "paste inside" says.
        $last = 0;

        foreach ($this->folders->siblingsOf($parentId, $objectType) as $sibling) {
            $last = max($last, (int) $sibling['sort_order'] + 1);
        }

        $sorts = $this->sorts->all();

        return Transaction::run(function () use (
            $rows,
            $source,
            $parentId,
            $objectType,
            $name,
            $last,
            $sorts,
            $files,
            $withFiles,
            $order
        ): Folder|WP_Error {
            /** @var array<int, int> $map source id => copy id */
            $map = [];

            foreach ($rows as $row) {
                $oldId = (int) $row['id'];
                $isTop = $oldId === $source->id;

                $created = $this->create([
                    'name'        => $isTop ? $name : (string) $row['name'],
                    'parent_id'   => $isTop ? $parentId : $map[(int) $row['parent_id']],
                    'object_type' => $objectType,
                    'color'       => $row['color'] ?? null,
                    'icon'        => $row['icon'] ?? null,
                    'sort_order'  => $isTop ? $last : (int) $row['sort_order'],
                ]);

                if (is_wp_error($created)) {
                    return $created;
                }

                $map[$oldId] = $created->id;

                foreach (FolderSorts::SCOPES as $scope) {
                    $chosen = $sorts[$oldId][$scope] ?? null;

                    if ($chosen === null) {
                        continue;
                    }

                    $written = $this->sorts->set($created->id, $scope, $chosen);

                    // A stored order that is no longer one of ours is not a
                    // reason to refuse the paste — the client already ignores
                    // it (isSortOrder) and the copy simply follows the global
                    // sort. A write that failed is.
                    if (is_wp_error($written) && $written->get_error_code() === 'folderfolio_sort_failed') {
                        return $written;
                    }
                }

                // A copy of a gallery is a gallery: the kind is what the
                // folder is, like its colour — unlike a lock, which is a
                // permission on the original. Its files, if any come with
                // it, are images already.
                if ($this->kinds->isGallery($oldId)) {
                    $kind = $this->kinds->set($created->id, FolderKinds::GALLERY);

                    if (is_wp_error($kind)) {
                        return $kind;
                    }
                }

                if ($withFiles && ($files[$oldId] ?? []) !== []) {
                    $copied = $this->assignments->copyFolder($oldId, $created->id);

                    if (is_wp_error($copied)) {
                        return $copied;
                    }
                }
            }

            $copyId = $map[$source->id];

            if ($order !== null) {
                $placed = FolderCopy::place($order, $copyId);
                $arranged = $placed === null
                    ? new WP_Error(
                        'folderfolio_reorder_malformed',
                        __('This folder list is out of date. Reload the page and try again.', 'folderfolio')
                    )
                    : $this->reorder($parentId, $placed);

                if (is_wp_error($arranged)) {
                    return $arranged;
                }
            }

            $copy = $this->get($copyId);

            if ($copy === null) {
                return new WP_Error(
                    'folderfolio_folder_create_failed',
                    __('The folder could not be created.', 'folderfolio')
                );
            }

            Transaction::after(static function () use ($copy, $source, $map, $files, $withFiles): void {
                // The filing hook once per folder that received files, so a
                // listener keeping its own index sees every row this wrote —
                // the same contract a bulk Add to folder keeps.
                foreach ($files as $oldId => $ids) {
                    if ($ids !== []) {
                        do_action('folderfolio_attachments_assigned', $ids, $map[$oldId], self::MODE_ADD);
                    }
                }

                do_action('folderfolio_folder_duplicated', $copy, $source, $map, $withFiles);
            });

            return $copy;
        });
    }

    /**
     * Delete a folder.
     *
     * $children is required by the REST route rather than defaulted there:
     * "what happens to my subfolders" is not a question to answer silently.
     *
     * @param string $children One of the CHILDREN_* constants; validated below.
     * @return int|WP_Error Number of folders deleted.
     */
    public function delete(
        int $id,
        string $children = self::CHILDREN_REPARENT,
        ?int $reassignAttachmentsTo = null
    ): int|WP_Error {
        $folder = $this->get($id);

        if ($folder === null) {
            return new WP_Error(
                'folderfolio_folder_not_found',
                __('Folder not found.', 'folderfolio')
            );
        }

        if (!in_array($children, [self::CHILDREN_REPARENT, self::CHILDREN_CASCADE], true)) {
            return new WP_Error(
                'folderfolio_invalid_children_strategy',
                __('Choose whether subfolders are kept or deleted.', 'folderfolio')
            );
        }

        // Inside a locked folder, or with one inside it: a cascade would
        // delete it and a reparent would move it.
        $locked = $this->locks->guard($folder->path, $this->nameOf())
            ?? $this->locks->guardSubtree($this->folders->subtreeIds($folder->path), $this->nameOf());

        if ($locked !== null) {
            return $locked;
        }

        // Validated before the transaction opens: nothing here writes, and a
        // request that is going to be refused should not cost a transaction.
        //
        // A cascade deletes everything under the folder too, so a destination
        // inside it would be filled and then deleted, and the files would end
        // up in no folder while the call said it succeeded (review M3). A
        // reparent keeps the children, so one of them is a fine destination.
        $destination = $reassignAttachmentsTo === null ? null : $this->folders->find($reassignAttachmentsTo);

        if (
            $reassignAttachmentsTo !== null
            && (
                $reassignAttachmentsTo === $id
                || $destination === null
                || $this->wrongType($destination, $folder->objectType) !== null
                || (
                    $children === self::CHILDREN_CASCADE
                    && FolderPath::isWithin((string) $destination['path'], $folder->path)
                )
            )
        ) {
            return new WP_Error(
                'folderfolio_invalid_reassignment',
                __('Choose a valid destination folder.', 'folderfolio')
            );
        }

        /*
         * The whole delete is one unit, reassignment included. Both branches
         * below remove the assignment rows before the folders, and the rows are
         * the only record of what was in them — so a failure between the two
         * statements used to leave folders standing and empty, with nothing
         * anywhere able to say what they had held. Reassignment is inside the
         * same unit rather than before it: a delete that fails after moving
         * someone's files out of the folder they were looking at is not a
         * delete that failed, it is a move they did not ask for.
         */
        return Transaction::run(function () use (
            $id,
            $children,
            $reassignAttachmentsTo,
            $folder
        ): int|WP_Error {
            if ($reassignAttachmentsTo !== null) {
                $source = $children === self::CHILDREN_CASCADE
                    ? $this->assignments->subtreeAttachmentIds($folder->path)
                    : $this->assignments->attachmentIdsForFolder($id);

                $reassigned = $this->assignAttachments($reassignAttachmentsTo, $source, self::MODE_ADD);

                if (is_wp_error($reassigned)) {
                    return $reassigned;
                }
            }

            if ($children === self::CHILDREN_CASCADE) {
                $ids = $this->folders->subtreeIds($folder->path);

                $cleaned = $this->assignments->deleteForFolders($ids);

                if (is_wp_error($cleaned)) {
                    return $cleaned;
                }

                $deleted = $this->folders->deleteSubtree($folder->path);

                if (is_wp_error($deleted)) {
                    return $deleted;
                }

                $meta = $this->folders->deleteMeta($ids);

                if (is_wp_error($meta)) {
                    return $meta;
                }

                Transaction::after(static function () use ($id, $children, $ids): void {
                    do_action('folderfolio_folder_deleted', $id, $children, $ids);
                });

                return $deleted;
            }

            // Reparent: each child takes this folder's place in the hierarchy.
            // Each move() opens a savepoint inside this transaction, so a
            // failure at the fourth child undoes the first three as well —
            // before, they stayed moved and the folder stayed put.
            foreach ($this->folders->children($id) as $child) {
                $moved = $this->move((int) $child['id'], $folder->parentId);

                if (is_wp_error($moved)) {
                    return $moved;
                }
            }

            $cleaned = $this->assignments->deleteForFolder($id);

            if (is_wp_error($cleaned)) {
                return $cleaned;
            }

            $removed = $this->folders->delete($id);

            if (is_wp_error($removed)) {
                return $removed;
            }

            $meta = $this->folders->deleteMeta([$id]);

            if (is_wp_error($meta)) {
                return $meta;
            }

            Transaction::after(static function () use ($id, $children): void {
                do_action('folderfolio_folder_deleted', $id, $children, [$id]);
            });

            return 1;
        });
    }

    /**
     * File attachments into a folder.
     *
     * MODE_ADD leaves every existing assignment alone — this is what makes the
     * migration wizard safe, and it is the behaviour no competitor offers:
     * all four examined implement assignment as delete-then-insert, so
     * importing takes a file out of whatever folder its owner had put it in.
     *
     * MODE_MOVE is the drag gesture: file here, and nowhere else.
     *
     * $importRun marks the rows as one import's work, so that undoing that
     * import removes exactly them and leaves anything a person filed — before
     * it, or during it — where it is. Null for every other caller, which is
     * all of them but the migration wizard.
     *
     * @param list<int|string> $attachmentIds
     * @param string           $mode One of the MODE_* constants; validated below.
     * @return int|WP_Error Number of attachments filed.
     */
    public function assignAttachments(
        int $folderId,
        array $attachmentIds,
        string $mode = self::MODE_ADD,
        ?string $importRun = null
    ): int|WP_Error {
        $folder = $this->folders->find($folderId);

        if ($folder === null) {
            return new WP_Error(
                'folderfolio_folder_not_found',
                __('Folder not found.', 'folderfolio')
            );
        }

        if (!in_array($mode, [self::MODE_ADD, self::MODE_MOVE], true)) {
            return new WP_Error(
                'folderfolio_invalid_mode',
                __('Choose whether to add to the folder or move into it.', 'folderfolio')
            );
        }

        $ids = array_values(array_unique(array_map('intval', $attachmentIds)));

        $permitted = $this->guardAttachments($ids, (string) $folder['object_type']);

        if (is_wp_error($permitted)) {
            return $permitted;
        }

        // Every way a file reaches a folder ends here — a drag, Add to
        // folder, an upload (UploadRouter), a delete's reassignment, an
        // import — so a gallery's rule is asked once, in one place. The
        // upload is also refused before it is stored (UploadTarget), so the
        // person sees the reason where the upload failed.
        $gallery = $this->guardGallery($folder, $ids);

        if ($gallery !== null) {
            return $gallery;
        }

        if ($ids === []) {
            return 0;
        }

        /*
         * All or nothing, for two reasons.
         *
         * MODE_MOVE is the sharp one: it deletes an attachment's existing rows
         * and then writes the new one, so a failure between those two left the
         * file in no folder at all — its previous filing gone and the new one
         * never written. That is the drag gesture, and it failed silently.
         *
         * The batch as a whole matters too. A bulk action that failed at item
         * seven of twenty used to leave six filed and report an error, so the
         * undo toast offered to undo work that had only partly happened. Now
         * the error means nothing was done, which is a sentence the UI can tell
         * the truth with.
         */
        /*
         * Where each file goes in this folder (tier 2 item 8).
         *
         * A file already filed here keeps its place: `assign()` is a REPLACE,
         * and it used to write the file's index in this batch, so Add to
         * folder on a file already there moved it — in a folder somebody had
         * arranged by hand. Read before the transaction's deletes, because a
         * move deletes the row it is about to re-write.
         *
         * A new file goes first, before everything already here, in the order
         * of the batch. First is where an upload is visible the moment it
         * finishes, which is the answer Nick chose (board 66d5HKiPtdtNYTf7JWWBG5).
         */
        $kept = $this->assignments->positionsIn($folderId, $ids);
        $newCount = count(array_diff($ids, array_keys($kept)));
        $next = ($this->assignments->firstPosition($folderId) ?? 0) - $newCount;

        $positions = [];

        foreach ($ids as $attachmentId) {
            $positions[$attachmentId] = $kept[$attachmentId] ?? $next++;
        }

        return Transaction::run(function () use ($ids, $folderId, $mode, $importRun, $positions): int|WP_Error {
            $assigned = 0;

            foreach ($ids as $attachmentId) {
                $position = $positions[$attachmentId];

                if ($mode === self::MODE_MOVE) {
                    $cleared = $this->assignments->deleteForAttachment($attachmentId);

                    if (is_wp_error($cleared)) {
                        return $cleared;
                    }
                }

                $result = $this->assignments->assign($folderId, $attachmentId, $position, $importRun);

                if (is_wp_error($result)) {
                    return $result;
                }

                $assigned++;
            }

            Transaction::after(static function () use ($ids, $folderId, $mode): void {
                do_action('folderfolio_attachments_assigned', $ids, $folderId, $mode);
            });

            return $assigned;
        });
    }

    /**
     * A folder's files, in the order the library shows them.
     *
     * Asked of WP_Query with the library's own filter and ordering rather than
     * of the assignments table, so "the order you were looking at" is exactly
     * that — the folder's own file sort, or newest first, or its positions.
     * Rows the query does not return (a file whose post is gone) follow in
     * table order, so every row still gets a position when the folder is
     * rewritten.
     *
     * @return list<int>
     */
    public function orderedAttachmentIds(int $folderId): array
    {
        // The folder's own kind of content, in the statuses its list screen
        // shows — a post folder's drafts count, its trash does not.
        $row = $this->folders->find($folderId);
        $objectType = $row === null ? FolderRepository::DEFAULT_OBJECT_TYPE : (string) $row['object_type'];

        $query = new \WP_Query([
            'post_type' => $objectType,
            'post_status' => PostTypes::statuses($objectType),
            'fields' => 'ids',
            'posts_per_page' => -1,
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'suppress_filters' => false,
            \FolderFolio\Admin\MediaLibraryFilter::QUERY_VAR => $folderId,
        ] + \FolderFolio\Admin\MediaLibraryFilter::ordering($folderId));

        $shown = array_map('intval', $query->posts);
        $filed = $this->assignments->attachmentIdsForFolder($folderId);

        return array_values(array_unique([...array_intersect($shown, $filed), ...$filed]));
    }

    /**
     * A folder's files and then its subfolders', each in its own order.
     *
     * For the gallery block's Folder order with subfolders included: the
     * folder first, then each subfolder in the tree's stored order — every
     * one of them showing its files the way it does in the library.
     *
     * @return list<int>
     */
    public function orderedSubtreeAttachmentIds(int $folderId): array
    {
        $folder = $this->get($folderId);

        if ($folder === null) {
            return [];
        }

        $ids = [];

        foreach ($this->folders->subtree($folder->path) as $row) {
            array_push($ids, ...$this->orderedAttachmentIds((int) $row['id']));
        }

        return array_values(array_unique($ids));
    }

    /**
     * Put files where they were dropped — tier 2 item 8.
     *
     * `$place` is `start`, `end`, or `before` / `after` the anchor file. The
     * files keep the order they had among themselves. The folder is rewritten
     * whole from the order it was showing, so the first move in a folder
     * sorted by name keeps that name order with the files moved — and the
     * folder becomes Custom, because that is the only order in which the
     * arrangement just made is visible. The folder tree does the same on a
     * drop.
     *
     * @param list<int|string> $attachmentIds
     * @return int|WP_Error How many positions were written.
     */
    public function moveFiles(int $folderId, array $attachmentIds, string $place, ?int $anchorId = null): int|WP_Error
    {
        $folder = $this->folders->find($folderId);

        if ($folder === null) {
            return new WP_Error('folderfolio_folder_not_found', __('Folder not found.', 'folderfolio'));
        }

        if (!in_array($place, ['start', 'end', 'before', 'after'], true)) {
            return new WP_Error(
                'folderfolio_order_place',
                __('Say where the files go: start, end, before or after a file.', 'folderfolio'),
                ['status' => 400]
            );
        }

        $ids = array_values(array_unique(array_map('intval', $attachmentIds)));
        $current = $this->orderedAttachmentIds($folderId);

        if ($ids === [] || array_diff($ids, $current) !== []) {
            return new WP_Error(
                'folderfolio_order_not_here',
                __('Only files in this folder can be arranged in it. Reload the page and try again.', 'folderfolio'),
                ['status' => 400]
            );
        }

        $anchored = $place === 'before' || $place === 'after';

        if ($anchored && ($anchorId === null || !in_array($anchorId, $current, true) || in_array($anchorId, $ids, true))) {
            return new WP_Error(
                'folderfolio_order_anchor',
                __('The file they were dropped beside is not in this folder any more. Reload the page and try again.', 'folderfolio'),
                ['status' => 400]
            );
        }

        $permitted = $this->guardAttachments($ids, (string) $folder['object_type']);

        if (is_wp_error($permitted)) {
            return $permitted;
        }

        // In the order they already had, whatever order they were selected in.
        $moving = array_values(array_intersect($current, $ids));
        $rest = array_values(array_diff($current, $ids));

        $at = match ($place) {
            'start' => 0,
            'end' => count($rest),
            'before' => (int) array_search($anchorId, $rest, true),
            'after' => (int) array_search($anchorId, $rest, true) + 1,
        };

        array_splice($rest, $at, 0, $moving);

        return Transaction::run(function () use ($folderId, $rest): int|WP_Error {
            $written = $this->assignments->writePositions($folderId, $rest);

            if (is_wp_error($written)) {
                return $written;
            }

            $sorted = $this->sorts->set($folderId, 'files', 'custom');

            if (is_wp_error($sorted)) {
                return $sorted;
            }

            Transaction::after(static function () use ($folderId, $rest): void {
                do_action('folderfolio_files_ordered', $rest, $folderId);
            });

            return count($rest);
        });
    }

    /**
     * Remove attachments from a folder, or from every folder when null.
     *
     * @param list<int|string> $attachmentIds
     * @return int|WP_Error
     */
    public function unassignAttachments(?int $folderId, array $attachmentIds): int|WP_Error
    {
        $ids = array_values(array_unique(array_map('intval', $attachmentIds)));

        $permitted = $this->guardAttachments($ids, null);

        if (is_wp_error($permitted)) {
            return $permitted;
        }

        if ($ids === []) {
            return 0;
        }

        return Transaction::run(function () use ($ids, $folderId): int|WP_Error {
            $removed = 0;

            foreach ($ids as $attachmentId) {
                $result = $folderId === null
                    ? $this->assignments->deleteForAttachment($attachmentId)
                    : $this->assignments->unassign($folderId, $attachmentId);

                if (is_wp_error($result)) {
                    return $result;
                }

                $removed++;
            }

            Transaction::after(static function () use ($ids, $folderId): void {
                do_action('folderfolio_attachments_unassigned', $ids, $folderId);
            });

            return $removed;
        });
    }

    /**
     * Move attachments from one folder to another.
     *
     * @param list<int|string> $attachmentIds
     * @return int|WP_Error
     */
    public function moveAttachments(
        int $sourceFolderId,
        int $destinationFolderId,
        array $attachmentIds
    ): int|WP_Error {
        if ($sourceFolderId === $destinationFolderId) {
            return new WP_Error(
                'folderfolio_same_folder',
                __('Choose a different destination folder.', 'folderfolio')
            );
        }

        // Add first, then remove — so the failure this used to have left a file
        // in both folders rather than neither, which is the recoverable
        // direction. It is still wrong: the return value counted the additions
        // and said nothing about a removal that had not happened.
        return Transaction::run(function () use (
            $sourceFolderId,
            $destinationFolderId,
            $attachmentIds
        ): int|WP_Error {
            $assigned = $this->assignAttachments($destinationFolderId, $attachmentIds, self::MODE_ADD);

            if (is_wp_error($assigned)) {
                return $assigned;
            }

            $removed = $this->unassignAttachments($sourceFolderId, $attachmentIds);

            if (is_wp_error($removed)) {
                return $removed;
            }

            return $assigned;
        });
    }

    /**
     * Attachment ids in a folder, optionally including its descendants.
     *
     * @return list<int>
     */
    public function attachmentIds(int $folderId, bool $includeDescendants = false): array
    {
        if (!$includeDescendants) {
            return $this->assignments->attachmentIdsForFolder($folderId);
        }

        $folder = $this->get($folderId);

        return $folder === null
            ? []
            : $this->assignments->subtreeAttachmentIds($folder->path);
    }

    /**
     * Exact attachment count for a folder, optionally including descendants.
     */
    public function countAttachments(int $folderId, bool $includeDescendants = true): int
    {
        $folder = $this->get($folderId);

        if ($folder === null) {
            return 0;
        }

        if (!$includeDescendants) {
            return count($this->assignments->attachmentIdsForFolder($folderId));
        }

        return $this->assignments->subtreeCount($folder->path, $folder->objectType);
    }

    /**
     * Deepest depth value anywhere in a subtree.
     */
    private function deepestDepthIn(string $path): int
    {
        $deepest = 0;

        foreach ($this->folders->subtree($path) as $row) {
            $deepest = max($deepest, (int) $row['depth']);
        }

        return $deepest;
    }

    /**
     * Native `bool|WP_Error`, docblock `true|WP_Error`.
     *
     * A standalone `true` in a type position is PHP **8.2**, and this plugin
     * supports 8.1 — where the parser reads it as a class name and fatals with
     * "Cannot use 'FolderFolio\Domain\true' as class name as it is reserved",
     * before a single test runs. The docblock keeps the precision for the
     * analyser; phpstan.neon pins the version range so the next one is caught
     * here rather than by the 8.1 leg of CI.
     *
     * @return true|WP_Error
     */
    private function guardDepth(int $depth): bool|WP_Error
    {
        $max = FolderPath::capDepth(apply_filters('folderfolio_max_depth', FolderPath::MAX_DEPTH));

        if ($depth > $max) {
            return new WP_Error(
                'folderfolio_max_depth_exceeded',
                sprintf(
                    /* translators: %d: maximum nesting depth. */
                    __('Folders can be nested up to %d levels deep.', 'folderfolio'),
                    $max
                )
            );
        }

        return true;
    }

    /**
     * May these items be filed — each one the folder's kind, and each one this
     * person's to organise?
     *
     * @param list<int>   $ids
     * @param string|null $objectType What every item must be; null asks only
     *                                the permission (taking items out, where
     *                                what they are is not in question).
     * @return true|WP_Error
     */
    private function guardAttachments(array $ids, ?string $objectType = FolderRepository::DEFAULT_OBJECT_TYPE): bool|WP_Error
    {
        // One query per thousand files instead of one per file. Both checks
        // below read the post, and uncached that is a SELECT each: measured
        // on 23 Sep, a with-files copy of a 2,001-folder subtree holding
        // 36,000 files spent 6.1s of its 7.7s here, in 36,001 queries. The
        // same guard runs for every bulk assign. Core's own list screens
        // prime exactly this way before a loop of capability checks.
        foreach (array_chunk(array_map('intval', $ids), 1000) as $chunk) {
            _prime_post_caches($chunk, false, false);
        }

        foreach ($ids as $attachmentId) {
            // wp_attachment_is_image() implied the post type anyway; the only
            // thing that ever asserted was "is an attachment". Since item 12,
            // "is the folder's kind": an image is not filed among posts.
            if ($objectType !== null && get_post_type($attachmentId) !== $objectType) {
                return new WP_Error(
                    'folderfolio_invalid_attachment',
                    $objectType === FolderRepository::DEFAULT_OBJECT_TYPE
                        ? __('One or more selected media items are invalid.', 'folderfolio')
                        : __('One or more of the selected items cannot be filed in this folder.', 'folderfolio')
                );
            }

            if (!Capabilities::canEditAttachment($attachmentId)) {
                return new WP_Error(
                    'folderfolio_attachment_forbidden',
                    __('You are not allowed to organise one or more of the selected media items.', 'folderfolio')
                );
            }
        }

        return true;
    }

    /**
     * Normalize folder input for creation or update.
     *
     * @param FolderInput $data
     * @return FolderData
     */
    private function sanitize(array $data, bool $creating = true): array
    {
        $result = [];

        if ($creating || array_key_exists('name', $data)) {
            $result['name'] = sanitize_text_field((string) ($data['name'] ?? ''));
        }

        if ($creating || array_key_exists('parent_id', $data)) {
            $parentId = $data['parent_id'] ?? null;
            $result['parent_id'] = $parentId === null || $parentId === ''
                ? null
                : absint($parentId);
        }

        if (array_key_exists('object_type', $data)) {
            $result['object_type'] = sanitize_key((string) $data['object_type']);
        }

        if (array_key_exists('color', $data)) {
            if ($data['color'] === null || $data['color'] === '') {
                $result['color'] = null;
            } else {
                // A swatch name, or a hex snapped to the nearest one — see
                // Support\Swatches. Keep the raw value when it is neither, so
                // validate() can report the problem; nulling it here would turn
                // a bad colour into a silent no-op.
                $raw = (string) $data['color'];
                $result['color'] = Swatches::normalize($raw) ?? $raw;
            }
        }

        if (array_key_exists('icon', $data)) {
            $result['icon'] = $data['icon'] === null || $data['icon'] === ''
                ? null
                : sanitize_key((string) $data['icon']);
        }

        if (array_key_exists('sort_order', $data)) {
            $result['sort_order'] = (int) $data['sort_order'];
        }

        return $result;
    }

    /**
     * Validate sanitized folder data.
     *
     * @param FolderData $data
     * @return true|WP_Error
     */
    private function validate(array $data, bool $creating): bool|WP_Error
    {
        if ($creating && ($data['name'] ?? '') === '') {
            return new WP_Error(
                'folderfolio_name_required',
                __('A folder name is required.', 'folderfolio')
            );
        }

        if (isset($data['name']) && $data['name'] === '') {
            return new WP_Error(
                'folderfolio_name_required',
                __('A folder name is required.', 'folderfolio')
            );
        }

        if (isset($data['name']) && mb_strlen($data['name']) > 191) {
            return new WP_Error(
                'folderfolio_name_too_long',
                __('Folder names must be 191 characters or fewer.', 'folderfolio')
            );
        }

        if (
            array_key_exists('color', $data)
            && $data['color'] !== null
            && !Swatches::isKey($data['color'])
        ) {
            return new WP_Error(
                'folderfolio_invalid_color',
                sprintf(
                    /* translators: %s: comma-separated list of the ten folder colour names. */
                    __('Choose one of the folder colours: %s.', 'folderfolio'),
                    implode(', ', Swatches::keys())
                )
            );
        }

        return true;
    }

    /**
     * Direct children of a folder.
     *
     * @return list<Folder>
     */
    public function children(int $id): array
    {
        return array_map(
            static fn (array $row): Folder => Folder::fromRow($row),
            $this->folders->children($id)
        );
    }

    /**
     * Folders an attachment is filed in.
     *
     * @return list<Folder>
     */
    public function foldersOf(int $attachmentId): array
    {
        $folders = [];

        foreach ($this->assignments->folderIdsForAttachment($attachmentId) as $folderId) {
            $row = $this->folders->find($folderId);

            if ($row !== null) {
                $folders[] = Folder::fromRow($row);
            }
        }

        return $folders;
    }

    /**
     * Resolve a human path such as "Brand/Logos/Primary".
     *
     * Names, not ids — this is the path a person types, not the internal id
     * path stored in the `path` column.
     */
    public function findByPath(
        string $path,
        string $objectType = FolderRepository::DEFAULT_OBJECT_TYPE
    ): ?Folder {
        $parentId = null;
        $found = null;
        $segments = $this->splitPath($path);

        // A segment that was typed and sanitises to nothing names no folder
        // that can exist, so the path finds nothing — rather than the path
        // with that segment left out, which is a different folder.
        if ($segments === null) {
            return null;
        }

        foreach ($segments as $segment) {
            $row = $this->folders->findByName($segment, $parentId, $objectType);

            if ($row === null) {
                return null;
            }

            $found = Folder::fromRow($row);
            $parentId = $found->id;
        }

        return $found;
    }

    /**
     * Resolve a human path, creating whatever part of it does not exist yet.
     *
     * Idempotent, so calling it on every upload is fine. This is the method
     * most integrations actually want.
     *
     * Idempotent for every name, including one `sanitize_text_field()`
     * changes — "Brand  Assets", "Logos <b>2024". Until tier 2 item 9
     * this looked a segment up **raw** and `create()` stored it
     * **sanitised**, so the second call searched under one spelling, found
     * nothing, and was refused as a duplicate of the folder the first call
     * made. `splitPath()` now sanitises, so the lookup and the write use the
     * same name. A segment that sanitises to nothing is refused rather than
     * dropped: "a/<b>/c" is not a request for "a/c".
     *
     * `$parentId` is where the path starts, and it defaults to the top level,
     * which is what every caller before `Domain\FolderBulk` wanted. It is a
     * seed for the same walk rather than a mode: with it set, "Logos/Primary"
     * means those two under that folder, and the method is otherwise
     * unchanged. Nothing validates it here — `create()` rejects a parent that
     * does not exist, on the first segment, before anything is written.
     *
     * @return Folder|WP_Error
     */
    public function getOrCreateByPath(
        string $path,
        string $objectType = FolderRepository::DEFAULT_OBJECT_TYPE,
        ?int $parentId = null
    ): Folder|WP_Error {
        $segments = $this->splitPath($path);

        if ($segments === null || $segments === []) {
            return new WP_Error(
                'folderfolio_name_required',
                __('A folder name is required.', 'folderfolio')
            );
        }

        $current = null;

        foreach ($segments as $segment) {
            $row = $this->folders->findByName($segment, $parentId, $objectType);

            if ($row !== null) {
                $current = Folder::fromRow($row);
                $parentId = $current->id;
                continue;
            }

            $created = $this->create([
                'name'        => $segment,
                'parent_id'   => $parentId,
                'object_type' => $objectType,
            ]);

            if (is_wp_error($created)) {
                return $created;
            }

            $current = $created;
            $parentId = $created->id;
        }

        // Always set: $segments is non-empty, and every path through the loop
        // either assigns $current or returns early.
        return $current;
    }

    /**
     * Split a human path into its segments, each as it would be stored.
     *
     * Tolerates leading, trailing and doubled separators, so "/a//b/" and
     * "a/b" mean the same thing.
     *
     * Each segment goes through `sanitize_text_field()` — the same call
     * `create()` makes on the way in — because a lookup has to use the name
     * the database holds. Looking up the raw segment is what made
     * `getOrCreateByPath()` fail on its own second call (see its docblock).
     *
     * Null when a segment had text and the sanitiser left nothing of it:
     * that segment names no folder, and quietly dropping it would resolve a
     * different path from the one written.
     *
     * @return list<string>|null
     */
    private function splitPath(string $path): ?array
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if (trim($segment) === '') {
                continue;
            }

            $segment = sanitize_text_field($segment);

            if ($segment === '') {
                return null;
            }

            $segments[] = $segment;
        }

        return $segments;
    }

    /**
     * The subtree under one folder, as nested nodes with counts.
     *
     * @return list<array<string, mixed>>
     */
    public function subtree(int $rootId, string $objectType = FolderRepository::DEFAULT_OBJECT_TYPE): array
    {
        $found = [];

        FolderTree::walk(
            $this->tree($objectType),
            static function (array $node) use (&$found, $rootId): void {
                if ((int) $node['id'] === $rootId) {
                    $found = [$node];
                }
            }
        );

        return $found;
    }
}
