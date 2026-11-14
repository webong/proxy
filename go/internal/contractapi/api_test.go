package contractapi

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"reflect"
	"strconv"
	"strings"
	"testing"
	"time"
)

func TestFrozenTransportAPI(t *testing.T) {
	actual, err := Snapshot("../..")
	if err != nil {
		t.Fatal(err)
	}
	data, err := os.ReadFile("../../testdata/spec/api/go-v0.3.json")
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

// Compile/run the public contract as a separate consumer, with no go.work or
// parent repository fixtures available to supply undeclared module dependencies.
func TestIndependentModuleConsumer(t *testing.T) {
	root, err := filepath.Abs("../..")
	if err != nil {
		t.Fatal(err)
	}
	consumer := t.TempDir()
	manifest := fmt.Sprintf("module proxy-consumer\n\ngo 1.23.0\n\nrequire github.com/webong/proxy/go v0.4.0\nreplace github.com/webong/proxy/go => %s\n", strconv.Quote(root))
	if err = os.WriteFile(filepath.Join(consumer, "go.mod"), []byte(manifest), 0600); err != nil {
		t.Fatal(err)
	}
	files, err := filepath.Glob(filepath.Join(root, "tests/contract/*_test.go"))
	if err != nil || len(files) == 0 {
		t.Fatalf("missing consumer tests: %v", err)
	}
	for _, file := range files {
		data, err := os.ReadFile(file)
		if err != nil {
			t.Fatal(err)
		}
		if err = os.WriteFile(filepath.Join(consumer, filepath.Base(file)), data, 0600); err != nil {
			t.Fatal(err)
		}
	}
	ctx, cancel := context.WithTimeout(context.Background(), 60*time.Second)
	defer cancel()
	command := exec.CommandContext(ctx, "go", "test", "-race", "./...")
	command.Dir = consumer
	command.Env = append(os.Environ(), "GOWORK=off", "GOPROXY=off", "GOSUMDB=off", "GOTOOLCHAIN=local")
	if output, err := command.CombinedOutput(); err != nil {
		t.Fatalf("external module consumer failed: %v\n%s", err, output)
	}
}
