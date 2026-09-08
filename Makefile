# FolderFolio Build Process
.PHONY: help install build build-prod test test-e2e clean zip

# Default target
help:
	@echo "FolderFolio Build Commands"
	@echo ""
	@echo "  make install     - Install all dependencies (Composer + npm)"
	@echo "  make build       - Build assets for development (with sourcemaps)"
	@echo "  make build-prod  - Build assets for production (minified)"
	@echo "  make test        - Run PHPUnit tests"
	@echo "  make test-e2e    - Run Playwright E2E tests"
	@echo "  make clean       - Remove build artifacts"
	@echo "  make zip         - Create installable plugin ZIP"
	@echo ""

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
