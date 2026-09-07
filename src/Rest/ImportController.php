<?php

declare(strict_types=1);

namespace FolderFolio\Rest;

use FolderFolio\Modules\Importers\ImporterFactory;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

/**
 * REST controller for import operations.
 */
class ImportController
{
    private ImporterFactory $factory;

    public function __construct()
    {
        $this->factory = new ImporterFactory();
    }

    public function registerRoutes(): void
    {
        register_rest_route('folderfolio/v1', '/import/detect', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'detect'],
            'permission_callback' => [$this, 'canManageOptions'],
        ]);

        register_rest_route('folderfolio/v1', '/import/(?P<importer>[a-z-]+)', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'import'],
            'permission_callback' => [$this, 'canManageOptions'],
            'args' => [
                'importer' => [
                    'type' => 'string',
                    'description' => 'Importer key (e.g., "filebird")',
                    'required' => true,
                ],
            ],
        ]);
    }

    public function canManageOptions(): bool
    {
        return current_user_can('manage_options');
    }

    public function detect(): WP_REST_Response
    {
        $importers = $this->factory->getAvailableImporters();
        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'importers' => $importers,
                'installed' => $this->factory->detectInstalled(),
            ],
        ]);
    }

    public function import(WP_REST_Request $request): WP_REST_Response
    {
        $importerKey = $request->get_param('importer');

        try {
            $importer = $this->factory->create($importerKey);
        } catch (\InvalidArgumentException $e) {
            return new WP_REST_Response([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }

        if (!$importer->isInstalled()) {
            return new WP_REST_Response([
                'success' => false,
                'error' => "{$importer->getName()} is not installed.",
            ], 400);
        }

        $result = $importer->import();

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => $result->get_error_message(),
            ], 500);
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $result,
        ], 200);
    }
}
