package contract_test

import (
	"context"
	"errors"
	"io"
	"net"
	"testing"
	"time"

	"github.com/webong/proxy/tcpdial"
	"github.com/webong/proxy/udpdial"
)

type greetingPeer func(context.Context, string, string) (net.Conn, error)

func (f greetingPeer) DialContext(ctx context.Context, network, address string) (net.Conn, error) {
	return f(ctx, network, address)
}

func TestPublicSOCKSErrorMatching(t *testing.T) {
	for _, test := range []struct {
		name, proxy        string
		reply              []byte
		tcpError, udpError error
	}{
		{"protocol", "socks5h://proxy.example", []byte{4, 0}, tcpdial.ErrProtocol, udpdial.ErrProtocol},
		{"authentication", "socks5h://user:pass@proxy.example", []byte{5, 0}, tcpdial.ErrAuthentication, udpdial.ErrAuthentication},
	} {
		for _, transport := range []string{"tcp", "udp"} {
			t.Run(transport+"/"+test.name, func(t *testing.T) {
				peer := greetingPeer(func(context.Context, string, string) (net.Conn, error) {
					client, server := net.Pipe()
					go func() {
						defer server.Close()
						server.SetDeadline(time.Now().Add(time.Second))
						var greeting [3]byte
						if _, err := io.ReadFull(server, greeting[:]); err == nil {
							server.Write(test.reply)
						}
					}()
					return client, nil
				})
				var err error
				want := test.tcpError
				if transport == "tcp" {
					dialer, e := tcpdial.New(tcpdial.Profile{Version: 1, Proxy: test.proxy}, peer, nil)
					if e != nil {
						t.Fatal(e)
					}
					conn, e := dialer.DialContext(context.Background(), "tcp", "service.example:9000")
					if conn != nil {
						conn.Close()
					}
					err = e
				} else {
					want = test.udpError
					dialer, e := udpdial.New(udpdial.Profile{Version: 1, Proxy: test.proxy}, peer, nil)
					if e != nil {
						t.Fatal(e)
					}
					conn, e := dialer.Open(context.Background(), "")
					if conn != nil {
						conn.Close()
					}
					err = e
				}
				if !errors.Is(err, want) {
					t.Fatalf("public sentinel does not match actual failure: %v", err)
				}
			})
		}
	}
}

func TestPublicDatagramErrorMatching(t *testing.T) {
	if _, _, err := udpdial.DecodeDatagram(nil); !errors.Is(err, udpdial.ErrProtocol) {
		t.Fatalf("protocol matching: %v", err)
	}
	if _, _, err := udpdial.DecodeDatagram([]byte{0, 0, 1, 1, 127, 0, 0, 1, 0, 53}); !errors.Is(err, udpdial.ErrFragment) {
		t.Fatalf("fragment matching: %v", err)
	}
	if _, err := udpdial.EncodeDatagram(make([]byte, udpdial.MaxWireSize), udpdial.Address{Host: "127.0.0.1", Port: 53}); !errors.Is(err, udpdial.ErrMessageTooLarge) {
		t.Fatalf("size matching: %v", err)
	}
}
