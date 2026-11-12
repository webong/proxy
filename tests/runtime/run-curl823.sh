#!/usr/bin/env bash
set -euo pipefail

# Verify the loaded library, not merely the installed curl command's version.
php -r 'if (!extension_loaded("curl") || version_compare(curl_version()["version"], "8.23.0", "<")) { throw new RuntimeException("PHP must load libcurl 8.23+"); } echo "PHP ",PHP_VERSION," / libcurl ",curl_version()["version"],PHP_EOL;'

# /source is mounted read-only; Testbench and PHPUnit write only in this copy.
proxy_test_checkout=$(mktemp -d /tmp/proxy-curl823.XXXXXX)
trap 'rm -rf "$proxy_test_checkout"' EXIT

tar -C /source --exclude=.git --exclude=.phpunit.cache -cf - . \
    | tar -C "$proxy_test_checkout" -xf -
cd "$proxy_test_checkout"

if [[ ! -f vendor/autoload.php ]]; then
    echo 'Install Composer dependencies in the source checkout before running this image.' >&2
    exit 1
fi

# A green run must exercise every test, including the authentication downgrade case.
php vendor/bin/pest --configuration=phpunit.xml.dist \
    --cache-directory="$proxy_test_checkout/.phpunit.cache" --fail-on-skipped "$@"
