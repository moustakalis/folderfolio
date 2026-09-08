# FolderFolio Build Process
# Uses mise for all dependency and build management
.PHONY: help install build build-prod test test-e2e clean zip wp-link wp-unlink

# WordPress environment (customize these)
WP_ROOT ?= /var/www/html
WP_PLUGINS ?= $(WP_ROOT)/wp-content/plugins

# Default target
help:
	@echo "FolderFolio Commands (all via mise)"
	@echo ""
	@echo "  mise install   - Install PHP 8.2, Node 20, Composer, npm"
	@echo "  mise run install - Install project dependencies"
	@echo "  mise run build   - Build assets (development)"
	@echo "  mise run build-prod - Build assets (production)"
	@echo "  mise run test    - Run PHPUnit tests"
	@echo "  mise run test-e2e  - Run Playwright E2E tests"
	@echo "  mise run clean   - Remove build artifacts"
	@echo "  mise run zip     - Create installable plugin ZIP"
	@echo ""
	@echo "WordPress Development:"
	@echo "  mise run wp-link   - Create symlink to local WordPress"
	@echo "  mise run wp-unlink - Remove symlink"
	@echo ""
	@echo "Or use make as wrapper:"
	@echo "  make install, make build, make test, etc."

# Install dependencies
install:
	@$(MAKE) mise-install

# Build assets (development)
build:
	@$(MAKE) mise-build

# Build assets (production)
build-prod:
	@$(MAKE) mise-build-prod

# Run PHPUnit tests
test:
	@$(MAKE) mise-test

# Run Playwright E2E tests
test-e2e:
	@$(MAKE) mise-test-e2e

# Clean build artifacts
clean:
	@$(MAKE) mise-clean

# Create installable plugin ZIP
zip:
	@$(MAKE) mise-zip

# Create symlink to WordPress plugins directory
wp-link:
	@$(MAKE) mise-wp-link

# Remove symlink
wp-unlink:
	@$(MAKE) mise-wp-unlink

# --- Mise task implementations ---

mise-install:
	@echo "Installing PHP dependencies..."
	composer install --no-interaction
	@echo "Installing Node dependencies..."
	npm install
	@echo "Done!"

mise-build:
	@echo "Building assets..."
	npm run build
	@echo "Copying CSS files..."
	mkdir -p assets/build/core
	cp assets/src/core/admin.css assets/build/core/admin.css
	cp assets/src/core/media-modal.css assets/build/core/media-modal.css
	@echo "Build complete! Assets in assets/build/core/"

mise-build-prod:
	@echo "Building production assets..."
	npm run build
	@echo "Copying CSS files..."
	mkdir -p assets/build/core
	cp assets/src/core/admin.css assets/build/core/admin.css
	cp assets/src/core/media-modal.css assets/build/core/media-modal.css
	@echo "Production build complete!"

mise-test:
	@echo "Running PHPUnit tests..."
	composer test

mise-test-e2e:
	@echo "Running Playwright E2E tests..."
	npm run test:e2e

mise-clean:
	@echo "Cleaning build artifacts..."
	rm -rf assets/build/
	rm -rf build/
	rm -rf vendor/
	rm -rf node_modules/
	rm -f package-lock.json
	rm -f composer.lock
	rm -rf .phpunit.cache/
	rm -rf playwright-report/
	@echo "Clean complete!"

mise-zip:
	@echo "Creating plugin ZIP..."
	mkdir -p build/folderfolio
	rsync -a \
		--exclude='.git' \
		--exclude='.github' \
		--exclude='node_modules' \
		--exclude='tests' \
		--exclude='build' \
		--exclude='*.map' \
		--exclude='.DS_Store' \
		--exclude='phpunit.xml.dist' \
		--exclude='phpstan.neon' \
		--exclude='playwright*' \
		--exclude='*.spec.ts' \
		--exclude='composer.json' \
		--exclude='composer.lock' \
		--exclude='package.json' \
		--exclude='package-lock.json' \
		--exclude='Makefile' \
		./ build/folderfolio/
	cd build && zip -qr folderfolio-latest.zip folderfolio/
	@echo "Plugin ZIP created: build/folderfolio-latest.zip"
	@echo "Install this ZIP in WordPress: /wp-admin/plugin-install.php"

mise-wp-link:
	@echo "Creating symlink to WordPress..."
	@if [ ! -d "$(WP_PLUGINS)" ]; then \
		echo "Error: WordPress plugins directory not found at $(WP_PLUGINS)"; \
		echo "Please set WP_ROOT correctly, e.g.: make wp-link WP_ROOT=/path/to/wordpress"; \
		exit 1; \
	fi
	@if [ -L "$(WP_PLUGINS)/folderfolio" ]; then \
		echo "Symlink already exists at $(WP_PLUGINS)/folderfolio"; \
		echo "Run 'make wp-unlink' first to remove it."; \
		exit 1; \
	fi
	@if [ -d "$(WP_PLUGINS)/folderfolio" ]; then \
		echo "Error: Directory already exists at $(WP_PLUGINS)/folderfolio"; \
		echo "Please remove or rename it first."; \
		exit 1; \
	fi
	ln -s "$(CURDIR)" "$(WP_PLUGINS)/folderfolio"
	@echo "✓ Symlink created: $(WP_PLUGINS)/folderfolio -> $(CURDIR)"
	@echo ""
	@echo "Next steps:"
	@echo "  1. Run 'make build' to compile assets"
	@echo "  2. Visit WordPress admin and activate FolderFolio"
	@echo "  3. Edit code - changes will be reflected immediately"
	@echo "  4. Run 'make build' again to rebuild assets"

mise-wp-unlink:
	@echo "Removing symlink..."
	@if [ ! -L "$(WP_PLUGINS)/folderfolio" ]; then \
		echo "No symlink found at $(WP_PLUGINS)/folderfolio"; \
		exit 0; \
	fi
	rm "$(WP_PLUGINS)/folderfolio"
	@echo "✓ Symlink removed"
