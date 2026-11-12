package tcpdial

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"errors"
	"io"
	"net"
	"os"
	"strings"
	"testing"
	"time"
)

func proxyTestRoots(t *testing.T) *x509.CertPool {
	t.Helper()
	pem, err := os.ReadFile("../tests/fixtures/tls/ca.pem")
	if err != nil {
		t.Fatal(err)
	}
	roots := x509.NewCertPool()
	if !roots.AppendCertsFromPEM(pem) {
		t.Fatal("bad CA fixture")
	}
	return roots
}
func TestHTTPSProxyVerification(t *testing.T) {
	for _, test := range []struct {
		name, host, leaf string
		trust, nested    bool
	}{
		{"trusted-dns", "proxy.test", "proxy", true, false},
		{"trusted-root-dot", "proxy.test.", "proxy", true, false},
		{"trusted-ip", "127.0.0.1", "proxy", true, false},
		{"trusted-ipv6", "::1", "proxy", true, false},
		{"trusted-mapped-ip", "::ffff:127.0.0.1", "proxy", true, false},
		{"untrusted", "proxy.test", "proxy", false, false},
		{"wrong-host", "wrong.test", "proxy", true, false},
		{"expired", "proxy.test", "expired", true, false},
		{"nested-target-tls", "proxy.test", "proxy", true, true},
	} {
		t.Run(test.name, func(t *testing.T) {
			cert, err := tls.LoadX509KeyPair("../tests/fixtures/tls/"+test.leaf+".pem", "../tests/fixtures/tls/"+test.leaf+"-key.pem")
			if err != nil {
				t.Fatal(err)
			}
			listenAddress := "127.0.0.1:0"
			if test.host == "::1" {
				listenAddress = "[::1]:0"
			}
			listener, err := net.Listen("tcp", listenAddress)
			if err != nil {
				t.Fatal(err)
			}
			defer listener.Close()
			recorded := make(chan string, 1)
			hello := make(chan *tls.ClientHelloInfo, 1)
			go func() {
				request := ""
				defer func() { recorded <- request }()
				raw, e := listener.Accept()
				if e != nil {
					return
				}
				defer raw.Close()
				raw.SetDeadline(time.Now().Add(3 * time.Second))
				config := &tls.Config{Certificates: []tls.Certificate{cert}, MinVersion: tls.VersionTLS12, NextProtos: []string{"http/1.1"}, GetConfigForClient: func(info *tls.ClientHelloInfo) (*tls.Config, error) { hello <- info; return nil, nil }}
				conn := tls.Server(raw, config)
				if e = conn.Handshake(); e != nil {
					return
				}
				var b [1]byte
				for !strings.HasSuffix(request, "\r\n\r\n") {
					if _, e = io.ReadFull(conn, b[:]); e != nil {
						return
					}
					request += string(b[:])
				}
				conn.Write([]byte("HTTP/1.1 200 OK\r\n\r\n"))
				if test.nested {
					conn = tls.Server(conn, &tls.Config{Certificates: []tls.Certificate{cert}, MinVersion: tls.VersionTLS12})
					if e = conn.Handshake(); e != nil {
						return
					}
				}
				conn.Write([]byte("secure\x00"))
				var ping [4]byte
				if _, e = io.ReadFull(conn, ping[:]); e == nil {
					conn.Write(ping[:])
				}
			}()
			options := []Option{}
			if test.trust {
				options = append(options, WithProxyRootCAs(proxyTestRoots(t)))
			}
			proxyHost := test.host
			if strings.Contains(proxyHost, ":") {
				proxyHost = "[" + proxyHost + "]"
			}
			d, err := New(Profile{Version: 1, Proxy: "https://user:pass@" + proxyHost, ConnectTimeoutSeconds: 1}, baseFunc(func(ctx context.Context, n, a string) (net.Conn, error) {
				if a != net.JoinHostPort(test.host, "443") {
					t.Errorf("wrong HTTPS hop %q", a)
				}
				return (&net.Dialer{}).DialContext(ctx, n, listener.Addr().String())
			}), resolverFunc(func(context.Context, string) ([]net.IPAddr, error) {
				t.Error("target DNS invoked")
				return nil, errors.New("unexpected DNS")
			}), options...)
			if err != nil {
				t.Fatal(err)
			}
			ctx, cancel := context.WithCancel(context.Background())
			defer cancel()
			conn, err := d.DialContext(ctx, "tcp", "proxy.test:9000")
			success := test.trust && test.host != "wrong.test" && test.leaf != "expired"
			if success {
				if err != nil {
					t.Fatal(err)
				}
				defer conn.Close()
				cancel() // setup context must no longer own the tunnel
				if test.nested {
					secure := tls.Client(conn, &tls.Config{RootCAs: proxyTestRoots(t), ServerName: "proxy.test", MinVersion: tls.VersionTLS12})
					if err = secure.HandshakeContext(context.Background()); err != nil {
						t.Fatal(err)
					}
					conn = secure
				}
				conn.SetDeadline(time.Now().Add(time.Second))
				banner := make([]byte, 7)
				if _, err = io.ReadFull(conn, banner); err != nil || string(banner) != "secure\x00" {
					t.Fatalf("secure banner %q %v", banner, err)
				}
				conn.Write([]byte("ping"))
				var ping [4]byte
				if _, err = io.ReadFull(conn, ping[:]); err != nil || string(ping[:]) != "ping" {
					t.Fatalf("echo %q %v", ping, err)
				}
			} else if err == nil {
				conn.Close()
				t.Fatal("accepted invalid proxy certificate")
			}
			request := <-recorded
			if !success && request != "" {
				t.Fatal("CONNECT credentials sent before TLS verification")
			}
			if success && !strings.Contains(request, "Proxy-Authorization: Basic dXNlcjpwYXNz\r\n") {
				t.Fatal("missing authenticated CONNECT")
			}
			info := <-hello
			expectedSNI := strings.TrimSuffix(test.host, ".")
			if net.ParseIP(test.host) != nil {
				expectedSNI = ""
			}
			if info.ServerName != expectedSNI {
				t.Fatalf("SNI %q", info.ServerName)
			}
			if len(info.SupportedProtos) != 1 || info.SupportedProtos[0] != "http/1.1" {
				t.Fatalf("ALPN %v", info.SupportedProtos)
			}
		})
	}
}

func TestHTTPSProxyHandshakeCancellationAndTimeout(t *testing.T) {
	for _, cancelled := range []bool{false, true} {
		t.Run(map[bool]string{false: "timeout", true: "cancel"}[cancelled], func(t *testing.T) {
			local, peer := net.Pipe()
			defer peer.Close()
			ctx, cancel := context.WithCancel(context.Background())
			defer cancel()
			closed := make(chan error, 1)
			go func() {
				var first [1]byte
				_, err := io.ReadFull(peer, first[:])
				if err == nil && first[0] != 22 {
					t.Errorf("cleartext sent to HTTPS proxy")
				}
				if cancelled {
					cancel()
				}
				_, err = io.Copy(io.Discard, peer)
				closed <- err
			}()
			d, _ := New(Profile{Version: 1, Proxy: "https://user:pass@proxy.test", ConnectTimeoutSeconds: .03}, baseFunc(func(context.Context, string, string) (net.Conn, error) { return local, nil }), nil)
			_, err := d.DialContext(ctx, "tcp", "target.test:443")
			want := context.DeadlineExceeded
			if cancelled {
				want = context.Canceled
			}
			if !errors.Is(err, want) {
				t.Fatalf("setup error %v", err)
			}
			select {
			case <-closed:
			case <-time.After(time.Second):
				t.Fatal("TLS setup leaked raw connection")
			}
		})
	}
}

func TestProxyRootOptionsSnapshot(t *testing.T) {
	roots := proxyTestRoots(t)
	d, err := New(Profile{Version: 1}, nil, nil, WithProxyRootCAs(roots))
	if err != nil {
		t.Fatal(err)
	}
	if d.proxyRoots == roots || !d.proxyRoots.Equal(roots) {
		t.Fatal("CA roots were not snapshotted")
	}
	if _, err = New(Profile{Version: 1}, nil, nil, WithProxyRootCAs(nil)); err == nil {
		t.Fatal("nil roots accepted")
	}
	if _, err = New(Profile{Version: 1}, nil, nil, nil); err == nil {
		t.Fatal("nil option accepted")
	}
}

func TestHTTPSRejectionCleanupDoesNotWaitForTLSCloseNotify(t *testing.T) {
	local, peer := net.Pipe()
	defer peer.Close()
	release := make(chan struct{})
	defer close(release)
	cert, err := tls.LoadX509KeyPair("../tests/fixtures/tls/proxy.pem", "../tests/fixtures/tls/proxy-key.pem")
	if err != nil {
		t.Fatal(err)
	}
	go func() {
		server := tls.Server(peer, &tls.Config{Certificates: []tls.Certificate{cert}, MinVersion: tls.VersionTLS12, MaxVersion: tls.VersionTLS12, SessionTicketsDisabled: true})
		request := ""
		var b [1]byte
		for !strings.HasSuffix(request, "\r\n\r\n") {
			if _, err := io.ReadFull(server, b[:]); err != nil {
				return
			}
			request += string(b[:])
		}
		server.Write([]byte("HTTP/1.1 407 Denied\r\n\r\n"))
		<-release
	}()
	d, _ := New(Profile{Version: 1, Proxy: "https://proxy.test", ConnectTimeoutSeconds: 1}, baseFunc(func(context.Context, string, string) (net.Conn, error) { return local, nil }), nil, WithProxyRootCAs(proxyTestRoots(t)))
	start := time.Now()
	_, err = d.DialContext(context.Background(), "tcp", "target.test:443")
	var rejected *ConnectError
	if !errors.As(err, &rejected) || rejected.StatusCode != 407 {
		t.Fatalf("rejection: %v", err)
	}
	if time.Since(start) > time.Second {
		t.Fatal("failure cleanup waited for TLS close-notify")
	}
}
