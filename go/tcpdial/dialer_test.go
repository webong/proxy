package tcpdial

import (
	"context"
	"encoding/json"
	"errors"
	"github.com/webong/proxy/go/internal/sockstest"
	"io"
	"net"
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

type resolverFunc func(context.Context, string) ([]net.IPAddr, error)

func (f resolverFunc) LookupIPAddr(ctx context.Context, host string) ([]net.IPAddr, error) {
	return f(ctx, host)
}

type baseFunc func(context.Context, string, string) (net.Conn, error)

func (f baseFunc) DialContext(ctx context.Context, n, a string) (net.Conn, error) {
	return f(ctx, n, a)
}
func TestProfiles(t *testing.T) {
	for _, group := range []string{"valid", "invalid"} {
		files, err := filepath.Glob("../testdata/spec/tcp/" + group + "/*.json")
		if err != nil || len(files) == 0 {
			t.Fatalf("missing conformance fixtures: %v", err)
		}
		for _, file := range files {
			t.Run(file, func(t *testing.T) {
				data, err := os.ReadFile(file)
				if err != nil {
					t.Fatal(err)
				}
				p, err := Parse(data)
				if (err == nil) != (group == "valid") {
					t.Fatal(err)
				}
				var other Profile
				if err = json.Unmarshal(data, &other); (err == nil) != (group == "valid") {
					t.Fatal(err)
				}
				if group == "valid" {
					encoded, err := json.Marshal(p)
					if err != nil {
						t.Fatal(err)
					}
					if _, err = Parse(encoded); err != nil {
						t.Fatal(err)
					}
				}
			})
		}
	}
}
func TestConformance(t *testing.T) {
	for _, c := range sockstest.Load(t, "../testdata/spec/socks5-cases.json") {
		t.Run(c.Name, func(t *testing.T) {
			server := sockstest.Start(t, c)
			username, password := c.Username, c.Password
			if username == "" {
				username = "user"
			}
			if password == "" {
				password = "p@ss"
			}
			credentials := ""
			if c.Credentials {
				credentials = url.UserPassword(username, password).String() + "@"
			}
			timeout := c.Timeout
			if timeout == 0 {
				timeout = 2
			}
			var lookups atomic.Int32
			resolver := resolverFunc(func(ctx context.Context, host string) ([]net.IPAddr, error) {
				lookups.Add(1)
				ip := c.ResolvedIP
				if ip == "" {
					ip = "192.0.2.10"
				}
				return []net.IPAddr{{IP: net.ParseIP(ip)}}, nil
			})
			d, err := New(Profile{Version: 1, Proxy: c.Scheme + "://" + credentials + server.Address, ConnectTimeoutSeconds: timeout}, nil, resolver)
			if err != nil {
				t.Fatal(err)
			}
			conn, err := d.DialContext(context.Background(), "tcp", net.JoinHostPort(c.Host, "443"))
			if !c.Success {
				if c.Name == "timeout" && !errors.Is(err, context.DeadlineExceeded) {
					t.Fatalf("setup timeout not classified: %v", err)
				}
				if err == nil {
					conn.Close()
					t.Fatal("expected failure")
				}
				if strings.Contains(err.Error(), "p@ss") {
					t.Fatal("credentials leaked")
				}
				return
			}
			if err != nil {
				t.Fatal(err)
			}
			// Application I/O is independent of the setup timeout.
			if _, err = conn.Write([]byte("ping")); err != nil {
				t.Fatal(err)
			}
			var reply [4]byte
			if _, err = io.ReadFull(conn, reply[:]); err != nil {
				t.Fatal(err)
			}
			conn.Close()
			if string(reply[:]) != "ping" {
				t.Fatal("echo failed")
			}
			record := server.Record()
			if record.ATYP != c.ATYP || record.Host != c.WireHost || record.Port != c.Port {
				t.Fatalf("record: %+v", record)
			}
			if c.Credentials && (record.User != username || record.Password != password) {
				t.Fatal("wrong credentials")
			}
			want := int32(0)
			if c.Scheme == "socks5" && net.ParseIP(c.Host) == nil {
				want = 1
			}
			if lookups.Load() != want {
				t.Fatal("DNS mode not honored")
			}
		})
	}
}
func TestDirectAndInvalidDestination(t *testing.T) {
	server := sockstest.Start(t, sockstest.Case{Mode: "direct"})
	d, _ := New(Profile{Version: 1}, nil, nil)
	conn, err := d.DialContext(context.Background(), "tcp", server.Address)
	if err != nil {
		t.Fatal(err)
	}
	conn.Write([]byte("ping"))
	reply := make([]byte, 4)
	_, err = io.ReadFull(conn, reply)
	conn.Close()
	if err != nil || string(reply) != "ping" {
		t.Fatal(err)
	}
	for _, address := range []string{"host:0", "host:65536", "bad host:80", "::1:80", "host:http"} {
		if _, err = d.DialContext(context.Background(), "tcp", address); err == nil {
			t.Fatal(address)
		}
	}
	if _, err = d.DialContext(context.Background(), "udp", server.Address); err == nil {
		t.Fatal("UDP accepted")
	}
}
func TestCancellationAndResolverFailure(t *testing.T) {
	c := sockstest.Case{Stall: true}
	server := sockstest.Start(t, c)
	d, _ := New(Profile{Version: 1, Proxy: "socks5h://" + server.Address}, nil, nil)
	ctx, cancel := context.WithCancel(context.Background())
	time.AfterFunc(20*time.Millisecond, cancel)
	if _, err := d.DialContext(ctx, "tcp", "example.test:443"); !errors.Is(err, context.Canceled) {
		t.Fatalf("cancel: %v", err)
	}
	var reached atomic.Bool
	base := baseFunc(func(context.Context, string, string) (net.Conn, error) {
		reached.Store(true)
		return nil, errors.New("unexpected dial")
	})
	resolver := resolverFunc(func(context.Context, string) ([]net.IPAddr, error) { return nil, errors.New("DNS failed") })
	d, _ = New(Profile{Version: 1, Proxy: "socks5://proxy.example"}, base, resolver)
	if _, err := d.DialContext(context.Background(), "tcp", "example.test:443"); err == nil || reached.Load() {
		t.Fatal("DNS failure leaked into a proxy connection")
	}
}

func TestSetupDeadlineCleared(t *testing.T) {
	c := sockstest.Case{Greeting: "0500", Reply: "05000001000000000000", Success: true}
	server := sockstest.Start(t, c)
	d, _ := New(Profile{Version: 1, Proxy: "socks5h://" + server.Address, ConnectTimeoutSeconds: 0.1}, nil, nil)
	conn, err := d.DialContext(context.Background(), "tcp", "example.test:80")
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	time.Sleep(150 * time.Millisecond)
	if _, err = conn.Write([]byte("ping")); err != nil {
		t.Fatal("setup deadline retained", err)
	}
	if _, err = io.ReadFull(conn, make([]byte, 4)); err != nil {
		t.Fatal(err)
	}
}

type watchedConn struct {
	net.Conn
	closed atomic.Bool
}

func (c *watchedConn) Close() error { c.closed.Store(true); return c.Conn.Close() }
func (c *watchedConn) Write(data []byte) (int, error) {
	if len(data) > 1 {
		data = data[:1]
	}
	return c.Conn.Write(data)
}
func TestPartialWritesAndFailureCleanup(t *testing.T) {
	for _, success := range []bool{true, false} {
		t.Run(strconv.FormatBool(success), func(t *testing.T) {
			c := sockstest.Case{Greeting: "0500", Reply: "05000001000000000000", Success: true}
			if !success {
				c.Greeting = "0400"
				c.Success = false
			}
			server := sockstest.Start(t, c)
			var captured *watchedConn
			base := baseFunc(func(ctx context.Context, n, a string) (net.Conn, error) {
				conn, err := (&net.Dialer{}).DialContext(ctx, n, a)
				if err != nil {
					return nil, err
				}
				captured = &watchedConn{Conn: conn}
				return captured, nil
			})
			d, _ := New(Profile{Version: 1, Proxy: "socks5h://" + server.Address}, base, nil)
			conn, err := d.DialContext(context.Background(), "tcp", "example.test:80")
			if !success {
				if err == nil || !captured.closed.Load() {
					t.Fatal("failure did not close connection")
				}
				return
			}
			if err != nil {
				t.Fatal(err)
			}
			if err = writeAll(conn, []byte("ping")); err != nil {
				t.Fatal(err)
			}
			if _, err = io.ReadFull(conn, make([]byte, 4)); err != nil {
				t.Fatal(err)
			}
			conn.Close()
		})
	}
}

func TestInvalidBaseResultsAreClosed(t *testing.T) {
	for _, proxy := range []string{"", "socks5h://proxy.example"} {
		for _, mode := range []string{"nil", "error", "late"} {
			t.Run(proxy+mode, func(t *testing.T) {
				var captured *watchedConn
				base := baseFunc(func(context.Context, string, string) (net.Conn, error) {
					if mode == "nil" {
						return nil, nil
					}
					conn, peer := net.Pipe()
					defer peer.Close()
					captured = &watchedConn{Conn: conn}
					if mode == "late" {
						time.Sleep(10 * time.Millisecond)
						return captured, nil
					}
					return captured, errors.New("dial failed")
				})
				d, _ := New(Profile{Version: 1, Proxy: proxy, ConnectTimeoutSeconds: 0.001}, base, nil)
				if conn, err := d.DialContext(context.Background(), "tcp", "example.test:443"); err == nil || conn != nil {
					t.Fatal("invalid result accepted")
				}
				if captured != nil && !captured.closed.Load() {
					t.Fatal("discarded connection leaked")
				}
			})
		}
	}
}

func TestDefaultProxyPort(t *testing.T) {
	base := baseFunc(func(ctx context.Context, network, address string) (net.Conn, error) {
		if address != "proxy.example:1080" {
			t.Error(address)
		}
		return nil, errors.New("fixture connector stopped")
	})
	resolver := resolverFunc(func(context.Context, string) ([]net.IPAddr, error) {
		t.Error("remote destination resolved locally")
		return nil, nil
	})
	d, _ := New(Profile{Version: 1, Proxy: "socks5h://proxy.example"}, base, resolver)
	if _, err := d.DialContext(context.Background(), "tcp", "unresolved.example:80"); err == nil {
		t.Fatal("expected connector error")
	}
}
