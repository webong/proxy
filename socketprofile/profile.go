// Package socketprofile defines the shared direct/SOCKS raw-socket profile.
package socketprofile

import (
	"bytes"
	"encoding/json"
	"fmt"
	"math"

	"github.com/webong/proxy/internal/proxyurl"
)

// Profile describes a direct or SOCKS5 socket connection, independent of HTTP.
// Omitted connect_timeout_seconds defaults to 10 seconds for the complete setup.
type Profile struct {
	Version               int     `json:"version"`
	Proxy                 string  `json:"proxy,omitempty"`
	ConnectTimeoutSeconds float64 `json:"connect_timeout_seconds,omitempty"`
}

func Parse(data []byte) (Profile, error) {
	var p Profile
	var fields map[string]json.RawMessage
	if err := json.Unmarshal(data, &fields); err != nil || fields == nil {
		return p, fmt.Errorf("socket profile must be an object")
	}
	for key, value := range fields {
		if (key != "version" && key != "proxy" && key != "connect_timeout_seconds") || bytes.Equal(bytes.TrimSpace(value), []byte("null")) {
			return p, fmt.Errorf("invalid socket profile field")
		}
		if key == "proxy" {
			var s string
			if json.Unmarshal(value, &s) != nil || s == "" {
				return p, fmt.Errorf("socket proxy must be a non-empty URL")
			}
		}
		if key == "connect_timeout_seconds" {
			var n float64
			if json.Unmarshal(value, &n) != nil || n <= 0 {
				return p, fmt.Errorf("invalid socket timeout")
			}
		}
	}
	type wire Profile
	var decoded wire
	if json.Unmarshal(data, &decoded) != nil {
		return p, fmt.Errorf("invalid socket profile types")
	}
	p = Profile(decoded)
	return p, p.Validate()
}
func (p Profile) Validate() error {
	if p.Version != 1 {
		return fmt.Errorf("unsupported socket profile version")
	}
	n := p.ConnectTimeoutSeconds
	if math.IsNaN(n) || math.IsInf(n, 0) || n < 0 || n > 300 {
		return fmt.Errorf("invalid socket timeout")
	}
	if p.Proxy != "" {
		u, err := proxyurl.Parse(p.Proxy)
		if err != nil {
			return err
		}
		if !proxyurl.IsSOCKS(u) {
			return fmt.Errorf("socket proxy must use socks5 or socks5h")
		}
	}
	return nil
}
func (p *Profile) UnmarshalJSON(data []byte) error {
	v, err := Parse(data)
	if err == nil {
		*p = v
	}
	return err
}
func (p Profile) MarshalJSON() ([]byte, error) {
	if err := p.Validate(); err != nil {
		return nil, err
	}
	type wire Profile
	return json.Marshal(wire(p))
}
