# FolderFolio Build Process
# Uses mise for dependency management
.PHONY: help install build build-prod test test-e2e clean zip wp-link wp-unlink mise-setup

# WordPress environment (customize these)
WP_ROOT ?= /var/www/html
WP_PLUGINS ?= $(WP_ROOT)/wp-content/plugins

# Default target
help:
	@echo "FolderFolio Build Commands"
	@echo ""
	@echo "  mise setup   - Install mise and configure tool versions"
	@echo "  make install - Install all dependencies (Composer + npm)"
	@echo "  make build   - Build assets for development (with sourcemaps)"
	@echo "  make build-prod  - Build assets for production (minified)"
	@echo "  make test    - Run PHPUnit tests"
	@echo "  make test-e2e  - Run Playwright E2E tests"
	@echo "  make clean   - Remove build artifacts"
	@echo "  make zip     - Create installable plugin ZIP"
	@echo ""
	@echo "WordPress Development:"
	@echo "  make wp-link   - Create symlink to local WordPress"
	@echo "  make wp-unlink - Remove symlink"
	@echo ""

# Setup mise (one-time)
mise-setup:
	@echo "Setting up mise..."
	@if ! command -v mise &> /dev/null; then \
		echo "Installing mise..."; \
		curl https://mise.run | sh; \
		echo ""; \
		echo "mise installed! Please restart your shell or run:"; \
		echo "  source \"$$HOME/.local/bin/env\""; \
		echo ""; \
	else \
		echo "mise already installed"; \
	fi
	@echo "Installing configured tools..."
	mise install
	@echo "Done! Run 'make install' to install project dependencies."

# Install dependencies
install:
	@echo "Installing PHP dependencies..."
	composer install --no-interaction
	@echo "Installing Node dependencies..."
	npm install
	@echo "Done!"

# Build assets (development)
build:
	@echo "Building assets..."
	npm run build
	@echo "Copying CSS files..."
	mkdir -p assets/build/core
	cp assets/src/core/admin.css assets/build/core/admin.css
	cp assets/src/core/media-modal.css assets/build/core/media-modal.css
	@echo "Build complete! Assets in assets/build/core/"

# Build assets (production)
build-prod:
	@echo "Building production assets..."
	npm run build
	@echo "Copying CSS files..."
	mkdir -p assets/build/core
	cp assets/src/core/admin.css assets/build/core/admin.css
	cp assets/src/core/media-modal.css assets/build/core/media-modal.css
	@echo "Production build complete!"

# Run PHPUnit tests
test:
	@echo "Running PHPUnit tests..."
	composer test

# Run Playwright E2E tests
test-e2e:
	@echo "Running Playwright E2E tests..."
	npm run test:e2e

# Clean build artifacts
clean:
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

# Create installable plugin ZIP
zip: build-prod
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

# Create symlink to WordPress plugins directory
wp-link:
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

# Remove symlink
wp-unlink:
	@echo "Removing symlink..."
	@if [ ! -L "$(WP_PLUGINS)/folderfolio" ]; then \
		echo "No symlink found at $(WP_PLUGINS)/folderfolio"; \
		exit 0; \
	fi
	rm "$(WP_PLUGINS)/folderfolio"
	@echo "✓ Symlink removed"
