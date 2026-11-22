# Proxy for Go

Module: **`github.com/webong/proxy/src/go`**, Go 1.23+. HTTP profiles/client factory,
direct and SOCKS5 TCP dialers, HTTP/HTTPS CONNECT, direct UDP and SOCKS5 UDP
ASSOCIATE. The module has no external Go dependencies and no registry store.

This source layout is not yet released. Future releases use repository tags
`src/go/vX.Y.Z`. Existing `go/v0.4.0` remains unchanged and installs the previous
`github.com/webong/proxy/go` module.

```go
import (
    "github.com/webong/proxy/src/go/httpclient"
    "github.com/webong/proxy/src/go/httpprofile"
)

profile, err := httpprofile.Parse(profileJSON)
if err != nil { return err }
client, err := httpclient.New(profile, nil)
if err != nil { return err }
defer client.CloseIdleConnections()
```

Other public packages are `socketprofile`, `tcpdial` and `udpdial`. Reuse clients;
callers own destination authorization, redirects and connection deadlines after
setup. The [shared contracts](https://github.com/webong/proxy/blob/main/spec/CONTRACT.md)
document TLS, SOCKS authentication, UDP limits and supported PHP/Go asymmetries.

## Upgrade to this layout

Change imports from `github.com/webong/proxy/go/` (or legacy
`github.com/webong/proxy/`) to `github.com/webong/proxy/src/go/`. Once a version
is released under `src/go/vX.Y.Z`, require that version and run `go mod tidy`.
For local development, use a consuming workspace or an explicit local replace
pointing to this module. No compatibility wrappers or root Go module are added.
Old versions and their tags remain unchanged.

## Tests and fixtures

From this module directory:

```sh
go test -race ./...
go vet ./...
```

The same commands work after extracting only this directory, without the PHP
workspace, a parent go.work, or network dependency resolution. The public consumer
tests also run in a separate temporary module using the new import path.

`testdata/spec` and `testdata/tls` are checked-in mirrors of the repository's shared
fixtures. Update canonical fixtures at the repository root and run
`python3 scripts/sync_go_testdata.py`; do not edit the mirrors independently.
CI checks that they are byte-identical real files, rejects linked mirrors and
missing canonical groups, and tests synchronization failures in isolated copies. The API guard compares the unchanged v0.3
API-shape baseline, independently of the new module path.

After an explicit API contract review, regenerate the canonical snapshot from the
repository root, then refresh mirrors:

```sh
go -C src/go run ./scripts/snapshot-api -write -baseline ../../spec/api/go-v0.3.json
python3 scripts/sync_go_testdata.py
```
