# Proxy for Go

Module: **`github.com/webong/proxy/go`**, Go 1.23+. HTTP profiles/client factory,
direct and SOCKS5 TCP dialers, HTTP/HTTPS CONNECT, direct UDP and SOCKS5 UDP
ASSOCIATE. The module has no external Go dependencies and no registry store.

```sh
go get github.com/webong/proxy/go@v0.4.0
```

Version 0.4.0 is unreleased. Publication requires the repository tag
**`go/v0.4.0`**, whose module root is this directory. Root `v0.4.0` tags do not
release this nested Go module.

```go
import (
    "github.com/webong/proxy/go/httpclient"
    "github.com/webong/proxy/go/httpprofile"
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

## Upgrade from v0.3

Replace every import prefix `github.com/webong/proxy/` with
`github.com/webong/proxy/go/`, then use `go get github.com/webong/proxy/go@v0.4.0`
and `go mod tidy` in the consuming app. Retire its old direct module requirement
if no other dependency needs it. Public functions/types and version-1 profile
formats retain their existing shape; this is an intentional module identity/import
path change. Old v0.3.x versions remain available at their original import paths.
No compatibility wrappers or root module are added.

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
go -C go run ./scripts/snapshot-api -write -baseline ../spec/api/go-v0.3.json
python3 scripts/sync_go_testdata.py
```
