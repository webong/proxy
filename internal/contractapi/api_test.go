package contractapi

import (
	"context"
	"encoding/json"
	"os"
	"os/exec"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
	"time"
)

func TestFrozenTransportAPI(t *testing.T) {
	actual, err := Snapshot("../..")
	if err != nil {
		t.Fatal(err)
	}
	data, err := os.ReadFile("../../spec/api/go-v0.3.json")
	if err != nil {
		t.Fatal(err)
	}
	var expected map[string][]string
	if err = json.Unmarshal(data, &expected); err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(actual, expected) {
		t.Fatal("public Go API changed; review spec/CONTRACT.md before updating spec/api/go-v0.3.json")
	}
}

func TestSnapshotDistinguishesPublicAndPrivateChanges(t *testing.T) {
	root := t.TempDir()
	copyGoPackages(t, root, []string{"httpprofile", "httpclient", "socketprofile", "tcpdial", "udpdial", "internal/socks5"})
	before, err := Snapshot(root)
	if err != nil {
		t.Fatal(err)
	}
	path := filepath.Join(root, "tcpdial/dialer.go")
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	private := strings.Replace(string(data), "type Dialer struct {", "type Dialer struct {\n privateReadinessProbe int", 1) + "\nfunc privateReadinessProbe() {}\n"
	if err = os.WriteFile(path, []byte(private), 0600); err != nil {
		t.Fatal(err)
	}
	after, err := Snapshot(root)
	if err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(before, after) {
		t.Fatal("private implementation changes leaked into contract")
	}
	if err = os.WriteFile(path, []byte(private+"\nfunc PublicReadinessProbe() {}\n"), 0600); err != nil {
		t.Fatal(err)
	}
	after, err = Snapshot(root)
	if err != nil {
		t.Fatal(err)
	}
	if reflect.DeepEqual(before, after) {
		t.Fatal("public API change was not detected")
	}
}

func copyGoPackages(t *testing.T, root string, packages []string) {
	t.Helper()
	for _, pkg := range packages {
		files, err := filepath.Glob(filepath.Join("../..", pkg, "*.go"))
		if err != nil {
			t.Fatal(err)
		}
		for _, file := range files {
			if strings.HasSuffix(file, "_test.go") {
				continue
			}
			data, err := os.ReadFile(file)
			if err != nil {
				t.Fatal(err)
			}
			target := filepath.Join(root, pkg, filepath.Base(file))
			if err = os.MkdirAll(filepath.Dir(target), 0700); err != nil {
				t.Fatal(err)
			}
			if err = os.WriteFile(target, data, 0600); err != nil {
				t.Fatal(err)
			}
		}
	}
}

func TestConsumerGuardRejectsConcreteErrorVariable(t *testing.T) {
	root := t.TempDir()
	copyGoPackages(t, root, []string{"httpprofile", "httpclient", "socketprofile", "tcpdial", "udpdial", "internal/socks5", "internal/proxyurl"})
	for _, path := range []string{"go.mod", "tests/contract/consumer_test.go"} {
		data, err := os.ReadFile(filepath.Join("../..", path))
		if err != nil {
			t.Fatal(err)
		}
		target := filepath.Join(root, path)
		if err = os.MkdirAll(filepath.Dir(target), 0700); err != nil {
			t.Fatal(err)
		}
		if err = os.WriteFile(target, data, 0600); err != nil {
			t.Fatal(err)
		}
	}
	path := filepath.Join(root, "tcpdial/httpconnect.go")
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	changed := strings.Replace(string(data), `var ErrConnectProtocol = errors.New("invalid HTTP CONNECT response")`, `var ErrConnectProtocol = &ConnectError{StatusCode:407}`, 1)
	if changed == string(data) {
		t.Fatal("mutation did not apply")
	}
	if err = os.WriteFile(path, []byte(changed), 0600); err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 45*time.Second)
	defer cancel()
	command := exec.CommandContext(ctx, "go", "test", "-race", "-run=^$", "./tests/contract")
	command.Dir = root
	command.Env = append(os.Environ(), "GOWORK=off", "GOPROXY=off", "GOSUMDB=off", "GOTOOLCHAIN=local")
	output, err := command.CombinedOutput()
	if ctx.Err() != nil {
		t.Fatalf("consumer guard timed out: %s", output)
	}
	if err == nil || !strings.Contains(string(output), "ErrConnectProtocol") || !strings.Contains(string(output), "cannot use") {
		t.Fatalf("error variable type break was not caught by consumer guard: %v\n%s", err, output)
	}
}
