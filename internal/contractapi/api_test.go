package contractapi

import (
	"encoding/json"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
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
	for _, pkg := range []string{"httpprofile", "httpclient", "socketprofile", "tcpdial", "udpdial", "internal/socks5"} {
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
