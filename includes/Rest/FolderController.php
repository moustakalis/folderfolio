<?php

declare(strict_types=1);

namespace FolderFolio\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Domain\Folder;
use FolderFolio\Domain\FolderRepository;
use FolderFolio\Domain\FolderService;
use FolderFolio\Domain\FolderTree;
use FolderFolio\Support\Capabilities;
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
                    'counts' => [
                        'type' => 'string',
                        'required' => false,
                        'default' => 'inherited',
                        'enum' => ['inherited', 'direct', 'none'],
                    ],
                ],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create'],
                'permission_callback' => [$this, 'canManageFolders'],
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
                'permission_callback' => [$this, 'canManageFolders'],
                'args' => $this->folderArguments(false),
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete'],
                'permission_callback' => [$this, 'canManageFolders'],
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
            'permission_callback' => [$this, 'canManageFolders'],
            'args' => [
                'parent_id' => [
                    'required' => true,
                    'sanitize_callback' => [$this, 'nullableInteger'],
                ],
            ],
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
        ]);

        // -------------------------------------------------------- assignments

        register_rest_route($ns, '/assignments', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'assign'],
                'permission_callback' => [$this, 'canUseFolders'],
                'args' => $this->assignmentArguments(true),
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'unassign'],
                'permission_callback' => [$this, 'canUseFolders'],
                'args' => $this->assignmentArguments(false),
            ],
        ]);

        register_rest_route($ns, '/attachments/(?P<id>\d+)/folders', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'attachmentFolders'],
            'permission_callback' => [$this, 'canUseFolders'],
        ]);

        register_rest_route($ns, '/health', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'health'],
            'permission_callback' => [$this, 'canUseFolders'],
        ]);

        $this->registerLegacyRoutes($ns);
    }

    /**
     * Routes the shipped JavaScript still calls.
     *
     * Undocumented and unsupported: they exist only so the current admin
     * bundles keep working until the React app replaces them, and they are
     * removed in the same change that lands it. Nothing outside this plugin
     * should call them — docs/api/README.md lists the supported surface.
     *
     * @deprecated 1.0.0
     */
    private function registerLegacyRoutes(string $ns): void
    {
        register_rest_route($ns, '/tree', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'tree'],
            'permission_callback' => [$this, 'canUseFolders'],
        ]);

        register_rest_route($ns, '/attachments/assign', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'assign'],
            'permission_callback' => [$this, 'canUseFolders'],
            'args' => $this->assignmentArguments(true),
        ]);

        register_rest_route($ns, '/attachments/unassign', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'unassign'],
            'permission_callback' => [$this, 'canUseFolders'],
            'args' => $this->assignmentArguments(false),
        ]);

        register_rest_route($ns, '/attachments/bulk-move', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'bulkMove'],
            'permission_callback' => [$this, 'canUseFolders'],
            'args' => [
                'source_folder_id' => [
                    'type' => 'integer',
                    'required' => true,
                    'sanitize_callback' => 'absint',
                ],
                'destination_folder_id' => [
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
    }

    /**
     * Reading folders, and filing media into them.
     */
    public function canUseFolders(): bool
    {
        return Capabilities::canUseFolders();
    }

    /**
     * Changing the folder structure itself.
     */
    public function canManageFolders(): bool
    {
        return Capabilities::canManageFolders();
    }

    public function tree(WP_REST_Request $request): WP_REST_Response
    {
        $mode = (string) ($request->get_param('counts') ?? 'inherited');

        return $this->success($this->folders->tree(
            FolderRepository::DEFAULT_OBJECT_TYPE,
            $mode
        ));
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->folders->create($this->folderPayload($request));

        return $this->result(
            $result,
            201,
            fn (Folder $folder): array => [
                'id' => $folder->id,
                'folder' => $folder->toArray(),
                'tree' => $this->folders->tree(),
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
            fn (): array => ['tree' => $this->folders->tree()]
        );
    }

    public function delete(WP_REST_Request $request): WP_REST_Response
    {
        $destination = $request->get_param('reassign_to');
        $children = (string) $request->get_param('children');

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
            fn (): array => ['tree' => $this->folders->tree()]
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
            fn (): array => ['tree' => $this->folders->tree()]
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
            (int) $request['destination_folder_id'],
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

    public function counts(): WP_REST_Response
    {
        $counts = [];

        FolderTree::walk(
            $this->folders->tree(),
            static function (array $node) use (&$counts): void {
                $counts[(int) $node['id']] = [
                    'count' => (int) ($node['count'] ?? 0),
                    'total_count' => (int) ($node['total_count'] ?? 0),
                ];
            }
        );

        return $this->success(['counts' => $counts]);
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
            // No sanitize_callback: sanitize_hex_color() returns null for an
            // invalid value, which would silently discard a field the caller
            // set. FolderService validates it and returns a 400 instead.
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
     * @param callable(int|bool|Folder): array<string, mixed> $successData
     */
    private function result(
        int|bool|Folder|WP_Error $result,
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
