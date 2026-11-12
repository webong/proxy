// Package httpprofile defines portable HTTP connection profiles shared with PHP.
package httpprofile

import (
	"bytes"
	"encoding/json"
	"fmt"
	"math"
	"net"
	"net/url"
	"regexp"
	"strconv"
	"strings"
)

type Proxy struct {
	HTTP    string   `json:"http,omitempty"`
	HTTPS   string   `json:"https,omitempty"`
	NoProxy []string `json:"no_proxy,omitempty"`
}
type Profile struct {
	Version               int     `json:"version"`
	Proxy                 *Proxy  `json:"proxy,omitempty"`
	ConnectTimeoutSeconds float64 `json:"connect_timeout_seconds,omitempty"`
	RequestTimeoutSeconds float64 `json:"request_timeout_seconds,omitempty"`
}

// Parse validates the versioned JSON contract, including exact field names and containers.
func Parse(data []byte) (Profile, error) {
	var p Profile
	var fields map[string]json.RawMessage
	if err := json.Unmarshal(data, &fields); err != nil {
		return p, fmt.Errorf("profile must be an object")
	}
	if fields == nil {
		return p, fmt.Errorf("profile must be an object")
	}
	allowed := map[string]bool{"version": true, "proxy": true, "connect_timeout_seconds": true, "request_timeout_seconds": true}
	for key, value := range fields {
		if !allowed[key] || bytes.Equal(bytes.TrimSpace(value), []byte("null")) {
			return p, fmt.Errorf("invalid profile field %s", key)
		}
	}
	if value, ok := fields["proxy"]; ok {
		var proxy map[string]json.RawMessage
		if err := json.Unmarshal(value, &proxy); err != nil || proxy == nil {
			return p, fmt.Errorf("proxy must be an object")
		}
		for key, value := range proxy {
			if key != "http" && key != "https" && key != "no_proxy" {
				return p, fmt.Errorf("unknown proxy field %s", key)
			}
			if bytes.Equal(bytes.TrimSpace(value), []byte("null")) {
				return p, fmt.Errorf("proxy fields cannot be null")
			}
			if key != "no_proxy" {
				var u string
				if json.Unmarshal(value, &u) != nil || u == "" {
					return p, fmt.Errorf("proxy URL must be non-empty")
				}
			}
		}
	}
	type wire Profile
	var decoded wire
	if err := json.Unmarshal(data, &decoded); err != nil {
		return p, fmt.Errorf("invalid profile types")
	}
	p = Profile(decoded)
	for _, key := range []string{"connect_timeout_seconds", "request_timeout_seconds"} {
		if value, ok := fields[key]; ok {
			var n float64
			if json.Unmarshal(value, &n) != nil || n <= 0 {
				return p, fmt.Errorf("timeout must be positive")
			}
		}
	}
	return p, p.Validate()
}

// UnmarshalJSON applies the same checks as Parse rather than Go's permissive struct decoder.
func (p *Profile) UnmarshalJSON(data []byte) error {
	parsed, err := Parse(data)
	if err == nil {
		*p = parsed
	}
	return err
}

// MarshalJSON prevents programmatically constructed invalid profiles being published.
func (p Profile) MarshalJSON() ([]byte, error) {
	if err := p.Validate(); err != nil {
		return nil, err
	}
	type wire Profile
	return json.Marshal(wire(p))
}

var proxyURLPattern = regexp.MustCompile(`^https?://(?:[A-Za-z0-9._~!$&'()*+,;=:%-]*@)?(?:\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\.?)(?::[0-9]{1,5})?/?$`)
var invalidEscape = regexp.MustCompile(`%(?:[^0-9A-Fa-f]|[0-9A-Fa-f](?:[^0-9A-Fa-f]|$)|$)`)

var hostPattern = regexp.MustCompile(`^\.?[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*$`)

func (p Profile) Validate() error {
	if p.Version != 1 {
		return fmt.Errorf("unsupported profile version")
	}
	for _, n := range []float64{p.ConnectTimeoutSeconds, p.RequestTimeoutSeconds} {
		if math.IsNaN(n) || math.IsInf(n, 0) || n < 0 || n > 300 {
			return fmt.Errorf("invalid timeout")
		}
	}
	if p.Proxy == nil {
		return nil
	}
	for _, value := range []string{p.Proxy.HTTP, p.Proxy.HTTPS} {
		if value == "" {
			continue
		}
		if !proxyURLPattern.MatchString(value) || invalidEscape.MatchString(value) {
			return fmt.Errorf("invalid HTTP proxy URL")
		}
		u, err := url.Parse(value)
		if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Hostname() == "" || (u.Path != "" && u.Path != "/") || u.RawPath != "" || u.RawQuery != "" || u.ForceQuery || u.Fragment != "" || strings.ContainsAny(value, " \t\r\n") {
			return fmt.Errorf("invalid HTTP proxy URL")
		}
		if strings.HasPrefix(u.Host, "[") && net.ParseIP(u.Hostname()) == nil {
			return fmt.Errorf("invalid proxy IPv6 address")
		}
		if port := u.Port(); port != "" {
			n, err := strconv.Atoi(port)
			if err != nil || n < 1 || n > 65535 {
				return fmt.Errorf("invalid proxy port")
			}
		}
		if strings.Contains(value, "#") {
			return fmt.Errorf("proxy fragments are not supported")
		}

	}
	for _, host := range p.Proxy.NoProxy {
		if host != "*" && (!hostPattern.MatchString(host) || (strings.HasPrefix(host, ".") && net.ParseIP(host[1:]) != nil)) {
			return fmt.Errorf("invalid no-proxy host")
		}
	}
	return nil
}
