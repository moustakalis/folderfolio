<?php

declare(strict_types=1);

namespace FolderFolio\Rest;

use FolderFolio\Domain\FolderService;
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
    public function __construct(
        private readonly FolderService $folders = new FolderService()
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route('folderfolio/v1', '/tree', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'tree'],
            'permission_callback' => [$this, 'canManageMedia'],
        ]);

        register_rest_route('folderfolio/v1', '/folders', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create'],
            'permission_callback' => [$this, 'canManageMedia'],
            'args' => $this->folderArguments(true),
        ]);

        register_rest_route('folderfolio/v1', '/folders/(?P<id>\d+)', [
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => [$this, 'update'],
            'permission_callback' => [$this, 'canManageMedia'],
            'args' => $this->folderArguments(false),
        ]);

        register_rest_route('folderfolio/v1', '/folders/(?P<id>\d+)', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => [$this, 'delete'],
            'permission_callback' => [$this, 'canManageMedia'],
            'args' => [
                'reassign_to' => [
                    'type' => 'integer',
                    'required' => false,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route('folderfolio/v1', '/folders/(?P<id>\d+)/move', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'move'],
            'permission_callback' => [$this, 'canManageMedia'],
            'args' => [
                'parent_id' => [
                    'required' => true,
                    'sanitize_callback' => [$this, 'nullableInteger'],
                ],
            ],
        ]);

        register_rest_route('folderfolio/v1', '/folders/(?P<id>\d+)/attachments', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'attachments'],
            'permission_callback' => [$this, 'canManageMedia'],
        ]);

        register_rest_route('folderfolio/v1', '/attachments/assign', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'assign'],
            'permission_callback' => [$this, 'canManageMedia'],
            'args' => $this->attachmentArguments('folder_id'),
        ]);

        register_rest_route('folderfolio/v1', '/attachments/unassign', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'unassign'],
            'permission_callback' => [$this, 'canManageMedia'],
            'args' => $this->attachmentArguments('folder_id'),
        ]);

        register_rest_route('folderfolio/v1', '/attachments/bulk-move', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'bulkMove'],
            'permission_callback' => [$this, 'canManageMedia'],
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

        register_rest_route('folderfolio/v1', '/health', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'health'],
            'permission_callback' => [$this, 'canManageMedia'],
        ]);
    }

    public function canManageMedia(): bool
    {
        return current_user_can('upload_files');
    }

    public function tree(): WP_REST_Response
    {
        return $this->success($this->folders->tree());
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->folders->create($this->folderPayload($request));

        return $this->result(
            $result,
            201,
            fn (int $id): array => [
                'id' => $id,
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

        $result = $this->folders->delete(
            (int) $request['id'],
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
        return $this->success([
            'attachment_ids' => $this->folders->attachmentIds((int) $request['id']),
        ]);
    }

    public function assign(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->folders->assignAttachments(
            (int) $request['folder_id'],
            $this->integerList($request->get_param('attachment_ids'))
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
            (int) $request['folder_id'],
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
            'color' => [
                'type' => 'string',
                'required' => false,
                'sanitize_callback' => 'sanitize_hex_color',
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
    private function attachmentArguments(string $folderKey): array
    {
        return [
            $folderKey => [
                'type' => 'integer',
                'required' => true,
                'sanitize_callback' => 'absint',
            ],
            'attachment_ids' => [
                'type' => 'array',
                'required' => true,
                'items' => ['type' => 'integer'],
            ],
        ];
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
     * @param int|bool|WP_Error $result
     * @param callable(int|bool): array<string, mixed> $successData
     */
    private function result(
        int|bool|WP_Error $result,
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
