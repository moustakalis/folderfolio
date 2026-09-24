<?php

declare(strict_types=1);

namespace FolderFolio\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Admin\FolderDownload;
use FolderFolio\Admin\RailPreferences;
use FolderFolio\Domain\FolderArchive;
use FolderFolio\Domain\AttachmentFolderRepository;
use FolderFolio\Domain\Folder;
use FolderFolio\Domain\FolderBulk;
use FolderFolio\Domain\FolderLocks;
use FolderFolio\Domain\FolderKinds;
use FolderFolio\Domain\FolderService;
use FolderFolio\Domain\FolderSorts;
use FolderFolio\Domain\FolderTree;
use FolderFolio\Support\Capabilities;
use FolderFolio\Support\PostTypes;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * @phpstan-type RestArgument array{
 *     type?: 'array'|'integer'|'string',
 *     required?: bool,
 *     sanitize_callback?: callable|string,
 *     items?: array{type: 'integer'}
 * }
 *
 * @phpstan-type FolderPayload array{
 *     name?: mixed,
 *     parent_id?: mixed,
 *     color?: mixed,
 *     icon?: mixed,
 *     sort_order?: mixed
 * }
 *
 * @phpstan-type ApiError array{
 *     code: string,
 *     message: string
 * }
 *
 * @phpstan-type ApiFailure array{
 *     success: false,
 *     error: ApiError
 * }
 *
 * @phpstan-type ApiSuccess array{
 *     success: true,
 *     data: mixed
 * }
 */
class FolderController
{
    public const REST_NAMESPACE = 'folderfolio/v1';

    public function __construct(
        private readonly FolderService $folders = new FolderService()
    ) {
    }

    public function registerRoutes(): void
    {
        $ns = self::REST_NAMESPACE;

        // ------------------------------------------------------------ folders

        register_rest_route($ns, '/folders', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'tree'],
                'permission_callback' => [$this, 'canUseFolders'],
                'args' => [
                    // Deliberately no `default`. An absent parameter means
                    // "use the site's count setting", which is what the rail
                    // sends; a default here would answer for the setting
                    // before FolderService ever saw the question.
                    'counts' => [
                        'type' => 'string',
                        'required' => false,
                        'enum' => ['inherited', 'direct', 'none'],
                    ],
                    'object_type' => $this->objectTypeArgument(),
                ],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create'],
                'permission_callback' => [$this, 'canCreateFolders'],
                'args' => $this->folderArguments(true),
            ],
        ]);

        register_rest_route($ns, '/folders/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'show'],
                'permission_callback' => [$this, 'canUseFolders'],
            ],
            [
                // EDITABLE is POST, PUT and PATCH. The documented verb is
                // PATCH; the others are accepted so a client that cannot send
                // PATCH is not locked out.
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update'],
                'permission_callback' => [$this, 'canRenameFolders'],
                'args' => $this->folderArguments(false),
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete'],
                'permission_callback' => [$this, 'canDeleteFolders'],
                'args' => [
                    // Required, with no default. "What happens to my
                    // subfolders" is not a question to answer silently.
                    'children' => [
                        'type' => 'string',
                        'required' => true,
                        'enum' => [
                            FolderService::CHILDREN_REPARENT,
                            FolderService::CHILDREN_CASCADE,
                        ],
                    ],
                    'reassign_to' => [
                        'type' => 'integer',
                        'required' => false,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ]);

        register_rest_route($ns, '/folders/(?P<id>\d+)/move', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'move'],
            'permission_callback' => [$this, 'canRenameFolders'],
            'args' => [
                'parent_id' => [
                    'required' => true,
                    'sanitize_callback' => [$this, 'nullableInteger'],
                ],
            ],
        ]);

        /*
         * Paste a copy — tier 1 item 5, with Duplicate folder brought forward
         * from tier 2 to make it possible.
         *
         * Cut + paste needs no route of its own: it is /move, or /reorder when
         * the destination level is in Custom order. Copy + paste is a new
         * subtree, so it is here.
         */
        register_rest_route($ns, '/folders/(?P<id>\\d+)/duplicate', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'duplicate'],
            'permission_callback' => [$this, 'canDuplicateFolders'],
            'args' => [
                // Optional, and absent means the top level — the same
                // spelling /folders/reorder uses.
                'parent_id' => [
                    'required' => false,
                    'sanitize_callback' => [$this, 'nullableInteger'],
                ],
                'with_files' => [
                    'type' => 'boolean',
                    'required' => false,
                    'default' => false,
                ],
                // The destination level as the person sees it, with a 0 where
                // the copy goes. Only sent when that level is in Custom order.
                'order' => [
                    'type' => 'array',
                    'required' => false,
                    'items' => ['type' => 'integer'],
                ],
            ],
        ]);

        // Not `/folders/{id}/reorder`: the subject is the level, not one
        // folder, and the level is named by its parent — which is null at the
        // top. `\d+` cannot match "reorder", so this and the id routes above
        // do not compete however they are registered.
        register_rest_route($ns, '/folders/(?P<id>\\d+)/sort', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'sort'],
            // `rename` — the column headed Organise. A per-folder order is
            // written down and everyone sees it, so it is the folder's
            // property in the way its colour is, not the view the person
            // happens to be in. The global sort, which is that view, is
            // behind no ability at all.
            'permission_callback' => [$this, 'canRenameFolders'],
            'args' => [
                'scope' => [
                    'type' => 'string',
                    'required' => true,
                    'enum' => FolderSorts::SCOPES,
                ],
                // Required, and nullable: clearing an order is the thing this
                // route is asked to do most often after setting one, and
                // "follow the global sort" has to be expressible. Absent
                // would be indistinguishable from a client that forgot.
                'order' => [
                    'required' => true,
                ],
            ],
        ]);

        // Lock and pin — tier 2 item 10. Two routes because they are two
        // abilities: locking is `lock`; pinning is a shared change to the
        // order everyone sees, so `rename` (Organise), and on a locked folder
        // FolderService refuses it unless this person may lock.
        register_rest_route($ns, '/folders/(?P<id>\\d+)/lock', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'lock'],
            'permission_callback' => [$this, 'canLockFolders'],
            'args' => ['locked' => ['type' => 'boolean', 'required' => true]],
        ]);

        // Star — the person's own, so no ability beyond seeing folders at
        // all. One folder at a time; the list is the server's to change
        // (RailPreferences::star() says why).
        // What a folder's ZIP would hold — the rail asks before it starts a
        // download, so it can say the size above a threshold and refuse
        // above a limit without the browser ever opening the response.
        register_rest_route($ns, '/folders/(?P<id>\\d+)/zip', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'zip'],
            'permission_callback' => [$this, 'canDownloadFolders'],
        ]);

        register_rest_route($ns, '/folders/(?P<id>\\d+)/star', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'star'],
            'permission_callback' => [$this, 'canUseFolders'],
            'args' => ['starred' => ['type' => 'boolean', 'required' => true]],
        ]);

        // A folder's kind — tier 3 item 14. Organise, like pin and colour:
        // it is the site's, and it changes what everyone may file there.
        register_rest_route($ns, '/folders/(?P<id>\\d+)/kind', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'kind'],
            'permission_callback' => [$this, 'canRenameFolders'],
            'args' => [
                'kind' => ['type' => 'string', 'required' => true, 'enum' => FolderKinds::KINDS],
            ],
        ]);

        register_rest_route($ns, '/folders/(?P<id>\\d+)/pin', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'pin'],
            'permission_callback' => [$this, 'canRenameFolders'],
            'args' => ['pinned' => ['type' => 'boolean', 'required' => true]],
        ]);

        // Files placed in a folder — tier 2 item 8. The files and where they
        // go, never the whole folder: a 5,000-file folder is not 5,000 ids on
        // every drag. `rename` like /sort, because the arrangement is written
        // down and everyone sees it; `edit_post` on each file is asked by the
        // service, the way every assign asks it.
        register_rest_route($ns, '/folders/(?P<id>\\d+)/files/order', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'orderFiles'],
            'permission_callback' => [$this, 'canRenameFolders'],
            'args' => [
                'ids' => [
                    'type' => 'array',
                    'required' => true,
                    'items' => ['type' => 'integer'],
                ],
                'place' => [
                    'type' => 'string',
                    'required' => true,
                    'enum' => ['start', 'end', 'before', 'after'],
                ],
                'anchor' => [
                    'required' => false,
                    'sanitize_callback' => [$this, 'nullableInteger'],
                ],
            ],
        ]);

        register_rest_route($ns, '/folders/reorder', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'reorder'],
            'permission_callback' => [$this, 'canRenameFolders'],
            'args' => [
                // Optional, and absent means the top level. Required-with-null
                // would make "arrange the roots" unexpressible in a form post.
                'parent_id' => [
                    'required' => false,
                    'sanitize_callback' => [$this, 'nullableInteger'],
                ],
                'ids' => [
                    'type' => 'array',
                    'required' => true,
                    'items' => ['type' => 'integer'],
                ],
            ],
        ]);

        /*
         * Many folders from a list — one name per line, a slash nests.
         *
         * Two routes rather than one with a `dry_run` flag, following the
         * import wizard's /plan and /start next door: the preview is a
         * different question with a different answer, and a boolean that
         * decides whether a request writes is the kind of parameter that is
         * wrong exactly once.
         *
         * Both are POST. The plan writes nothing and would otherwise be a
         * GET, but its subject is a block of text a person pastes — which
         * belongs in a body, not in a query string, a server log and a
         * browser history.
         *
         * `\d+` cannot match "bulk", so neither competes with the id routes
         * above however they are registered — the same reason /folders/reorder
         * is spelled the way it is.
         */
        register_rest_route($ns, '/folders/bulk/plan', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'bulkPlan'],
            'permission_callback' => [$this, 'canCreateFolders'],
            'args' => $this->bulkArguments(),
        ]);

        register_rest_route($ns, '/folders/bulk', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'bulkCreate'],
            'permission_callback' => [$this, 'canCreateFolders'],
            'args' => $this->bulkArguments(),
        ]);

        register_rest_route($ns, '/folders/(?P<id>\d+)/ancestors', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'ancestors'],
            'permission_callback' => [$this, 'canUseFolders'],
        ]);

        register_rest_route($ns, '/folders/(?P<id>\d+)/attachments', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'attachments'],
            'permission_callback' => [$this, 'canUseFolders'],
            'args' => [
                'include_descendants' => [
                    'type' => 'boolean',
                    'required' => false,
                    'default' => false,
                ],
            ],
        ]);

        register_rest_route($ns, '/counts', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'counts'],
            'permission_callback' => [$this, 'canUseFolders'],
            'args' => ['object_type' => $this->objectTypeArgument()],
        ]);

        // -------------------------------------------------------- assignments

        register_rest_route($ns, '/assignments', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'assign'],
                'permission_callback' => [$this, 'canAssignFiles'],
                'args' => $this->assignmentArguments(true),
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'unassign'],
                'permission_callback' => [$this, 'canAssignFiles'],
                'args' => $this->assignmentArguments(false),
            ],
        ]);

        // Out of one folder and into another, every other folder the files
        // are in left alone — what a drag from a folder does. `mode: move` on
        // /assignments takes them out of *every* folder, which is not that.
        // `folder_id` is the destination, spelled as on /assignments, so the
        // tree it is asked for is the destination's (it was
        // /attachments/bulk-move until 24 Sep, with /attachments/assign,
        // /attachments/unassign and /tree beside it — aliases the rail still
        // called under a comment calling them unused).
        register_rest_route($ns, '/assignments/move', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'bulkMove'],
            'permission_callback' => [$this, 'canAssignFiles'],
            'args' => [
                'source_folder_id' => [
                    'type' => 'integer',
                    'required' => true,
                    'sanitize_callback' => 'absint',
                ],
                'folder_id' => [
                    'type' => 'integer',
                    'required' => true,
                    'sanitize_callback' => 'absint',
                ],
                'attachment_ids' => [
                    'type' => 'array',
                    'required' => true,
                    'items' => ['type' => 'integer'],
                ],
            ],
        ]);

        register_rest_route($ns, '/attachments/(?P<id>\d+)/folders', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'attachmentFolders'],
            'permission_callback' => [$this, 'canReadItemFolders'],
        ]);

        register_rest_route($ns, '/health', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'health'],
            'permission_callback' => [$this, 'canUseFolders'],
        ]);

    }

    public function canDownloadFolders(WP_REST_Request $request): bool
    {
        return FolderDownload::allowed() && $this->objectType($request) === PostTypes::MEDIA;
    }

    /**
     * Reading folders, and filing items into them.
     *
     * Every permission here asks about one object type (tier 3 item 12): the
     * folder's own when the route names one, and otherwise `object_type` —
     * absent is media, as before item 12. `upload_files` opens the media
     * tree; the Posts tree needs `edit_posts`.
     */
    public function canUseFolders(WP_REST_Request $request): bool
    {
        return Capabilities::canUseFolders($this->objectType($request));
    }

    /**
     * The folders one item is filed in. The id here is the item's — a file or,
     * since item 12, a post — so its type is the post's own, never a folder's.
     */
    public function canReadItemFolders(WP_REST_Request $request): bool
    {
        $type = get_post_type((int) $request['id']);

        return Capabilities::canUseFolders(is_string($type) ? $type : PostTypes::MEDIA);
    }

    /**
     * Whose folders a request is about.
     *
     * A route with a folder in it answers from the folder, so a request cannot
     * borrow a type it may use to act on a tree it may not: the route's own
     * folder, then the one a file goes into (`folder_id`), then the parent a
     * folder is made or arranged under, then the first folder of a level
     * being arranged. Only a request naming no folder at all — the tree, the
     * counts, a folder or a list made at the top — reads `object_type`. A
     * folder that does not exist falls through, and the handler says "not
     * found".
     */
    public function objectType(WP_REST_Request $request): string
    {
        $ids = $request->get_param('ids');
        $candidates = [
            $request->get_param('id'),
            $request->get_param('folder_id'),
            $request->get_param('parent_id'),
            is_array($ids) ? ($ids[0] ?? null) : null,
        ];

        foreach ($candidates as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $folder = $this->folders->get((int) $id);

                if ($folder !== null) {
                    return $folder->objectType;
                }
            }
        }

        return PostTypes::fromRequest($request->get_param('object_type'));
    }

    /**
     * The tree of the type a request was about — what every write returns.
     *
     * @return list<array<string, mixed>>
     */
    private function treeFor(WP_REST_Request $request): array
    {
        return $this->folders->tree($this->objectType($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function objectTypeArgument(): array
    {
        return [
            'type' => 'string',
            'required' => false,
            'pattern' => '^[a-z0-9_-]{1,20}$',
        ];
    }

    /**
     * One method per ability, because a permission_callback is a callable and
     * WordPress gives it only the request.
     *
     * They used to be one `canManageFolders`, which meant a site could not say
     * "Authors may create folders but not delete them" — the thing the roles
     * matrix on screen 08 exists to say.
     */
    public function canCreateFolders(WP_REST_Request $request): bool
    {
        return Capabilities::can('create', $this->objectType($request));
    }

    /**
     * Renaming, recolouring, and moving: all three edit a folder that already
     * exists, and the matrix has one column for that.
     */
    public function canRenameFolders(WP_REST_Request $request): bool
    {
        return Capabilities::can('rename', $this->objectType($request));
    }

    /**
     * Copy + paste makes folders (`create`) that are arrangements of an
     * existing one (`rename`, the column headed Organise) — both, because a
     * role holding one alone could otherwise do with a paste what it cannot
     * do directly. With files it also files media, which is `assign`; which
     * files is asked per attachment by FolderService, before anything is
     * written.
     */
    public function canDuplicateFolders(WP_REST_Request $request): bool
    {
        $type = $this->objectType($request);

        if (!Capabilities::can('create', $type) || !Capabilities::can('rename', $type)) {
            return false;
        }

        return !$this->withFiles($request) || Capabilities::can('assign', $type);
    }

    public function canDeleteFolders(WP_REST_Request $request): bool
    {
        return Capabilities::can('delete', $this->objectType($request));
    }

    public function canLockFolders(WP_REST_Request $request): bool
    {
        return Capabilities::can('lock', $this->objectType($request));
    }

    /**
     * Filing media into folders, and taking it out again.
     *
     * Which files may be filed is a separate question, asked per attachment by
     * FolderService through Capabilities::canEditAttachment().
     */
    public function canAssignFiles(WP_REST_Request $request): bool
    {
        return Capabilities::can('assign', $this->objectType($request));
    }

    public function tree(WP_REST_Request $request): WP_REST_Response
    {
        $mode = $request->get_param('counts');

        return $this->success($this->folders->tree(
            $this->objectType($request),
            is_string($mode) ? $mode : null
        ));
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->folders->create(
            $this->folderPayload($request) + ['object_type' => $this->objectType($request)]
        );

        return $this->result(
            $result,
            201,
            fn (Folder $folder): array => [
                'id' => $folder->id,
                'folder' => $folder->toArray(),
                'tree' => $this->folders->tree($folder->objectType),
            ]
        );
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->folders->update(
            (int) $request['id'],
            $this->folderPayload($request, false)
        );

        return $this->result(
            $result,
            200,
            fn (): array => ['tree' => $this->treeFor($request)]
        );
    }

    public function delete(WP_REST_Request $request): WP_REST_Response
    {
        $destination = $request->get_param('reassign_to');
        $children = (string) $request->get_param('children');
        // Asked now: once the folder is gone, nothing can say what it was.
        $type = $this->objectType($request);

        $result = $this->folders->delete(
            (int) $request['id'],
            $children,
            $destination === null || $destination === ''
                ? null
                : (int) $destination
        );

        return $this->result(
            $result,
            200,
            fn (): array => ['tree' => $this->folders->tree($type)]
        );
    }

    /**
     * How one folder shows what is inside it.
     *
     * Returns the whole tree like every other write here, because the client
     * applies a folder order to that folder's children itself — the order is
     * data on the node, not an instruction the server carries out.
     */
    public function sort(WP_REST_Request $request): WP_REST_Response
    {
        $order = $request->get_param('order');

        $result = $this->folders->sort(
            (int) $request['id'],
            (string) $request->get_param('scope'),
            // An empty string arrives from a form post that means "clear it";
            // JSON sends a real null. Both are the same intent.
            ($order === null || $order === '') ? null : (string) $order
        );

        return $this->result(
            $result,
            200,
            fn (): array => ['tree' => $this->treeFor($request)]
        );
    }

    public function lock(WP_REST_Request $request): WP_REST_Response
    {
        return $this->result(
            $this->folders->mark((int) $request['id'], FolderLocks::LOCKED, (bool) $request->get_param('locked')),
            200,
            fn (): array => ['tree' => $this->treeFor($request)]
        );
    }

    public function pin(WP_REST_Request $request): WP_REST_Response
    {
        return $this->result(
            $this->folders->mark((int) $request['id'], FolderLocks::PINNED, (bool) $request->get_param('pinned')),
            200,
            fn (): array => ['tree' => $this->treeFor($request)]
        );
    }

    public function kind(WP_REST_Request $request): WP_REST_Response
    {
        return $this->result(
            $this->folders->setKind((int) $request['id'], (string) $request->get_param('kind')),
            200,
            fn (): array => ['tree' => $this->treeFor($request)]
        );
    }

    public function zip(WP_REST_Request $request): WP_REST_Response
    {
        $manifest = (new FolderArchive())->manifest((int) $request['id']);

        if (null === $manifest) {
            return $this->result(
                new WP_Error('folderfolio_folder_not_found', __('Folder not found.', 'folderfolio')),
                404,
                static fn (): array => []
            );
        }

        $max = FolderDownload::maxBytes();
        $refused = $max > 0 && $manifest['bytes'] > $max;

        return $this->success([
            'name' => $manifest['folder']->name,
            'files' => $manifest['files'],
            'bytes' => $manifest['bytes'],
            'size' => size_format($manifest['bytes'], 1) ?: '0 B',
            'left_out' => $manifest['left_out'],
            'confirm' => $manifest['bytes'] >= FolderDownload::confirmBytes() && FolderDownload::confirmBytes() > 0,
            'refused' => $refused
                ? sprintf(
                    /* translators: 1: folder name, 2: a size such as "2 GB". */
                    __('“%1$s” is larger than this site allows in one download (%2$s). Download a subfolder at a time instead.', 'folderfolio'),
                    $manifest['folder']->name,
                    size_format($max)
                )
                : null,
            'url' => $refused ? null : FolderDownload::url((int) $request['id']),
        ]);
    }

    public function star(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request['id'];
        $on = (bool) $request->get_param('starred');

        // Unstarring a folder that has since gone is still allowed: it is how
        // a stale star leaves the list.
        $result = $on && $this->folders->get($id) === null
            ? new WP_Error('folderfolio_folder_not_found', __('Folder not found.', 'folderfolio'))
            : true;

        return $this->result(
            $result,
            200,
            fn (): array => ['stars' => RailPreferences::star(get_current_user_id(), $id, $on)]
        );
    }

    public function orderFiles(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->folders->moveFiles(
            (int) $request['id'],
            $this->integerList($request->get_param('ids')),
            (string) $request->get_param('place'),
            $this->nullableInteger($request->get_param('anchor'))
        );

        // The tree comes back because the folder's file sort may just have
        // become Custom, and the rail's Sort inside says so.
        return $this->result(
            $result,
            200,
            fn (): array => ['placed' => $result, 'tree' => $this->treeFor($request)]
        );
    }

    public function move(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->folders->move(
            (int) $request['id'],
            $this->nullableInteger($request->get_param('parent_id'))
        );

        return $this->result(
            $result,
            200,
            fn (): array => ['tree' => $this->treeFor($request)]
        );
    }

    public function duplicate(WP_REST_Request $request): WP_REST_Response
    {
        $order = $request->get_param('order');

        $result = $this->folders->duplicate(
            (int) $request['id'],
            $this->nullableInteger($request->get_param('parent_id')),
            $this->withFiles($request),
            is_array($order) ? $this->integerList($order) : null
        );

        return $this->result(
            $result,
            201,
            fn (Folder $copy): array => [
                'folder' => $copy->toArray(),
                'tree' => $this->folders->tree($copy->objectType),
            ]
        );
    }

    public function reorder(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->folders->reorder(
            $this->nullableInteger($request->get_param('parent_id')),
            $this->integerList($request->get_param('ids'))
        );

        return $this->result(
            $result,
            200,
            fn (int $arranged): array => [
                'arranged' => $arranged,
                'tree' => $this->treeFor($request),
            ]
        );
    }

    /**
     * What creating a pasted list would do. Writes nothing.
     */
    public function bulkPlan(WP_REST_Request $request): WP_REST_Response
    {
        return $this->result(
            (new FolderBulk())->plan(
                (string) $request->get_param('text'),
                $this->nullableInteger($request->get_param('parent_id')),
                $this->objectType($request)
            ),
            200,
            /** @param array<string, mixed> $plan */
            static fn (array $plan): array => $plan
        );
    }

    /**
     * Create the list, all of it or none of it.
     *
     * Deliberately does **not** return the tree, which every other write here
     * does. Those are called from the rail, which redraws from what comes
     * back; this is called from the Tools screen, which has no tree on it, and
     * a 1,053-folder payload nothing reads is not a courtesy.
     */
    public function bulkCreate(WP_REST_Request $request): WP_REST_Response
    {
        return $this->result(
            (new FolderBulk())->run(
                (string) $request->get_param('text'),
                $this->nullableInteger($request->get_param('parent_id')),
                $this->objectType($request)
            ),
            201,
            /** @param array<string, mixed> $plan */
            static fn (array $plan): array => $plan
        );
    }

    public function attachments(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request['id'];
        $deep = (bool) $request->get_param('include_descendants');

        return $this->success([
            'attachment_ids' => $this->folders->attachmentIds($id, $deep),
            'count' => $this->folders->countAttachments($id, $deep),
        ]);
    }

    public function assign(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->folders->assignAttachments(
            (int) $request['folder_id'],
            $this->integerList($request->get_param('attachment_ids')),
            (string) ($request->get_param('mode') ?? FolderService::MODE_ADD)
        );

        return $this->result(
            $result,
            200,
            fn (int $assigned): array => ['assigned' => $assigned]
        );
    }

    public function unassign(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->folders->unassignAttachments(
            $this->nullableInteger($request->get_param('folder_id')),
            $this->integerList($request->get_param('attachment_ids'))
        );

        return $this->result(
            $result,
            200,
            fn (int $removed): array => ['removed' => $removed]
        );
    }

    public function bulkMove(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->folders->moveAttachments(
            (int) $request['source_folder_id'],
            (int) $request['folder_id'],
            $this->integerList($request->get_param('attachment_ids'))
        );

        return $this->result(
            $result,
            200,
            fn (int $moved): array => ['moved' => $moved]
        );
    }


    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $folder = $this->folders->get((int) $request['id']);

        if ($folder === null) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'error' => [
                        'code' => 'folderfolio_folder_not_found',
                        'message' => __('Folder not found.', 'folderfolio'),
                    ],
                ],
                404
            );
        }

        return $this->success([
            'folder' => $folder->toArray(),
            'ancestors' => array_map(
                static fn (Folder $f): array => $f->toArray(),
                $this->folders->ancestors($folder->id)
            ),
            'count' => $this->folders->countAttachments($folder->id),
        ]);
    }

    public function ancestors(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success([
            'ancestors' => array_map(
                static fn (Folder $f): array => $f->toArray(),
                $this->folders->ancestors((int) $request['id'])
            ),
        ]);
    }

    public function counts(WP_REST_Request $request): WP_REST_Response
    {
        $counts = [];
        $type = $this->objectType($request);

        FolderTree::walk(
            $this->folders->tree($type),
            static function (array $node) use (&$counts): void {
                $counts[(int) $node['id']] = [
                    'count' => (int) ($node['count'] ?? 0),
                    'total_count' => (int) ($node['total_count'] ?? 0),
                ];
            }
        );

        return $this->success([
            'counts' => $counts,
            // The rail's All media and Unassigned rows. Here rather than in a
            // route of their own because they are read at the same moment, by
            // the same component, and a second round trip for two integers is
            // a second chance for one of them to be stale.
            'library' => (new AttachmentFolderRepository())->libraryCounts($type),
        ]);
    }

    public function attachmentFolders(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success([
            'folders' => array_map(
                static fn (Folder $f): array => $f->toArray(),
                $this->folders->foldersOf((int) $request['id'])
            ),
        ]);
    }

    public function health(): WP_REST_Response
    {
        return $this->success([
            'status' => 'ok',
            'version' => FOLDERFOLIO_VERSION,
            'wordpress' => get_bloginfo('version'),
            'php' => PHP_VERSION,
        ]);
    }

    public function nullableInteger(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : absint($value);
    }

    /**
     * `with_files` as the route declared it.
     *
     * The argument is typed `boolean`, and the REST server sanitises typed
     * arguments before it asks the permission callback — so by the time
     * either reader gets here, a form post's "1" or "true" is already `true`.
     * Comparing to `true` rather than casting means an absent value and any
     * value the schema did not turn into a boolean both read as "no files",
     * which is the answer that asks for the fewer abilities and writes less.
     */
    private function withFiles(WP_REST_Request $request): bool
    {
        return $request->get_param('with_files') === true;
    }

    /**
     * @param mixed $value
     * @return list<int>
     */
    private function integerList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map('intval', $value));
    }

    /**
     * @return array<string, RestArgument>
     */
    private function folderArguments(bool $creating): array
    {
        return [
            'name' => [
                'type' => 'string',
                'required' => $creating,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'parent_id' => [
                'required' => false,
                'sanitize_callback' => [$this, 'nullableInteger'],
            ],
            // A swatch name — 'steel', 'plum' — and not a hex. A hex is
            // still accepted and snapped to the nearest swatch, because 0.2.0
            // took one and anything written against it must keep working.
            //
            // No enum and no sanitize_callback, deliberately. An enum would
            // reject that legacy hex before FolderService ever sees it, and a
            // sanitize_callback that returned null for an unreadable value
            // would silently discard a field the caller set. FolderService
            // normalises, then validates, and returns a 400 naming the ten.
            'color' => [
                'type' => 'string',
                'required' => false,
            ],
            'icon' => [
                'type' => 'string',
                'required' => false,
                'sanitize_callback' => 'sanitize_key',
            ],
            'sort_order' => [
                'type' => 'integer',
                'required' => false,
                'sanitize_callback' => 'intval',
            ],
            // Whose tree a folder made at the top joins. Under a parent the
            // parent's type wins (`objectType()`), and a rename cannot change
            // it — the service drops it on update.
            'object_type' => $this->objectTypeArgument(),
        ];
    }

    /**
     * @return array<string, RestArgument>
     */
    private function assignmentArguments(bool $assigning): array
    {
        $args = [
            // Required when filing into a folder; optional when removing,
            // where omitting it means "take it out of every folder".
            'folder_id' => [
                'type' => 'integer',
                'required' => $assigning,
                'sanitize_callback' => 'absint',
            ],
            'attachment_ids' => [
                'type' => 'array',
                'required' => true,
                'items' => ['type' => 'integer'],
            ],
        ];

        if ($assigning) {
            // 'add' by default: filing a file somewhere should not quietly
            // take it out of wherever else its owner put it.
            $args['mode'] = [
                'type' => 'string',
                'required' => false,
                'default' => FolderService::MODE_ADD,
                'enum' => [FolderService::MODE_ADD, FolderService::MODE_MOVE],
            ];
        }

        return $args;
    }

    /**
     * @return FolderPayload
     */
    private function folderPayload(
        WP_REST_Request $request,
        bool $creating = true
    ): array {
        $payload = [];

        foreach (['name', 'parent_id', 'color', 'icon', 'sort_order'] as $key) {
            if ($creating || $request->has_param($key)) {
                $payload[$key] = $request->get_param($key);
            }
        }

        return $payload;
    }

    /**
     * The two bulk routes take the same pair, and take it the same way.
     *
     * `text` is the raw block, newlines and all, parsed on the server. The
     * browser could split it into an array and send that, but then half the
     * parse — which lines are blank, where a path divides — would live in the
     * client and the preview would be a second implementation free to
     * disagree with the one that writes.
     *
     * @return array<string, array<string, mixed>>
     */
    private function bulkArguments(): array
    {
        return [
            'text' => [
                'type' => 'string',
                'required' => true,
            ],
            // Absent means the top level, which is what the Tools screen
            // sends — it has no selection to inherit one from.
            'parent_id' => [
                'required' => false,
                'sanitize_callback' => [$this, 'nullableInteger'],
            ],
            'object_type' => $this->objectTypeArgument(),
        ];
    }

    /**
     * @param array<string, mixed>|int|bool|Folder|WP_Error $result
     * @param callable(array<string, mixed>|int|bool|Folder): array<string, mixed> $successData
     */
    private function result(
        array|int|bool|Folder|WP_Error $result,
        int $status,
        callable $successData
    ): WP_REST_Response {
        if (is_wp_error($result)) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'error' => [
                        'code' => $result->get_error_code(),
                        'message' => $result->get_error_message(),
                    ],
                ],
                400
            );
        }

        return $this->success($successData($result), $status);
    }

    private function success(mixed $data, int $status = 200): WP_REST_Response
    {
        return new WP_REST_Response(
            [
                'success' => true,
                'data' => $data,
            ],
            $status
        );
    }
}
