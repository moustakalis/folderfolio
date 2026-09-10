.PHONY: help deps build build-prod test e2e clean zip wp-link wp-unlink

deps:
	mise run deps:install

build:
	mise run assets:build

build-prod:
	mise run assets:build

test:
	mise run test:unit

e2e:
	mise run test:e2e

clean:
	mise run dev:clean

zip:
	mise run dev:zip

wp-link:
	mise run wp-link

wp-unlink:
	mise run wp-unlink
