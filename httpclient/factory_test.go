package httpclient

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"sync/atomic"
	"testing"
	"time"

	"github.com/webong/proxy/httpprofile"
)

func TestProxySelection(t *testing.T) {
	t.Setenv("HTTP_PROXY", "http://ambient.invalid:80")
	p := httpprofile.Profile{Version: 1, Proxy: &httpprofile.Proxy{HTTP: "http://proxy.example:80", HTTPS: "https://secure.example:443", NoProxy: []string{".internal.example", "exact.example"}}, RequestTimeoutSeconds: 2}
	c, err := New(p, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer c.CloseIdleConnections()
	if c.Timeout != 2*time.Second {
		t.Fatal("timeout")
	}
	tr := c.Transport.(*http.Transport)
	for target, want := range map[string]string{"http://public.example": "http://proxy.example:80", "https://public.example": "https://secure.example:443", "http://sub.internal.example": "", "http://internal.example": "http://proxy.example:80", "http://exact.example": ""} {
		req, _ := http.NewRequest("GET", target, nil)
		u, err := tr.Proxy(req)
		if err != nil {
			t.Fatal(err)
		}
		got := ""
		if u != nil {
			got = u.String()
		}
		if got != want {
			t.Fatalf("%s: %s != %s", target, got, want)
		}
	}
	p.Proxy.HTTP = "http://changed.invalid"
	req, _ := http.NewRequest("GET", "http://public.example", nil)
	u, _ := tr.Proxy(req)
	if u.Host != "proxy.example:80" {
		t.Fatal("profile mutation leaked")
	}
	direct, err := New(httpprofile.Profile{Version: 1}, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer direct.CloseIdleConnections()
	if direct.Transport.(*http.Transport).Proxy != nil {
		t.Fatal("ambient proxy inherited")
	}
}
func TestRequestAndBaseIsolation(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.WriteHeader(204) }))
	defer server.Close()
	base := http.DefaultTransport.(*http.Transport).Clone()
	client, err := New(httpprofile.Profile{Version: 1}, base)
	if err != nil {
		t.Fatal(err)
	}
	defer client.CloseIdleConnections()
	if client.Transport == base {
		t.Fatal("base reused")
	}
	response, err := client.Get(server.URL)
	if err != nil {
		t.Fatal(err)
	}
	response.Body.Close()
	if response.StatusCode != 204 {
		t.Fatal(response.StatusCode)
	}
	var reached atomic.Bool
	base.DialContext = func(ctx context.Context, network, address string) (net.Conn, error) {
		reached.Store(true)
		if _, ok := ctx.Deadline(); !ok {
			t.Error("connect deadline missing")
		}
		return nil, errors.New("policy denied")
	}
	guarded, err := New(httpprofile.Profile{Version: 1, ConnectTimeoutSeconds: 2}, base)
	if err != nil {
		t.Fatal(err)
	}
	defer guarded.CloseIdleConnections()
	if _, err = guarded.Get("http://example.test"); err == nil || !reached.Load() {
		t.Fatal("policy dialer was bypassed")
	}
}

func TestSharedBypassRules(t *testing.T) {
	data, err := os.ReadFile("../spec/fixtures/bypass.json")
	if err != nil {
		t.Fatal(err)
	}
	var cases []struct {
		Host  string
		Rule  string
		Match bool
	}
	if err = json.Unmarshal(data, &cases); err != nil {
		t.Fatal(err)
	}
	for _, c := range cases {
		if bypass(c.Host, []string{c.Rule}) != c.Match {
			t.Errorf("%s %s", c.Host, c.Rule)
		}
	}
}

func TestHTTPProxyReceivesRequest(t *testing.T) {
	proxy := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Host != "destination.invalid" {
			t.Errorf("unexpected destination %s", r.URL.Host)
		}
		w.WriteHeader(http.StatusAccepted)
	}))
	defer proxy.Close()
	client, err := New(httpprofile.Profile{Version: 1, Proxy: &httpprofile.Proxy{HTTP: proxy.URL}}, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer client.CloseIdleConnections()
	response, err := client.Get("http://destination.invalid/webhook")
	if err != nil {
		t.Fatal(err)
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusAccepted {
		t.Fatal(response.StatusCode)
	}
}

func TestTimeoutRounding(t *testing.T) {
	for secondsValue, want := range map[float64]time.Duration{1e-12: time.Millisecond, 0.0011: 2 * time.Millisecond, 60: time.Minute} {
		client, err := New(httpprofile.Profile{Version: 1, ConnectTimeoutSeconds: secondsValue, RequestTimeoutSeconds: secondsValue}, nil)
		if err != nil {
			t.Fatal(err)
		}
		if client.Timeout != want || client.Transport.(*http.Transport).TLSHandshakeTimeout != want {
			t.Errorf("%v: incorrect timeout", secondsValue)
		}
		client.CloseIdleConnections()
	}
}

func TestCustomTLSDialerDeadline(t *testing.T) {
	base := http.DefaultTransport.(*http.Transport).Clone()
	var reached atomic.Bool
	base.DialTLSContext = func(ctx context.Context, network, address string) (net.Conn, error) {
		reached.Store(true)
		if deadline, ok := ctx.Deadline(); !ok || time.Until(deadline) > time.Second {
			t.Error("TLS deadline missing")
		}
		return nil, errors.New("TLS policy denied")
	}
	client, err := New(httpprofile.Profile{Version: 1, ConnectTimeoutSeconds: 0.5}, base)
	if err != nil {
		t.Fatal(err)
	}
	defer client.CloseIdleConnections()
	if _, err = client.Get("https://example.test"); err == nil || !reached.Load() {
		t.Fatal("TLS policy dialer bypassed")
	}
	base.DialTLSContext = nil
	base.DialTLS = func(string, string) (net.Conn, error) { return nil, errors.New("legacy") }
	if _, err = New(httpprofile.Profile{Version: 1, ConnectTimeoutSeconds: 1}, base); err == nil {
		t.Fatal("legacy TLS dialer silently accepted a deadline")
	}
}

func TestRequestDeadlineAndCancellation(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { <-r.Context().Done() }))
	defer server.Close()
	client, err := New(httpprofile.Profile{Version: 1, RequestTimeoutSeconds: 0.02}, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer client.CloseIdleConnections()
	if _, err = client.Get(server.URL); !errors.Is(err, context.DeadlineExceeded) {
		t.Fatalf("deadline: %v", err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	request, _ := http.NewRequestWithContext(ctx, "GET", server.URL, nil)
	if _, err = client.Do(request); !errors.Is(err, context.Canceled) {
		t.Fatalf("cancellation: %v", err)
	}
}

func TestHTTPSConnectAndProxyCredentials(t *testing.T) {
	target := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.WriteHeader(http.StatusNoContent) }))
	defer target.Close()
	var authenticated atomic.Bool
	proxy := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodConnect {
			t.Error("expected CONNECT")
			w.WriteHeader(400)
			return
		}
		if r.Header.Get("Proxy-Authorization") == "Basic dXNlcjpwYXNz" {
			authenticated.Store(true)
		}
		upstream, err := net.Dial("tcp", r.Host)
		if err != nil {
			t.Error(err)
			w.WriteHeader(502)
			return
		}
		defer upstream.Close()
		connection, buffer, err := w.(http.Hijacker).Hijack()
		if err != nil {
			t.Error(err)
			return
		}
		defer connection.Close()
		buffer.WriteString("HTTP/1.1 200 Connection Established\r\n\r\n")
		buffer.Flush()
		go io.Copy(upstream, buffer)
		io.Copy(connection, upstream)
	}))
	defer proxy.Close()
	proxyURL, _ := url.Parse(proxy.URL)
	proxyURL.User = url.UserPassword("user", "pass")
	base := target.Client().Transport.(*http.Transport)
	client, err := New(httpprofile.Profile{Version: 1, Proxy: &httpprofile.Proxy{HTTPS: proxyURL.String()}, ConnectTimeoutSeconds: 2, RequestTimeoutSeconds: 2}, base)
	if err != nil {
		t.Fatal(err)
	}
	defer client.CloseIdleConnections()
	response, err := client.Get(target.URL)
	if err != nil {
		t.Fatal(err)
	}
	response.Body.Close()
	if response.StatusCode != 204 || !authenticated.Load() {
		t.Fatal("CONNECT delivery or credentials failed")
	}
}

func TestLegacyDialerNotSilentlyReplaced(t *testing.T) {
	base := &http.Transport{Dial: func(string, string) (net.Conn, error) { return nil, errors.New("policy denied") }}
	if _, err := New(httpprofile.Profile{Version: 1, ConnectTimeoutSeconds: 1}, base); err == nil {
		t.Fatal("legacy Dial silently replaced")
	}
	client, err := New(httpprofile.Profile{Version: 1}, base)
	if err != nil {
		t.Fatal(err)
	}
	defer client.CloseIdleConnections()
	if _, err = client.Get("http://example.test"); err == nil {
		t.Fatal("legacy policy lost")
	}
}

func TestNilBaseIndependentOfGlobalTransport(t *testing.T) {
	previous := http.DefaultTransport
	http.DefaultTransport = &http.Transport{DialContext: func(context.Context, string, string) (net.Conn, error) {
		t.Error("global dialer used")
		return nil, errors.New("global")
	}}
	defer func() { http.DefaultTransport = previous }()
	client, err := New(httpprofile.Profile{Version: 1, ConnectTimeoutSeconds: 60}, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer client.CloseIdleConnections()
	if client.Transport.(*http.Transport).TLSHandshakeTimeout != time.Minute {
		t.Fatal("profile timeout lost")
	}
}
