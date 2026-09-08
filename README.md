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
- Node.js 20+

## Quick Start

### 1. Install mise (one-time)

```bash
curl https://mise.run | sh
source ~/.local/bin/env  # or restart your shell
```

### 2. Setup project

```bash
# Install PHP 8.2, Node.js 20, and tools
mise install

# Install project dependencies
mise run deps:install

# Build assets
mise run assets:build
```

### 3. Link to WordPress (optional)

```bash
# Create symlink to your WordPress plugins directory
make wp-link WP_ROOT=/path/to/wordpress

# Build and activate
make assets:build
# Visit WordPress admin → Plugins → Activate FolderFolio
```

### 4. Create installable ZIP

```bash
make dev:zip
# Upload build/folderfolio-latest.zip to WordPress
```

## Development Commands

```bash
mise install           # Install PHP 8.2, Node 20, Composer, npm
mise run deps:install  # Install Composer + npm packages
mise run assets:build  # Build assets (development)
mise run assets:build-prod  # Build assets (production)
mise run test:unit     # Run PHPUnit tests
mise run test:e2e      # Run Playwright E2E tests
mise run dev:clean     # Remove build artifacts
mise run dev:zip       # Create installable plugin ZIP
make wp-link           # Symlink to WordPress for development
make wp-unlink         # Remove symlink
```

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

## Testing

```bash
# PHPUnit tests
mise run test:unit

# Playwright E2E tests
mise run test:e2e
```

## License

GPL-2.0-or-later. See [LICENSE](LICENSE) for details.

## Support

- GitHub Issues: https://github.com/moustakalis/folderfolio/issues
- Documentation: https://github.com/moustakalis/folderfolio/wiki
