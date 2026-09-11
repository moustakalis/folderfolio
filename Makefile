# FolderFolio Build Process
# Uses mise for all dependency and build management

.PHONY: help deps build build-watch zip test e2e clean clean-all

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

clean:
	mise run dev:clean

clean-all:
	mise run dev:clean-all
