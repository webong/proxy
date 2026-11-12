// Package httpclient builds independent clients from portable HTTP profiles.
package httpclient

import (
	"context"
	"fmt"
	"math"
	"net"
	"net/http"
	"net/url"
	"strings"
	"time"

	"github.com/webong/proxy/httpprofile"
	"github.com/webong/proxy/internal/proxyurl"
	"github.com/webong/proxy/tcpdial"
)

// New clones base so callers can supply TLS settings and a policy-aware dialer.
// With a nil base it uses package-owned standard defaults. Environment proxies are disabled.
// Clients should be reused; call CloseIdleConnections when retiring a profile.
// Redirect and destination authorization policy belong to the caller.
func New(profile httpprofile.Profile, base *http.Transport) (*http.Client, error) {
	if err := profile.Validate(); err != nil {
		return nil, err
	}
	defaultBase := base == nil
	if base == nil {
		base = &http.Transport{
			DialContext:           (&net.Dialer{Timeout: 30 * time.Second, KeepAlive: 30 * time.Second}).DialContext,
			ForceAttemptHTTP2:     true,
			MaxIdleConns:          100,
			IdleConnTimeout:       90 * time.Second,
			TLSHandshakeTimeout:   10 * time.Second,
			ExpectContinueTimeout: time.Second,
		}
	}
	transport := base.Clone()
	transport.Proxy = nil
	if profile.ConnectTimeoutSeconds > 0 {
		timeout := seconds(profile.ConnectTimeoutSeconds)
		if transport.DialContext == nil && transport.Dial != nil {
			return nil, fmt.Errorf("legacy Dial cannot honor a profile connect deadline; use DialContext")
		}
		dial := transport.DialContext
		// Replace the standard dialer's built-in 30s cap when the profile overrides it.
		if defaultBase || dial == nil {
			dial = (&net.Dialer{KeepAlive: 30 * time.Second}).DialContext
		}
		transport.DialContext = deadlineDialer(dial, timeout)
		// Custom TLS dialers bypass DialContext in net/http.
		if transport.DialTLSContext != nil {
			transport.DialTLSContext = deadlineDialer(transport.DialTLSContext, timeout)
		} else if transport.DialTLS != nil {
			return nil, fmt.Errorf("legacy DialTLS cannot honor a profile connect deadline; use DialTLSContext")
		}
		transport.TLSHandshakeTimeout = timeout
	}

	if profile.Proxy != nil {
		settings := *profile.Proxy
		settings.NoProxy = append([]string(nil), settings.NoProxy...)
		transport.Proxy = func(req *http.Request) (*url.URL, error) {
			if bypass(req.URL.Hostname(), settings.NoProxy) {
				return nil, nil
			}
			value := settings.HTTP
			if req.URL.Scheme == "https" {
				value = settings.HTTPS
			}
			if value == "" {
				return nil, nil
			}
			return url.Parse(value)
		}
	}
	var outgoing http.RoundTripper = transport
	if profile.Proxy != nil && (isSOCKS(profile.Proxy.HTTP) || isSOCKS(profile.Proxy.HTTPS)) {
		routed, err := socksRoutes(profile, transport)
		if err != nil {
			return nil, err
		}
		outgoing = routed
	}
	return &http.Client{Transport: outgoing, Timeout: seconds(profile.RequestTimeoutSeconds)}, nil
}
func deadlineDialer(dial func(context.Context, string, string) (net.Conn, error), timeout time.Duration) func(context.Context, string, string) (net.Conn, error) {
	return func(ctx context.Context, network, address string) (net.Conn, error) {
		ctx, cancel := context.WithTimeout(ctx, timeout)
		defer cancel()
		return dial(ctx, network, address)
	}
}

// Match Guzzle/cURL millisecond precision; tiny positive values never become unlimited.
func seconds(n float64) time.Duration { return time.Duration(math.Ceil(n*1000)) * time.Millisecond }
func bypass(host string, entries []string) bool {
	host = strings.ToLower(host)
	targetIP := net.ParseIP(host)
	if targetIP == nil {
		host = strings.TrimSuffix(host, ".")
	}
	for _, entry := range entries {
		entry = strings.ToLower(entry)
		if entry == "*" {
			return true
		}
		ruleIP := net.ParseIP(entry)
		if targetIP != nil || ruleIP != nil {
			if targetIP != nil && ruleIP != nil && host == entry {
				return true
			}
			continue
		}
		if strings.HasPrefix(entry, ".") {
			if strings.HasSuffix(host, entry) {
				return true
			}
		} else if host == entry || strings.HasSuffix(host, "."+entry) {
			return true
		}
	}
	return false
}

// SOCKS routes need a dedicated transport per request scheme so socks5 local DNS
// is not silently changed to the standard library's remote SOCKS resolution.
type schemeTransport struct{ http, https *http.Transport }

func (t *schemeTransport) RoundTrip(request *http.Request) (*http.Response, error) {
	switch request.URL.Scheme {
	case "http":
		return t.http.RoundTrip(request)
	case "https":
		return t.https.RoundTrip(request)
	default:
		return nil, fmt.Errorf("unsupported HTTP scheme")
	}
}
func (t *schemeTransport) CloseIdleConnections() {
	t.http.CloseIdleConnections()
	t.https.CloseIdleConnections()
}
func isSOCKS(value string) bool {
	u, err := proxyurl.Parse(value)
	return err == nil && proxyurl.IsSOCKS(u)
}

type dialFunc func(context.Context, string, string) (net.Conn, error)

func (f dialFunc) DialContext(ctx context.Context, network, address string) (net.Conn, error) {
	return f(ctx, network, address)
}
func socksRoutes(profile httpprofile.Profile, base *http.Transport) (*schemeTransport, error) {
	build := func(value string) (*http.Transport, error) {
		tr := base.Clone()
		if !isSOCKS(value) {
			return tr, nil
		}
		if tr.DialTLSContext != nil || tr.DialTLS != nil {
			return nil, fmt.Errorf("SOCKS HTTP transport cannot use a custom TLS dialer; supply TLSClientConfig instead")
		}
		if tr.DialContext == nil && tr.Dial != nil {
			return nil, fmt.Errorf("SOCKS HTTP transport requires a context-aware dialer")
		}
		direct := tr.DialContext
		if direct == nil {
			direct = (&net.Dialer{KeepAlive: 30 * time.Second}).DialContext
		}
		timeout := profile.ConnectTimeoutSeconds
		if timeout == 0 {
			timeout = 30
		}
		socks, err := tcpdial.New(tcpdial.Profile{Version: 1, Proxy: value, ConnectTimeoutSeconds: timeout}, dialFunc(direct), nil)
		if err != nil {
			return nil, err
		}
		bypasses := append([]string(nil), profile.Proxy.NoProxy...)
		tr.Proxy = nil
		tr.DialContext = func(ctx context.Context, network, address string) (net.Conn, error) {
			host, _, err := net.SplitHostPort(address)
			if err != nil {
				return nil, err
			}
			if bypass(host, bypasses) {
				return direct(ctx, network, address)
			}
			return socks.DialContext(ctx, network, address)
		}
		return tr, nil
	}
	httpRoute, err := build(profile.Proxy.HTTP)
	if err != nil {
		return nil, err
	}
	httpsRoute, err := build(profile.Proxy.HTTPS)
	if err != nil {
		return nil, err
	}
	return &schemeTransport{http: httpRoute, https: httpsRoute}, nil
}
