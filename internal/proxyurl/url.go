// Package proxyurl validates the common portable proxy URL syntax.
package proxyurl

import (
	"fmt"
	"net"
	"net/url"
	"regexp"
	"strconv"
	"strings"
)

var pattern = regexp.MustCompile(`^(?:https?|socks5h?)://(?:[A-Za-z0-9._~!$&'()*+,;=:%-]*@)?(?:\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\.?)(?::[0-9]{1,5})?/?$`)
var invalidEscape = regexp.MustCompile(`%(?:[^0-9A-Fa-f]|[0-9A-Fa-f](?:[^0-9A-Fa-f]|$)|$)`)

// Parse never includes credentials in errors. SOCKS credentials require both fields.
func Parse(value string) (*url.URL, error) {
	if !pattern.MatchString(value) || invalidEscape.MatchString(value) {
		return nil, fmt.Errorf("invalid proxy URL")
	}
	u, err := url.Parse(value)
	if err != nil || u.Hostname() == "" || u.RawPath != "" || u.RawQuery != "" || u.Fragment != "" || u.ForceQuery {
		return nil, fmt.Errorf("invalid proxy URL")
	}
	if strings.HasPrefix(u.Host, "[") && (net.ParseIP(u.Hostname()) == nil || !strings.Contains(u.Hostname(), ":")) {
		return nil, fmt.Errorf("invalid proxy IPv6 address")
	}
	if port := u.Port(); port != "" {
		n, err := strconv.Atoi(port)
		if err != nil || n < 1 || n > 65535 {
			return nil, fmt.Errorf("invalid proxy port")
		}
	}
	if IsSOCKS(u) && u.User != nil {
		username := u.User.Username()
		password, ok := u.User.Password()
		if !ok || len(username) < 1 || len(username) > 255 || len(password) < 1 || len(password) > 255 {
			return nil, fmt.Errorf("SOCKS credentials must each contain 1 to 255 bytes")
		}
	}
	return u, nil
}
func IsSOCKS(u *url.URL) bool { return u.Scheme == "socks5" || u.Scheme == "socks5h" }
func Address(u *url.URL) string {
	port := u.Port()
	if port == "" {
		port = "1080"
	}
	return net.JoinHostPort(u.Hostname(), port)
}
