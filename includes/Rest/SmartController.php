<?php

declare(strict_types=1);

namespace FolderFolio\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Domain\SmartFolders;
use FolderFolio\Domain\SmartRules;
use FolderFolio\Support\Capabilities;
use FolderFolio\Support\PostTypes;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Smart folders over REST — tier 3 item 13.
 *
 * Reading them, and asking how many items a set of rules matches, is `use`
 * for their type: anyone who sees the tree sees the Smart group. Making,
 * changing and deleting one is Organise (`rename`) — Nick's 13b: they are the
 * site's, like folders, and arranging the site's views is what that column
 * is for. A route naming a smart folder answers for that smart folder's type.
 */
final class SmartController
{
    public function __construct(
        private readonly SmartFolders $smart = new SmartFolders()
    ) {
    }

    public function registerRoutes(): void
    {
        $ns = FolderController::REST_NAMESPACE;
        $type = ['type' => 'string', 'required' => false, 'pattern' => '^[a-z0-9_-]{1,20}$'];

        register_rest_route($ns, '/smart', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'index'],
                'permission_callback' => [$this, 'canRead'],
                'args' => ['object_type' => $type],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create'],
                'permission_callback' => [$this, 'canWrite'],
                'args' => [
                    'name' => ['type' => 'string', 'required' => true],
                    'rules' => ['type' => 'array', 'required' => true],
                    'object_type' => $type,
                ],
            ],
        ]);

        // What a set of rules would match, without saving it — the editor's
        // live count. POST, because the rules are a body, not a query string.
        register_rest_route($ns, '/smart/preview', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'preview'],
            'permission_callback' => [$this, 'canRead'],
            'args' => [
                'rules' => ['type' => 'array', 'required' => true],
                'object_type' => $type,
            ],
        ]);

        register_rest_route($ns, '/smart/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update'],
                'permission_callback' => [$this, 'canWrite'],
                'args' => [
                    'name' => ['type' => 'string', 'required' => false],
                    'rules' => ['type' => 'array', 'required' => false],
                ],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete'],
                'permission_callback' => [$this, 'canWrite'],
            ],
        ]);
    }

    /** The smart folder's own type when the route names one; else `object_type`. */
    private function objectType(WP_REST_Request $request): string
    {
        $id = (int) ($request['id'] ?? 0);
        $smart = $id > 0 ? $this->smart->get($id) : null;

        return $smart['object_type'] ?? PostTypes::fromRequest($request->get_param('object_type'));
    }

    public function canRead(WP_REST_Request $request): bool
    {
        return Capabilities::canUseFolders($this->objectType($request));
    }

    public function canWrite(WP_REST_Request $request): bool
    {
        return Capabilities::can('rename', $this->objectType($request));
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $type = $this->objectType($request);
        $items = [];

        foreach ($this->smart->all($type) as $item) {
            $items[] = $item + ['count' => $this->smart->count($item['rules'], $type)];
        }

        return $this->ok($items);
    }

    public function preview(WP_REST_Request $request): WP_REST_Response
    {
        $type = $this->objectType($request);
        $rules = SmartRules::sanitize($request->get_param('rules'), $type);

        return $this->ok([
            'rules' => $rules,
            'count' => $rules === [] ? null : $this->smart->count($rules, $type),
        ]);
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        return $this->result(
            $this->smart->create(
                (string) $request->get_param('name'),
                $this->objectType($request),
                $request->get_param('rules')
            ),
            201
        );
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $name = $request->get_param('name');

        return $this->result(
            $this->smart->update(
                (int) $request['id'],
                is_string($name) ? $name : null,
                $request->has_param('rules') ? $request->get_param('rules') : null
            ),
            200
        );
    }

    public function delete(WP_REST_Request $request): WP_REST_Response
    {
        $deleted = $this->smart->delete((int) $request['id']);

        return is_wp_error($deleted) ? $this->error($deleted) : $this->ok(['deleted' => true]);
    }

    /**
     * @param array<string, mixed>|WP_Error $result
     */
    private function result(array|WP_Error $result, int $status): WP_REST_Response
    {
        if (is_wp_error($result)) {
            return $this->error($result);
        }

        $type = (string) $result['object_type'];
        $rules = $result['rules'];

        /** @var list<array{field: string, op: string, value: int|string}> $rules */
        return $this->ok($result + ['count' => $this->smart->count($rules, $type)], $status);
    }

    private function error(WP_Error $error): WP_REST_Response
    {
        $data = $error->get_error_data();

        return new WP_REST_Response(
            [
                'success' => false,
                'error' => ['code' => $error->get_error_code(), 'message' => $error->get_error_message()],
            ],
            is_array($data) && isset($data['status']) ? (int) $data['status'] : 400
        );
    }

    private function ok(mixed $data, int $status = 200): WP_REST_Response
    {
        return new WP_REST_Response(['success' => true, 'data' => $data], $status);
    }
}
