<?php

declare(strict_types=1);

namespace FolderFolio\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Admin\RailPreferences;
use FolderFolio\Support\Capabilities;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Per-user interface preferences.
 *
 * Small, and separate from FolderController on purpose: nothing here touches a
 * folder. These are settings about the current user's own screen, they are
 * only ever read and written for whoever is making the request, and there is
 * no id in any route — which is also why the permission check is the read
 * capability rather than the manage one. Being able to narrow your own sidebar
 * is not an editorial privilege.
 */
final class PreferenceController
{
    public const REST_NAMESPACE = FolderController::REST_NAMESPACE;

    public function registerRoutes(): void
    {
        register_rest_route(self::REST_NAMESPACE, '/preferences', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'read'],
                'permission_callback' => [$this, 'canUseFolders'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'write'],
                'permission_callback' => [$this, 'canUseFolders'],
                'args' => [
                    'rail' => [
                        'type' => 'object',
                        'required' => false,
                        // Validated by RailPreferences::sanitize() rather than
                        // by a schema: the clamp and the default belong with
                        // the constants that define them, and a schema here
                        // would be a second place to keep the bounds.
                        'properties' => [
                            'open' => ['type' => 'boolean'],
                            'width' => ['type' => 'integer'],
                            // Nullable, and the null is the point: it is how
                            // the toggle says "this is no longer my startup
                            // folder". write() merges onto what is stored, so
                            // a key left out changes nothing and a key sent
                            // null clears it.
                            'startup' => ['type' => ['integer', 'null']],
                            // Stars are sent whole, the list after the click:
                            // write() merges keys, not lists, so this is how
                            // one is taken away.
                            'stars' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function canUseFolders(): bool
    {
        // A logged-out request has no user meta to read or write, and
        // get_current_user_id() would be 0 — which is not "everyone", it is
        // nobody, and writing there would silently discard the value.
        return is_user_logged_in() && Capabilities::canUseFolders();
    }

    public function read(): WP_REST_Response
    {
        return $this->success([
            'rail' => RailPreferences::forUser(get_current_user_id()),
        ]);
    }

    public function write(WP_REST_Request $request): WP_REST_Response
    {
        $rail = $request->get_param('rail');

        if (!is_array($rail)) {
            $rail = [];
        }

        /** @var array<string, mixed> $rail */
        // Merged over what is already stored, so a client that sends only a
        // width does not silently collapse the rail by omitting `open`.
        $merged = array_merge(RailPreferences::forUser(get_current_user_id()), $rail);

        return $this->success([
            'rail' => RailPreferences::save(get_current_user_id(), $merged),
        ]);
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
