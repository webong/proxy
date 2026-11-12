# Mandatory SOCKS authentication verification

This test-only image builds checksum-pinned libcurl 8.23.0 from the official
curl release archive and loads it into the official PHP 8.5 CLI image. The build
asserts `curl_version()` inside PHP, not the separate curl CLI executable.
It leaves the host PHP/libcurl installation unchanged.

After installing the project's Composer dependencies:

```sh
docker build -f tests/runtime/Dockerfile.curl823 -t proxy-curl823 tests/runtime
docker run --rm \
  --mount "type=bind,source=$PWD,target=/source,readonly" \
  proxy-curl823 bash /source/tests/runtime/run-curl823.sh --compact
```

To run only the successful authenticated exchange and malicious downgrade case:

```sh
docker run --rm \
  --mount "type=bind,source=$PWD,target=/source,readonly" \
  proxy-curl823 bash /source/tests/runtime/run-curl823.sh --compact \
  --filter='requires authentication rather|rejects malicious no-auth'
```

The runner copies the read-only checkout to a temporary container directory,
including its installed vendor dependencies. Testbench caches, loopback fixture
processes, and generated TLS certificates stay inside the disposable container.
`--fail-on-skipped` prevents the authentication tests being silently omitted.
