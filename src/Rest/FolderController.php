<?php

declare(strict_types=1);

namespace FolderFolio\Rest;

use FolderFolio\Domain\FolderService;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class FolderController
{
    public function __construct(private readonly FolderService $folders = new FolderService())
    {
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
                'reassign_to' => ['type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint'],
            ],
        ]);

        register_rest_route('folderfolio/v1', '/folders/(?P<id>\d+)/move', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'move'],
            'permission_callback' => [$this, 'canManageMedia'],
            'args' => [
                'parent_id' => ['required' => true, 'sanitize_callback' => [$this, 'nullableInteger']],
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
                'source_folder_id' => ['type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint'],
                'destination_folder_id' => ['type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint'],
                'attachment_ids' => ['type' => 'array', 'required' => true, 'items' => ['type' => 'integer']],
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
        return $this->result($result, 201, ['id' => $result, 'tree' => $this->folders->tree()]);
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->folders->update((int) $request['id'], $this->folderPayload($request, false));
        return $this->result($result, 200, ['tree' => $this->folders->tree()]);
    }

    public function delete(WP_REST_Request $request): WP_REST_Response
    {
        $destination = $request->get_param('reassign_to');
        $result = $this->folders->delete((int) $request['id'], $destination ? (int) $destination : null);
        return $this->result($result, 200, ['tree' => $this->folders->tree()]);
    }

    public function move(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->folders->move((int) $request['id'], $request->get_param('parent_id'));
        return $this->result($result, 200, ['tree' => $this->folders->tree()]);
    }

    public function attachments(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success(['attachment_ids' => $this->folders->attachmentIds((int) $request['id'])]);
    }

    public function assign(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->folders->assignAttachments((int) $request['folder_id'], $request->get_param('attachment_ids'));
        return $this->result($result, 200, ['assigned' => $result]);
    }

    public function unassign(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->folders->unassignAttachments((int) $request['folder_id'], $request->get_param('attachment_ids'));
        return $this->result($result, 200, ['removed' => $result]);
    }

    public function bulkMove(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->folders->moveAttachments(
            (int) $request['source_folder_id'],
            (int) $request['destination_folder_id'],
            $request->get_param('attachment_ids')
        );
        return $this->result($result, 200, ['moved' => $result]);
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

    private function folderArguments(bool $creating): array
    {
        return [
            'name' => ['type' => 'string', 'required' => $creating, 'sanitize_callback' => 'sanitize_text_field'],
            'parent_id' => ['required' => false, 'sanitize_callback' => [$this, 'nullableInteger']],
            'color' => ['type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_hex_color'],
            'icon' => ['type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_key'],
            'sort_order' => ['type' => 'integer', 'required' => false, 'sanitize_callback' => 'intval'],
        ];
    }

    private function attachmentArguments(string $folderKey): array
    {
        return [
            $folderKey => ['type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint'],
            'attachment_ids' => ['type' => 'array', 'required' => true, 'items' => ['type' => 'integer']],
        ];
    }

    private function folderPayload(WP_REST_Request $request, bool $creating = true): array
    {
        $payload = [];
        foreach (['name', 'parent_id', 'color', 'icon', 'sort_order'] as $key) {
            if ($creating || $request->has_param($key)) {
                $payload[$key] = $request->get_param($key);
            }
        }
        return $payload;
    }

    private function result(mixed $result, int $status, array $data): WP_REST_Response
    {
        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => [
                    'code' => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                ],
            ], 400);
        }
        return $this->success($data, $status);
    }

    private function success(mixed $data, int $status = 200): WP_REST_Response
    {
        return new WP_REST_Response(['success' => true, 'data' => $data], $status);
    }
}
