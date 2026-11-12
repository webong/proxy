package tcpdial

import (
	"context"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"net"
	"os"
	"strconv"
	"strings"
	"testing"
	"time"
)

func TestHTTPConnectConformance(t *testing.T) {
	data, err := os.ReadFile("../spec/http-connect-cases.json")
	if err != nil {
		t.Fatal(err)
	}
	var raw []struct {
		Name            string `json:"name"`
		ResponseHex     string `json:"response_hex"`
		Host            string `json:"host"`
		Port            int    `json:"port"`
		Credentials     string `json:"credentials"`
		Success         bool   `json:"success"`
		Fragment        bool   `json:"fragment"`
		Large           bool   `json:"large"`
		Stall           bool   `json:"stall"`
		HeaderBytes     int    `json:"header_bytes"`
		DeadlineCleared bool   `json:"deadline_cleared"`
	}
	if err = json.Unmarshal(data, &raw); err != nil {
		t.Fatal(err)
	}
	for _, test := range raw {
		t.Run(test.Name, func(t *testing.T) {
			listener, err := net.Listen("tcp", "127.0.0.1:0")
			if err != nil {
				t.Fatal(err)
			}
			defer listener.Close()
			recorded := make(chan string, 1)
			done := make(chan struct{})
			go func() {
				defer close(done)
				peer, e := listener.Accept()
				if e != nil {
					return
				}
				defer peer.Close()
				peer.SetDeadline(time.Now().Add(2 * time.Second))
				var request strings.Builder
				var b [1]byte
				for !strings.HasSuffix(request.String(), "\r\n\r\n") && request.Len() < 4096 {
					if _, e = io.ReadFull(peer, b[:]); e != nil {
						return
					}
					request.WriteByte(b[0])
				}
				recorded <- request.String()
				if test.Stall {
					var b [1]byte
					peer.Read(b[:])
					return
				}
				response, _ := hex.DecodeString(test.ResponseHex)
				if test.Large {
					response = []byte("HTTP/1.1 200 OK\r\nX: " + strings.Repeat("x", 32768) + "\r\n\r\n")
				}
				if test.HeaderBytes > 0 {
					prefix := "HTTP/1.1 200 OK\r\nX: "
					response = []byte(prefix + strings.Repeat("x", test.HeaderBytes-len(prefix)-4) + "\r\n\r\n")
				}
				if test.Success {
					response = append(response, []byte("banner\x00")...)
				}
				if test.Fragment {
					for _, v := range response {
						if _, e = peer.Write([]byte{v}); e != nil {
							return
						}
					}
				} else {
					peer.Write(response)
				}
				if test.Success {
					var ping [4]byte
					if _, e = io.ReadFull(peer, ping[:]); e == nil {
						peer.Write(ping[:])
					}
				}
			}()
			auth := ""
			if test.Credentials != "" {
				auth = test.Credentials + "@"
			}
			timeout := 1.0
			if test.Stall || test.DeadlineCleared {
				timeout = .03
			}
			d, err := New(Profile{Version: 1, Proxy: "http://" + auth + listener.Addr().String(), ConnectTimeoutSeconds: timeout}, nil, resolverFunc(func(context.Context, string) ([]net.IPAddr, error) {
				t.Error("CONNECT destination resolved locally")
				return nil, errors.New("unexpected DNS")
			}))
			if err != nil {
				t.Fatal(err)
			}
			conn, err := d.DialContext(context.Background(), "tcp", net.JoinHostPort(test.Host, strconv.Itoa(test.Port)))
			if test.Success {
				if err != nil {
					t.Fatal(err)
				}
				defer conn.Close()
				if test.DeadlineCleared {
					time.Sleep(60 * time.Millisecond)
				}
				conn.SetReadDeadline(time.Now().Add(time.Second))
				banner := make([]byte, 7)
				if _, err = io.ReadFull(conn, banner); err != nil || string(banner) != "banner\x00" {
					t.Fatalf("lost tunnel banner: %q %v", banner, err)
				}
				conn.Write([]byte("ping"))
				b := make([]byte, 4)
				if _, err = io.ReadFull(conn, b); err != nil || string(b) != "ping" {
					t.Fatalf("echo: %q %v", b, err)
				}
			} else if err == nil {
				conn.Close()
				t.Fatal("accepted invalid response")
			}
			if test.Stall && !errors.Is(err, context.DeadlineExceeded) {
				t.Fatalf("timeout: %v", err)
			}
			request := <-recorded
			authority := net.JoinHostPort(test.Host, strconv.Itoa(test.Port))
			expected := "CONNECT " + authority + " HTTP/1.1\r\nHost: " + authority + "\r\n"
			if test.Credentials != "" {
				user := d.proxy.User.Username()
				pass, _ := d.proxy.User.Password()
				expected += "Proxy-Authorization: Basic " + base64.StdEncoding.EncodeToString([]byte(user+":"+pass)) + "\r\n"
			}
			if request != expected+"\r\n" {
				t.Fatalf("request mismatch %q", request)
			}
			<-done
		})
	}
}

func TestHTTPConnectCancellationAndDefaultPort(t *testing.T) {
	for _, cancelSetup := range []bool{false, true} {
		t.Run(map[bool]string{false: "default-port", true: "cancel"}[cancelSetup], func(t *testing.T) {
			ctx, cancel := context.WithCancel(context.Background())
			defer cancel()
			local, peer := net.Pipe()
			defer peer.Close()
			d, _ := New(Profile{Version: 1, Proxy: "http://proxy.example"}, baseFunc(func(ctx context.Context, n, a string) (net.Conn, error) {
				if a != "proxy.example:80" {
					t.Errorf("wrong hop %s", a)
				}
				if !cancelSetup {
					return nil, errors.New("stopped")
				}
				return local, nil
			}), nil)
			if !cancelSetup {
				local.Close()
				_, err := d.DialContext(ctx, "tcp", "target.example:80")
				if err == nil {
					t.Fatal("missing connector error")
				}
				return
			}
			go func() { var b [1]byte; peer.Read(b[:]); cancel() }()
			_, err := d.DialContext(ctx, "tcp", "target.example:80")
			if !errors.Is(err, context.Canceled) {
				t.Fatalf("cancel: %v", err)
			}
			peer.SetReadDeadline(time.Now().Add(time.Second))
			b := make([]byte, 1024)
			for {
				_, err = peer.Read(b)
				if err != nil {
					break
				}
			}
			if !errors.Is(err, io.EOF) {
				t.Fatalf("connection leaked: %v", err)
			}
		})
	}
}

func TestConnectRejectionStatusIsSafe(t *testing.T) {
	local, peer := net.Pipe()
	defer peer.Close()
	d, err := New(Profile{Version: 1, Proxy: "http://secret:password@proxy.example"}, baseFunc(func(context.Context, string, string) (net.Conn, error) { return local, nil }), nil)
	if err != nil {
		t.Fatal(err)
	}
	go func() {
		defer peer.Close()
		var b [1]byte
		request := ""
		for !strings.HasSuffix(request, "\r\n\r\n") {
			if _, err := io.ReadFull(peer, b[:]); err != nil {
				return
			}
			request += string(b[:])
		}
		peer.Write([]byte("HTTP/1.1 407 secret-password\r\n\r\n"))
	}()
	_, err = d.DialContext(context.Background(), "tcp", "target.example:443")
	var rejected *ConnectError
	if !errors.As(err, &rejected) || rejected.StatusCode != 407 || strings.Contains(err.Error(), "secret") || strings.Contains(err.Error(), "password") {
		t.Fatalf("unsafe rejection error: %v", err)
	}
}

func TestConnectProtocolErrorsAreSpecificAndPreserveEOF(t *testing.T) {
	for _, test := range []struct {
		name, response string
		eof            bool
	}{
		{"malformed-status", "SOCKS5 is not HTTP\r\n\r\n", false},
		{"malformed-header", "HTTP/1.1 200 OK\r\nBad Header: x\r\n\r\n", false},
		{"bare-lf", "HTTP/1.1 200 OK\n\n", false},
		{"truncated-status", "HTTP/1.1 20", true},
		{"truncated-headers", "HTTP/1.1 200 OK\r\nX: incomplete", true},
	} {
		t.Run(test.name, func(t *testing.T) {
			local, peer := net.Pipe()
			defer peer.Close()
			d, _ := New(Profile{Version: 1, Proxy: "http://proxy.example"}, baseFunc(func(context.Context, string, string) (net.Conn, error) { return local, nil }), nil)
			go func() {
				defer peer.Close()
				request := ""
				var b [1]byte
				for !strings.HasSuffix(request, "\r\n\r\n") {
					if _, err := io.ReadFull(peer, b[:]); err != nil {
						return
					}
					request += string(b[:])
				}
				peer.Write([]byte(test.response))
			}()
			_, err := d.DialContext(context.Background(), "tcp", "target.example:443")
			if !errors.Is(err, ErrConnectProtocol) || errors.Is(err, ErrProtocol) || strings.Contains(err.Error(), "SOCKS") {
				t.Fatalf("wrong protocol error: %v", err)
			}
			if test.eof && !errors.Is(err, io.EOF) {
				t.Fatalf("truncation lost EOF: %v", err)
			}
		})
	}
}
