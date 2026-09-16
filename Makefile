# FolderFolio Build Process
# Uses mise for all dependency and build management

.PHONY: help deps build build-watch zip test e2e clean clean-all wp-link wp-unlink

help:
	@echo "FolderFolio Commands (all via mise)"
	@echo ""
	@echo "  mise install              - Install PHP 8.2, Node 20, Composer, npm"
	@echo "  mise run deps:install     - Install project dependencies"
	@echo "  mise run assets:build     - Build production assets"
	@echo "  mise run assets:watch     - Watch and rebuild assets during development"
	@echo "  mise run test:unit        - Run PHPUnit tests"
	@echo "  mise run test:e2e         - Run Playwright E2E tests"
	@echo "  mise run dev:clean        - Remove build artifacts (keeps node_modules, vendor)"
	@echo "  mise run dev:clean-all    - Remove all including node_modules and vendor"
	@echo "  mise run dev:zip          - Create installable plugin ZIP"
	@echo ""
	@echo "Make aliases:"
	@echo "  make deps                 - Install dependencies"
	@echo "  make build                - Build production assets"
	@echo "  make build-watch          - Watch and rebuild assets"
	@echo "  make zip                  - Create distributable plugin ZIP"
	@echo "  make test                 - Run unit tests"
	@echo "  make e2e                  - Run E2E tests"
	@echo "  make wp-link WP_ROOT=...   - Symlink this checkout into a WordPress install"
	@echo "  make wp-unlink WP_ROOT=... - Remove that symlink"
	@echo "  make clean                - Remove build artifacts (fast)"
	@echo "  make clean-all            - Remove everything including dependencies"

deps:
	mise run deps:install

build:
	mise run assets:build

build-watch:
	mise run assets:watch

zip:
	mise run dev:zip

test:
	mise run test:unit

e2e:
	mise run test:e2e

wp-link:
	@test -n "$(WP_ROOT)" || (echo "Usage: make wp-link WP_ROOT=/path/to/wordpress"; exit 1)
	@test -d "$(WP_ROOT)/wp-content/plugins" || (echo "No wp-content/plugins under $(WP_ROOT)"; exit 1)
	ln -sfn "$(CURDIR)" "$(WP_ROOT)/wp-content/plugins/folderfolio"
	@echo "Linked $(CURDIR) -> $(WP_ROOT)/wp-content/plugins/folderfolio"

wp-unlink:
	@test -n "$(WP_ROOT)" || (echo "Usage: make wp-unlink WP_ROOT=/path/to/wordpress"; exit 1)
	@test -L "$(WP_ROOT)/wp-content/plugins/folderfolio" || (echo "Not a symlink; refusing to remove"; exit 1)
	rm "$(WP_ROOT)/wp-content/plugins/folderfolio"
	@echo "Unlinked $(WP_ROOT)/wp-content/plugins/folderfolio"

clean:
	mise run dev:clean

clean-all:
	mise run dev:clean-all
