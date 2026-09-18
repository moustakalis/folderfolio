<?php

namespace FolderFolio\Tests\Integration\Support;

use FolderFolio\Database\Schema;
use FolderFolio\Domain\FolderService;
use FolderFolio\Support\UploadRouter;
use FolderFolio\Support\UploadTarget;
use WP_UnitTestCase;

/**
 * An upload goes where the request said, and nowhere else.
 *
 * The unit test covers the parsing. This covers the half that only exists
 * once WordPress is running: that `add_attachment` fires, that the filter
 * chain hands the id along, that the assignment is actually written, and —
 * the part worth having a test for at all — that a site's own callback on
 * `folderfolio_default_folder_for_upload` still wins, because the whole
 * reason ours runs at priority 5 is to be overridable.
 *
 * The parameter is put in `$_REQUEST` directly rather than by uploading a
 * file: what is being tested is the wiring from a named folder to a written
 * row, and a real multipart upload would test WordPress's uploader instead.
 * The browser end of it was verified against a live WordPress on 18 Sep, in
 * both the media grid and the picker modal.
 */
class UploadTargetTest extends WP_UnitTestCase
{
    private FolderService $folders;

    public function setUp(): void
    {
        parent::setUp();

        // The plugin's tables are not created by the test bootstrap, and
        // WP_UnitTestCase rolls its transaction back after every test. Without
        // this the folder table does not exist, create() fails, and — worse —
        // the "files nothing" cases pass for the wrong reason, because nothing
        // is written when there is nowhere to write it.
        (new Schema())->migrate();

        $this->folders = new FolderService();

        (new UploadRouter())->register();
        (new UploadTarget())->register();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function tearDown(): void
    {
        unset($_REQUEST[UploadTarget::PARAM]);

        remove_all_filters('folderfolio_default_folder_for_upload');
        remove_all_actions('add_attachment');

        parent::tearDown();
    }

    private function newFolder(string $name): int
    {
        $folder = $this->folders->create(['name' => $name]);

        self::assertNotWPError($folder);

        return $folder->id;
    }

    private function upload(): int
    {
        return self::factory()->attachment->create_object([
            'file' => 'probe.png',
            'post_mime_type' => 'image/png',
        ]);
    }

    /** @return list<int> */
    private function folderIdsFor(int $attachmentId): array
    {
        return array_map(
            static fn ($folder): int => $folder->id,
            $this->folders->foldersOf($attachmentId)
        );
    }

    public function test_an_upload_is_filed_into_the_folder_the_request_names(): void
    {
        $folderId = $this->newFolder('Logos');
        $_REQUEST[UploadTarget::PARAM] = (string) $folderId;

        $attachmentId = $this->upload();

        self::assertSame([$folderId], $this->folderIdsFor($attachmentId));
    }

    public function test_an_upload_that_names_no_folder_is_filed_nowhere(): void
    {
        $this->newFolder('Logos');

        $attachmentId = $this->upload();

        self::assertSame([], $this->folderIdsFor($attachmentId));
    }

    /**
     * Unassigned is a place you can be looking at, and an upload made while
     * looking at it is the one upload that should stay unfiled.
     */
    public function test_zero_is_unassigned_and_files_nothing(): void
    {
        $this->newFolder('Logos');
        $_REQUEST[UploadTarget::PARAM] = '0';

        self::assertSame([], $this->folderIdsFor($this->upload()));
    }

    /**
     * The failure this is written against: FileBird and CatFolders both take
     * a path on this parameter and create the folders in it. A request must
     * not be able to write a folder.
     */
    public function test_a_path_creates_nothing_and_files_nothing(): void
    {
        $before = count($this->folders->tree());

        $_REQUEST[UploadTarget::PARAM] = '1/Brand/Logos';
        $attachmentId = $this->upload();

        self::assertSame([], $this->folderIdsFor($attachmentId));
        self::assertCount($before, $this->folders->tree(), 'An upload parameter created a folder.');
    }

    public function test_a_folder_that_does_not_exist_files_nothing(): void
    {
        $_REQUEST[UploadTarget::PARAM] = '999999';

        self::assertSame([], $this->folderIdsFor($this->upload()));
    }

    /**
     * The reason for priority 5.
     *
     * A site's rule — "product images go to /Products" — is a standing
     * decision, and a person's current folder must not silently override it.
     * Ours answers first so anything at the default priority can replace the
     * answer.
     */
    public function test_a_site_filter_at_the_default_priority_wins(): void
    {
        $requested = $this->newFolder('Requested');
        $ruled = $this->newFolder('Ruled');

        $_REQUEST[UploadTarget::PARAM] = (string) $requested;

        add_filter(
            'folderfolio_default_folder_for_upload',
            static fn ($folderId) => $ruled,
            10,
            2
        );

        self::assertSame([$ruled], $this->folderIdsFor($this->upload()));
    }

    /**
     * And a site filter that declines still leaves ours standing, rather than
     * the two cancelling each other out.
     */
    public function test_a_site_filter_that_returns_the_value_it_was_given_changes_nothing(): void
    {
        $requested = $this->newFolder('Requested');
        $_REQUEST[UploadTarget::PARAM] = (string) $requested;

        add_filter(
            'folderfolio_default_folder_for_upload',
            static fn ($folderId) => $folderId,
            10,
            2
        );

        self::assertSame([$requested], $this->folderIdsFor($this->upload()));
    }
}
