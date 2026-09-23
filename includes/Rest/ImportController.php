<?php

declare(strict_types=1);

namespace FolderFolio\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Domain\FolderExport;
use FolderFolio\Modules\Import\Catalog;
use FolderFolio\Modules\Import\JsonSource;
use FolderFolio\Modules\Import\Run;
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
 * | `GET /export` | the other direction, on the same tab |
 *
 * Export lives here rather than with the folder routes because it is the
 * Import tab's other half and shares its permission: it reads the whole
 * structure of the library at once, which is a site-administration act in the
 * same way an import is, and not something the folder roles matrix has an
 * opinion about.
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
        register_rest_route(self::NS, '/export', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'export'],
            'permission_callback' => [$this, 'canManageOptions'],
            'args' => [
                // Off by default, and the reason is size: the tree is a few
                // hundred rows at worst, the file→folder map is one row per
                // filed attachment with no ceiling.
                'assignments' => [
                    'type' => 'boolean',
                    'required' => false,
                    'default' => false,
                ],
            ],
        ]);

        /*
         * An export file, read back in — tier 1 item 6b.
         *
         * The body is the decoded document, sent as JSON; the browser reads
         * the file, so there is no multipart upload and nothing lands in the
         * uploads directory. Validated whole and stored; the wizard then
         * previews and runs it under the key it answers with, through the same
         * /plan and /start as every plugin source.
         */
        register_rest_route(self::NS, '/import/file', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'file'],
                'permission_callback' => [$this, 'canManageOptions'],
                'args' => [
                    'document' => [
                        'required' => true,
                    ],
                ],
            ],
            // Letting go of it when the preview is cancelled — a finished run
            // lets go by itself (Runner::finish), and nothing else reads it.
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'discardFile'],
                'permission_callback' => [$this, 'canManageOptions'],
            ],
        ]);

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

    /**
     * The whole folder structure, as a document.
     *
     * Returned as the document itself rather than wrapped in the
     * `{success, data}` envelope the write routes use. What the client does
     * with this is save it to disk under a name a person will recognise
     * later, and a reader opening that file should find the structure at the
     * top level rather than one key down inside a transport detail.
     *
     * The filename travels in a header so the client does not have to build
     * the same string from the same two facts and get it subtly different.
     */
    public function export(WP_REST_Request $request): WP_REST_Response
    {
        $document = (new FolderExport())->document(
            (bool) $request->get_param('assignments')
        );

        $response = new WP_REST_Response($document, 200);
        $response->header('X-FolderFolio-Filename', FolderExport::filename());

        return $response;
    }

    public function file(WP_REST_Request $request): WP_REST_Response
    {
        // The stored document is what a running import re-reads every batch;
        // replacing it mid-run would change the tree under the cursor.
        $current = $this->runs->current();

        if (null !== $current && !$current->isFinished()) {
            return $this->fail(
                __('An import is already running. Wait for it to finish, or stop it first.', 'folderfolio'),
                409
            );
        }

        $document = $request->get_param('document');
        $source = JsonSource::fromDocument($document);

        if ($source instanceof WP_Error) {
            return $this->fail($source->get_error_message(), 400);
        }

        /** @var array<string, mixed> $document */
        JsonSource::store($document);

        return $this->ok([
            'source' => [
                'key' => $source->key(),
                'label' => $source->label(),
                'folders' => $source->folderCount(),
                'assignments' => $source->assignmentCount(),
            ] + $source->facts(),
        ]);
    }

    public function discardFile(): WP_REST_Response
    {
        $current = $this->runs->current();

        if (null !== $current && !$current->isFinished()) {
            return $this->fail(
                __('An import is already running. Wait for it to finish, or stop it first.', 'folderfolio'),
                409
            );
        }

        delete_option(JsonSource::OPTION);

        return $this->ok([]);
    }

    public function sources(): WP_REST_Response
    {
        $run = $this->runs->current();

        return $this->ok([
            'sources' => Catalog::detect(),
            'run' => $this->runPayload($run),
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

        $plan = $this->planner->plan($source)->toArray();

        // What only a file can say: where it came from, and whether its file
        // assignments apply here. The preview draws a line from it.
        if ($source instanceof JsonSource) {
            $plan['file'] = $source->facts();
        }

        return $this->ok(['plan' => $plan]);
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
        return $this->ok(['run' => $this->runPayload($result)]);
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

    /**
     * A run, plus one fact about the site as it is right now.
     *
     * `source_plugin_active` is not in `Run::toArray()` on purpose: a run is a
     * stored record, that method is also what writes it to the database, and a
     * plugin being switched on is not a property of something that happened
     * last Tuesday. Freezing it there would mean the wizard telling somebody
     * they can deactivate a plugin they turned off a week ago.
     *
     * It is what the report's last line is gated on — *"its folders are here
     * now, so you can turn it off"* is only worth saying while it is on.
     *
     * @return array<string, mixed>|null
     */
    private function runPayload(?Run $run): ?array
    {
        if (null === $run) {
            return null;
        }

        $payload = $run->toArray();

        $payload['source_plugin_active'] = Catalog::find($run->sourceKey)?->isPluginActive() ?? false;

        return $payload;
    }
}
