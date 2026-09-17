<?php

declare(strict_types=1);

namespace FolderFolio\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Modules\Import\Catalog;
use FolderFolio\Modules\Import\Planner;
use FolderFolio\Modules\Import\Runner;
use FolderFolio\Modules\Import\RunStore;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The import wizard's four steps, as five routes.
 *
 * | Route | Screen 07 |
 * |---|---|
 * | `GET /import/sources` | step 1 — what has data, and what is still running |
 * | `GET /import/{source}/plan` | step 2 — the preview. Writes nothing |
 * | `POST /import/{source}/start` | begins a run |
 * | `POST /import/run` | one batch. Called until the run reports it is done |
 * | `POST /import/stop`, `POST /import/undo` | step 3's button, and step 4's |
 *
 * The preview being a **GET** is deliberate: it is a read, it is safe to
 * refresh, and a route that previews under POST invites the assumption that
 * previewing costs something.
 *
 * Everything here is `manage_options`, not `upload_files` and not the folder
 * roles matrix. An import rewrites the shape of the whole media library from
 * another plugin's data; that is a site-administration act, and it is the one
 * thing CatFolders' controller gets unambiguously right.
 */
class ImportController
{
    private const NS = 'folderfolio/v1';

    /**
     * Source keys are ours, from the catalogue, and this pattern is what stops
     * anything else reaching `Catalog::find()`.
     */
    private const KEY = '(?P<source>[a-z0-9-]+)';

    public function __construct(
        private readonly Planner $planner = new Planner(),
        private readonly Runner $runner = new Runner(),
        private readonly RunStore $runs = new RunStore()
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NS, '/import/sources', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'sources'],
            'permission_callback' => [$this, 'canManageOptions'],
        ]);

        register_rest_route(self::NS, '/import/' . self::KEY . '/plan', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'plan'],
            'permission_callback' => [$this, 'canManageOptions'],
        ]);

        register_rest_route(self::NS, '/import/' . self::KEY . '/start', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'start'],
            'permission_callback' => [$this, 'canManageOptions'],
        ]);

        register_rest_route(self::NS, '/import/run', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'run'],
            'permission_callback' => [$this, 'canManageOptions'],
        ]);

        register_rest_route(self::NS, '/import/stop', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'stop'],
            'permission_callback' => [$this, 'canManageOptions'],
        ]);

        register_rest_route(self::NS, '/import/undo', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'undo'],
            'permission_callback' => [$this, 'canManageOptions'],
        ]);

        // v0.2.0 shipped `POST /import/{key}`, which detected, planned and
        // wrote in one unreviewable call. It is gone rather than deprecated:
        // there is no version of "import everything now, no preview" this
        // plugin wants to keep answering.
    }

    public function canManageOptions(): bool
    {
        return current_user_can('manage_options');
    }

    public function sources(): WP_REST_Response
    {
        $run = $this->runs->current();

        return $this->ok([
            'sources' => Catalog::detect(),
            'run' => null === $run ? null : $run->toArray(),
        ]);
    }

    public function plan(WP_REST_Request $request): WP_REST_Response
    {
        $source = Catalog::find((string) $request->get_param('source'));

        if (null === $source) {
            return $this->fail(__('That plugin is not one FolderFolio can import from.', 'folderfolio'), 404);
        }

        if (!$source->hasData()) {
            return $this->fail(
                sprintf(
                    /* translators: %s is a plugin name, e.g. FileBird. */
                    __('There is no %s data on this site to import.', 'folderfolio'),
                    $source->label()
                ),
                404
            );
        }

        return $this->ok(['plan' => $this->planner->plan($source)->toArray()]);
    }

    public function start(WP_REST_Request $request): WP_REST_Response
    {
        $source = Catalog::find((string) $request->get_param('source'));

        if (null === $source) {
            return $this->fail(__('That plugin is not one FolderFolio can import from.', 'folderfolio'), 404);
        }

        return $this->fromRun($this->runner->start($source));
    }

    public function run(): WP_REST_Response
    {
        return $this->fromRun($this->runner->step());
    }

    public function stop(): WP_REST_Response
    {
        return $this->fromRun($this->runner->stop());
    }

    public function undo(): WP_REST_Response
    {
        return $this->fromRun($this->runner->undo());
    }

    private function fromRun(mixed $result): WP_REST_Response
    {
        if ($result instanceof WP_Error) {
            $data = $result->get_error_data();
            $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 400;

            return $this->fail($result->get_error_message(), $status);
        }

        /** @var \FolderFolio\Modules\Import\Run $result */
        return $this->ok(['run' => $result->toArray()]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function ok(array $data): WP_REST_Response
    {
        return new WP_REST_Response(['success' => true, 'data' => $data]);
    }

    private function fail(string $message, int $status): WP_REST_Response
    {
        return new WP_REST_Response(['success' => false, 'error' => $message], $status);
    }
}
