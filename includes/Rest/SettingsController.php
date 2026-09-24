<?php

declare(strict_types=1);

namespace FolderFolio\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Database\Schema;
use FolderFolio\Domain\AttachmentFolderRepository;
use FolderFolio\Support\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The site settings and the two repair tools, over REST — the settings
 * screen's two tabs for a script (24 Sep, alignment audit items A and C).
 *
 * `manage_options`, as the screen asks. The values go through
 * `Settings::change()`, which is `Settings::sanitize()` — the form's own —
 * with a refusal where the form's sanitiser would quietly substitute a value.
 */
final class SettingsController
{
    public const REST_NAMESPACE = FolderController::REST_NAMESPACE;

    /** The Status tab's tools, by the names its form posts. */
    public const TOOLS = ['rebuild-paths', 'remove-orphans'];

    public function registerRoutes(): void
    {
        register_rest_route(self::REST_NAMESPACE, '/settings', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'read'],
                'permission_callback' => [$this, 'canManageOptions'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'write'],
                'permission_callback' => [$this, 'canManageOptions'],
            ],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/repair', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'repair'],
            'permission_callback' => [$this, 'canManageOptions'],
            'args' => [
                'tool' => ['type' => 'string', 'required' => true, 'enum' => self::TOOLS],
            ],
        ]);
    }

    public function canManageOptions(): bool
    {
        return current_user_can('manage_options');
    }

    public function read(): WP_REST_Response
    {
        return $this->ok(['settings' => Settings::get()]);
    }

    /**
     * The keys sent, and nothing else — a key left out keeps its value.
     */
    public function write(WP_REST_Request $request): WP_REST_Response
    {
        $changes = $request->get_json_params();

        if (!is_array($changes) || [] === $changes) {
            $changes = $request->get_body_params();
        }

        if (!is_array($changes) || [] === $changes) {
            return $this->fail(new WP_Error(
                'folderfolio_setting_none',
                __('Send the settings to change, such as {"undo_window": 10}.', 'folderfolio'),
                ['status' => 400]
            ));
        }

        $saved = Settings::change($changes);

        if ($saved instanceof WP_Error) {
            return $this->fail($saved);
        }

        // What a filter makes of them, as `GET` would say — the same answer
        // the next request gets.
        return $this->ok(['settings' => Settings::get()]);
    }

    public function repair(WP_REST_Request $request): WP_REST_Response
    {
        $tool = (string) $request->get_param('tool');

        $fixed = 'rebuild-paths' === $tool
            ? (new Schema())->backfillPaths(true)
            : (new AttachmentFolderRepository())->deleteOrphans();

        return $this->ok(['tool' => $tool, 'fixed' => $fixed]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function ok(array $data): WP_REST_Response
    {
        return new WP_REST_Response(['success' => true, 'data' => $data]);
    }

    private function fail(WP_Error $error): WP_REST_Response
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
}
