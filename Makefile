PHP ?= php
COMPOSER ?= composer
GO ?= go
PYTHON ?= python3
DOCKER ?= docker
PEST := vendor/bin/pest
PHPUNIT_CONFIG := phpunit.xml.dist

.PHONY: install test test-filter readiness packaging

install:
	PROXY_COMPOSER_BINARY="$(COMPOSER)" $(PHP) scripts/bootstrap-composer.php

test: vendor/autoload.php
	$(PHP) scripts/check_php_api.php
	$(PHP) scripts/check_php_api.php --registry
	$(PHP) $(PEST) --configuration=$(PHPUNIT_CONFIG)

test-filter: vendor/autoload.php
	$(PHP) scripts/check_php_api.php
	$(PHP) scripts/check_php_api.php --registry
	$(PHP) $(PEST) --configuration=$(PHPUNIT_CONFIG) --filter="$(filter)"

vendor/autoload.php: composer.json
	$(MAKE) install

# Dependencies and the isolated image must already be installed/built.
readiness:
	@test -f vendor/autoload.php
	$(PYTHON) -m unittest discover -s tests/tooling -p 'test_*.py'
	$(PYTHON) scripts/sync_go_testdata.py --check
	$(GO) -C go test -race -count=1 ./...
	$(GO) -C go vet ./...
	$(COMPOSER) test
	$(PYTHON) scripts/check_profile_schemas.py
	$(DOCKER) run --rm --pull=never --mount "type=bind,source=$(CURDIR),target=/source,readonly" proxy-curl823 bash /source/tests/runtime/run-curl823.sh --compact

# Real Composer installs from candidate archives, not path-repository consumers.
packaging:
	$(PYTHON) scripts/sync_go_testdata.py --check
	$(PHP) tests/packaging/run.php
