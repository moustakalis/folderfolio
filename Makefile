# FolderFolio Build Process
# Uses mise for all dependency and build management
.PHONY: help deps:install assets:build assets:build-prod test:unit test:e2e dev:clean dev:zip wp-link wp-unlink

# WordPress environment (customize these)
WP_ROOT ?= /var/www/html
WP_PLUGINS ?= $(WP_ROOT)/wp-content/plugins

# Default target
help:
	@echo "FolderFolio Commands (all via mise)"
	@echo ""
	@echo "  mise install         - Install PHP 8.2, Node 20, Composer, npm"
	@echo "  mise run deps:install - Install project dependencies"
	@echo "  mise run assets:build   - Build assets (development)"
	@echo "  mise run assets:build-prod - Build assets (production)"
	@echo "  mise run test:unit      - Run PHPUnit tests"
	@echo "  mise run test:e2e       - Run Playwright E2E tests"
	@echo "  mise run dev:clean      - Remove build artifacts"
	@echo "  mise run dev:zip        - Create installable plugin ZIP"
	@echo ""
	@echo "WordPress Development:"
	@echo "  mise run wp-link   - Create symlink to local WordPress"
	@echo "  mise run wp-unlink - Remove symlink"
	@echo ""
	@echo "Make wrapper (optional):"
	@echo "  make deps:install, make assets:build, make test:unit, etc."

# Install dependencies
deps:install:
	@mise run deps:install

# Build assets (development)
assets:build:
	@mise run assets:build

# Build assets (production)
assets:build-prod:
	@mise run assets:build-prod

# Run PHPUnit tests
test:unit:
	@mise run test:unit

# Run Playwright E2E tests
test:e2e:
	@mise run test:e2e

# Clean build artifacts
dev:clean:
	@mise run dev:clean

# Create installable plugin ZIP
dev:zip:
	@mise run dev:zip

# Create symlink to WordPress plugins directory
wp-link:
	@$(MAKE) mise-wp-link

# Remove symlink
wp-unlink:
	@$(MAKE) mise-wp-unlink

# --- Mise task implementations ---

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
	@echo "  1. Run 'make assets:build' to compile assets"
	@echo "  2. Visit WordPress admin and activate FolderFolio"
	@echo "  3. Edit code - changes will be reflected immediately"
	@echo "  4. Run 'make assets:build' again to rebuild assets"

mise-wp-unlink:
	@echo "Removing symlink..."
	@if [ ! -L "$(WP_PLUGINS)/folderfolio" ]; then \
		echo "No symlink found at $(WP_PLUGINS)/folderfolio"; \
		exit 0; \
	fi
	rm "$(WP_PLUGINS)/folderfolio"
	@echo "✓ Symlink removed"
