# FolderFolio

**Organize your WordPress Media Library with unlimited virtual folders. No tiers. No upsells.**

## Version

1.0.0 - Initial Release

## Features

- ✅ Unlimited nested folders
- ✅ Drag-and-drop organization
- ✅ Multi-folder membership
- ✅ Folder colors and icons
- ✅ Bulk operations (assign, move, unassign)
- ✅ Upload directly to folder
- ✅ Auto-assign uploads to active folder
- ✅ Media Library filtering
- ✅ Media Modal integration (Gutenberg, Classic Editor, ACF, Customizer)
- ✅ Import from FileBird (free & Pro)
- ✅ Safe deactivation (no data loss)
- ✅ REST API for extensibility

## Requirements

- WordPress 6.4+
- PHP 8.0+
- Node.js 18+ (for building assets)

## Installation

### From GitHub

1. Clone or download this repository
2. Run `composer install --no-dev` to generate the autoloader
3. Run `npm install && npm run build` to compile assets
4. Upload the `folderfolio` folder to `/wp-content/plugins/`
5. Activate via WordPress admin (Plugins → FolderFolio)

### Without Composer

If you don't have Composer installed, create a simple autoloader at `vendor/autoload.php`:

```php
<?php
spl_autoload_register(function ($class) {
    $prefix = 'FolderFolio\\';
    $base_dir = __DIR__ . '/../src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) require $file;
});
```

## Usage

### Media Library

1. Go to **Media → Library**
2. Folder tree appears in the sidebar
3. Click **New** to create folders
4. Click a folder to filter media
5. Drag attachments to folders or use bulk actions

### Uploading to Folder

1. Select a folder in the sidebar
2. Click **Upload** button
3. Files automatically assigned to selected folder

### Media Modal (Gutenberg/Classic Editor)

1. Click **Add Media** or insert Image block
2. Folder tree appears in modal sidebar
3. Browse folders while selecting media
4. Filter by folder to find assets faster

### Importing from FileBird

1. Go to **Media → Import**
2. Click **Import** next to FileBird
3. Confirm migration
4. All folders and assignments migrated

## REST API

FolderFolio exposes a REST API at `/wp-json/folderfolio/v1/`:

- `GET /tree` - Get folder tree
- `POST /folders` - Create folder
- `PATCH /folders/{id}` - Update folder
- `DELETE /folders/{id}` - Delete folder
- `POST /folders/{id}/move` - Move folder
- `GET /folders/{id}/attachments` - Get folder attachments
- `POST /attachments/assign` - Assign attachments to folder
- `POST /attachments/unassign` - Unassign attachments
- `POST /attachments/bulk-move` - Move attachments between folders
- `GET /import/detect` - Detect importable plugins
- `POST /import/{importer}` - Run importer

## Development

```bash
# Install dependencies
composer install
npm install

# Development watch mode
npm run dev

# Production build
npm run build
```

## Changelog

### 1.0.0 (2026-09-08)

- Initial release
- Complete folder management (CRUD)
- Media Library integration
- Media Modal integration
- Bulk operations
- Upload-to-folder
- FileBird importer
- REST API

## License

GPL-2.0-or-later. See [LICENSE](LICENSE) for details.

## Support

- GitHub Issues: https://github.com/moustakalis/folderfolio/issues
- Documentation: https://github.com/moustakalis/folderfolio/wiki
