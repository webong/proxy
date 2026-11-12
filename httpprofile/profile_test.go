package httpprofile

import (
	"encoding/json"
	"math"
	"os"
	"path/filepath"
	"testing"
)

func TestFixtures(t *testing.T) {
	for _, category := range []string{"valid", "invalid"} {
		files, _ := filepath.Glob("../spec/fixtures/" + category + "/*.json")
		if len(files) == 0 {
			t.Fatal("missing fixtures")
		}
		for _, file := range files {
			t.Run(file, func(t *testing.T) {
				data, err := os.ReadFile(file)
				if err != nil {
					t.Fatal(err)
				}
				profile, parseErr := Parse(data)
				err = parseErr
				var viaJSON Profile
				jsonErr := json.Unmarshal(data, &viaJSON)
				if (jsonErr == nil) != (category == "valid") {
					t.Fatalf("UnmarshalJSON: %v", jsonErr)
				}
				if category == "valid" && parseErr == nil {
					serialized, err := json.Marshal(profile)
					if err != nil {
						t.Fatal(err)
					}
					if _, err = Parse(serialized); err != nil {
						t.Fatal(err)
					}
				}
				if (err == nil) != (category == "valid") {
					t.Fatalf("unexpected result: %v", err)
				}
			})
		}
	}
}

func TestInvalidProgrammaticProfiles(t *testing.T) {
	for _, p := range []Profile{{Version: 2}, {Version: 1, RequestTimeoutSeconds: math.NaN()}, {Version: 1, RequestTimeoutSeconds: math.Inf(1)}, {Version: 1, ConnectTimeoutSeconds: -1}, {Version: 1, Proxy: &Proxy{HTTP: "http://password%zz@host"}}} {
		if p.Validate() == nil {
			t.Errorf("invalid profile accepted")
		}
		if _, err := json.Marshal(p); err == nil {
			t.Error("invalid profile serialized")
		}
	}
}
