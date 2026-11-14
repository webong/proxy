package udpdial

import (
	"context"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"net"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/webong/proxy/go/internal/udptest"
)

type resolverFunc func(context.Context, string) ([]net.IPAddr, error)

func (f resolverFunc) LookupIPAddr(c context.Context, h string) ([]net.IPAddr, error) { return f(c, h) }
func TestProfiles(t *testing.T) {
	for _, group := range []string{"valid", "invalid"} {
		files, err := filepath.Glob("../testdata/spec/udp/" + group + "/*.json")
		if err != nil || len(files) == 0 {
			t.Fatalf("missing conformance fixtures: %v", err)
		}
		for _, f := range files {
			data, err := os.ReadFile(f)
			if err != nil {
				t.Fatal(err)
			}
			_, err = Parse(data)
			if (err == nil) != (group == "valid") {
				t.Fatalf("%s: %v", f, err)
			}
		}
	}
}
func TestSharedFrames(t *testing.T) {
	data, err := os.ReadFile("../testdata/spec/udp-datagrams.json")
	if err != nil {
		t.Fatal(err)
	}
	var cases []struct {
		Name, Host, Payload, Wire, DecodedHost string
		Port                                   int
		Valid                                  bool
		DecodeOnly                             bool `json:"decode_only"`
	}
	// The fixture uses snake_case for one non-native field.
	var raw []map[string]json.RawMessage
	json.Unmarshal(data, &raw)
	json.Unmarshal(data, &cases)
	for i := range cases {
		json.Unmarshal(raw[i]["decoded_host"], &cases[i].DecodedHost)
	}
	for _, c := range cases {
		t.Run(c.Name, func(t *testing.T) {
			wire, _ := hex.DecodeString(c.Wire)
			payload, address, err := DecodeDatagram(wire)
			if !c.Valid {
				if err == nil {
					t.Fatal("malformed packet accepted")
				}
				return
			}
			if err != nil {
				t.Fatal(err)
			}
			if hex.EncodeToString(payload) != c.Payload || address.Host != c.DecodedHost || address.Port != c.Port {
				t.Fatalf("decoded %+v %x", address, payload)
			}
			if !c.DecodeOnly {
				encoded, err := EncodeDatagram(payload, Address{Host: c.Host, Port: c.Port})
				if err != nil || hex.EncodeToString(encoded) != c.Wire {
					t.Fatalf("encode %x %v", encoded, err)
				}
			}
		})
	}
	for _, host := range []string{"192.0.2.1", "2001:db8::1", "unresolved.example"} {
		header, _ := EncodeDatagram(nil, Address{Host: host, Port: 53})
		maxPayload := MaxWireSize - len(header)
		if _, err = EncodeDatagram(make([]byte, maxPayload), Address{Host: host, Port: 53}); err != nil {
			t.Fatal(err)
		}
		if _, err = EncodeDatagram(make([]byte, maxPayload+1), Address{Host: host, Port: 53}); !errors.Is(err, ErrMessageTooLarge) {
			t.Fatal(err)
		}
	}
}
func TestDirectDatagrams(t *testing.T) {
	server := udptest.Start(t, udptest.Config{Direct: true, Count: 3})
	d, _ := New(Profile{Version: 1}, nil, nil)
	c, err := d.Open(context.Background(), "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer c.Close()
	target, _ := net.ResolveUDPAddr("udp", server.Address)
	c.SetDeadline(time.Now().Add(time.Second))
	for _, payload := range [][]byte{{}, {0, 255, 1}, []byte("third")} {
		if n, err := c.WriteTo(payload, target); err != nil || n != len(payload) {
			t.Fatal(n, err)
		}
		buffer := make([]byte, 64)
		n, source, err := c.ReadFrom(buffer)
		if err != nil || string(buffer[:n]) != string(payload) || source.String() != target.String() {
			t.Fatal(n, source, err)
		}
	}
	c.Close()
	c.Close()
	select {
	case <-c.Done():
	default:
		t.Fatal("close not signaled")
	}
}
func TestSOCKSAssociations(t *testing.T) {
	for _, mode := range []struct{ scheme, relay string }{{"socks5h", "literal"}, {"socks5", "wildcard"}, {"socks5h", "domain"}} {
		t.Run(mode.scheme+mode.relay, func(t *testing.T) {
			server := udptest.Start(t, udptest.Config{Auth: true, Relay: mode.relay, Inject: true, FragmentReply: true})
			lookups := []string{}
			resolver := resolverFunc(func(ctx context.Context, h string) ([]net.IPAddr, error) {
				lookups = append(lookups, h)
				return []net.IPAddr{{IP: net.IPv4(127, 0, 0, 1)}}, nil
			})
			d, err := New(Profile{Version: 1, Proxy: mode.scheme + "://user:p%40ss@" + server.Address}, nil, resolver)
			if err != nil {
				t.Fatal(err)
			}
			c, err := d.Open(context.Background(), "")
			if err != nil {
				t.Fatal(err)
			}
			defer c.Close()
			c.SetDeadline(time.Now().Add(time.Second))
			for _, payload := range [][]byte{{}, {0, 255, 1}, []byte("third")} {
				if _, err = c.WriteTo(payload, Address{Host: "unresolved.example", Port: 5353}); err != nil {
					t.Fatal(err)
				}
				buf := make([]byte, 64)
				n, _, err := c.ReadFrom(buf)
				if err != nil || string(buf[:n]) != string(payload) {
					t.Fatalf("packet %x %v", buf[:n], err)
				}
			}
			c.Close()
			record := server.Record()
			if record.Command != "050300" || record.Request.Host != "0.0.0.0" || record.Request.Port != 0 || !record.ControlClosed || record.User != "user" || record.Password != "p@ss" {
				t.Fatalf("association: %+v", record)
			}
			wantType := byte(3)
			if mode.scheme == "socks5" {
				wantType = 1
			}
			for _, packet := range record.Packets {
				if packet.ATYP != wantType {
					t.Fatal(packet)
				}
			}
			targetLookup := false
			for _, h := range lookups {
				if h == "unresolved.example" {
					targetLookup = true
				}
			}
			if targetLookup != (mode.scheme == "socks5") {
				t.Fatal("DNS mode")
			}
		})
	}
}
func TestControlClosureTimeoutAndShortBuffer(t *testing.T) {
	t.Run("control", func(t *testing.T) {
		server := udptest.Start(t, udptest.Config{CloseOnPacket: true})
		d, _ := New(Profile{Version: 1, Proxy: "socks5h://" + server.Address}, nil, nil)
		c, err := d.Open(context.Background(), "")
		if err != nil {
			t.Fatal(err)
		}
		defer c.Close()
		c.SetReadDeadline(time.Now().Add(time.Second))
		c.WriteTo([]byte("x"), Address{Host: "example.test", Port: 53})
		if _, _, err = c.ReadFrom(make([]byte, 64)); !errors.Is(err, net.ErrClosed) {
			t.Fatal(err)
		}
		select {
		case <-c.Done():
		case <-time.After(time.Second):
			t.Fatal("control closure not propagated")
		}
	})
	t.Run("timeout", func(t *testing.T) {
		server := udptest.Start(t, udptest.Config{Drop: true})
		d, _ := New(Profile{Version: 1, Proxy: "socks5h://" + server.Address}, nil, nil)
		c, err := d.Open(context.Background(), "")
		if err != nil {
			t.Fatal(err)
		}
		defer c.Close()
		c.SetReadDeadline(time.Now().Add(30 * time.Millisecond))
		c.WriteTo([]byte("x"), Address{Host: "example.test", Port: 53})
		if _, _, err = c.ReadFrom(make([]byte, 64)); err == nil {
			t.Fatal("no timeout")
		}
	})
	t.Run("short-buffer", func(t *testing.T) {
		server := udptest.Start(t, udptest.Config{Direct: true})
		d, _ := New(Profile{Version: 1}, nil, nil)
		c, err := d.Open(context.Background(), "127.0.0.1:0")
		if err != nil {
			t.Fatal(err)
		}
		defer c.Close()
		addr, _ := net.ResolveUDPAddr("udp", server.Address)
		c.SetDeadline(time.Now().Add(time.Second))
		c.WriteTo([]byte("long"), addr)
		if n, _, err := c.ReadFrom(make([]byte, 1)); n != 1 || !errors.Is(err, io.ErrShortBuffer) {
			t.Fatal(n, err)
		}
	})
}
func TestSetupFailures(t *testing.T) {
	for _, config := range []udptest.Config{{Status: 7}, {ZeroPort: true}, {Auth: true, BadAuth: true}, {Stall: true}} {
		server := udptest.Start(t, config)
		auth := ""
		if config.Auth {
			auth = "user:pass@"
		}
		d, _ := New(Profile{Version: 1, Proxy: "socks5h://" + auth + server.Address, ConnectTimeoutSeconds: 0.1}, nil, nil)
		if c, err := d.Open(context.Background(), ""); err == nil {
			c.Close()
			t.Fatal("expected setup error")
		}
	}
}

func TestIPv6DirectAndRelay(t *testing.T) {
	for _, proxied := range []bool{false, true} {
		t.Run(map[bool]string{false: "direct", true: "SOCKS"}[proxied], func(t *testing.T) {
			server := udptest.Start(t, udptest.Config{Direct: !proxied, IPv6: true})
			profile := Profile{Version: 1}
			bind := "[::1]:0"
			if proxied {
				profile.Proxy = "socks5h://" + server.Address
				bind = ""
			}
			d, _ := New(profile, nil, nil)
			c, err := d.Open(context.Background(), bind)
			if err != nil {
				t.Fatal(err)
			}
			defer c.Close()
			c.SetDeadline(time.Now().Add(time.Second))
			var address net.Addr = Address{Host: "2001:db8::1", Port: 9000}
			if !proxied {
				address, _ = net.ResolveUDPAddr("udp", server.Address)
			}
			if _, err = c.WriteTo([]byte("v6"), address); err != nil {
				t.Fatal(err)
			}
			buf := make([]byte, 8)
			n, _, err := c.ReadFrom(buf)
			if err != nil || string(buf[:n]) != "v6" {
				t.Fatal(n, err)
			}
			c.Close()
			record := server.Record()
			if proxied && record.Packets[0].ATYP != 4 {
				t.Fatal(record)
			}
		})
	}
}
func TestCancelSetupAndInvalidBind(t *testing.T) {
	server := udptest.Start(t, udptest.Config{Stall: true})
	d, _ := New(Profile{Version: 1, Proxy: "socks5h://" + server.Address}, nil, nil)
	ctx, cancel := context.WithCancel(context.Background())
	time.AfterFunc(20*time.Millisecond, cancel)
	if _, err := d.Open(ctx, ""); !errors.Is(err, context.Canceled) {
		t.Fatal(err)
	}
	for _, bind := range []string{"localhost:0", "127.0.0.1:-1", "127.0.0.1:+1", "127.0.0.1:65536"} {
		if _, err := d.Open(context.Background(), bind); err == nil {
			t.Fatal(bind)
		}
	}
}
func TestMaximumAndInvalidDestinations(t *testing.T) {
	d, _ := New(Profile{Version: 1}, nil, nil)
	c, err := d.Open(context.Background(), "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer c.Close()
	if _, err = c.WriteTo(make([]byte, MaxWireSize+1), Address{Host: "127.0.0.1", Port: 53}); !errors.Is(err, ErrMessageTooLarge) {
		t.Fatal(err)
	}
	for _, a := range []Address{{Host: "bad host", Port: 53}, {Host: "::1%zone", Port: 53}, {Host: "127.0.0.1", Port: 0}} {
		if _, err = c.WriteTo(nil, a); err == nil {
			t.Fatal(a)
		}
	}
	for _, host := range []string{"192.0.2.1", "2001:db8::1", "unresolved.example"} {
		max, err := MaxPayloadSize(Address{Host: host, Port: 53})
		if err != nil {
			t.Fatal(err)
		}
		frame, err := EncodeDatagram(make([]byte, max), Address{Host: host, Port: 53})
		if err != nil || len(frame) != MaxWireSize {
			t.Fatal(err)
		}
	}
}

func TestDefaultDirectIPv4Hostname(t *testing.T) {
	server := udptest.Start(t, udptest.Config{Direct: true})
	resolver := resolverFunc(func(context.Context, string) ([]net.IPAddr, error) {
		return []net.IPAddr{{IP: net.IPv4(127, 0, 0, 1)}}, nil
	})
	d, _ := New(Profile{Version: 1}, nil, resolver)
	c, err := d.Open(context.Background(), "")
	if err != nil {
		t.Fatal(err)
	}
	defer c.Close()
	if c.LocalAddr().(*net.UDPAddr).IP.To4() == nil {
		t.Fatal("default socket is not IPv4")
	}
	target, _ := net.ResolveUDPAddr("udp", server.Address)
	c.SetDeadline(time.Now().Add(time.Second))
	if _, err = c.WriteTo([]byte("default"), Address{Host: "ipv4-only.example", Port: target.Port}); err != nil {
		t.Fatal(err)
	}
	if n, _, err := c.ReadFrom(make([]byte, 16)); err != nil || n != 7 {
		t.Fatal(n, err)
	}
}
func TestPendingDNSRespectsCloseAndDeadlineUpdates(t *testing.T) {
	for _, action := range []string{"close", "write-deadline", "both-deadline"} {
		t.Run(action, func(t *testing.T) {
			entered := make(chan struct{})
			resolver := resolverFunc(func(ctx context.Context, _ string) ([]net.IPAddr, error) {
				close(entered)
				<-ctx.Done()
				return nil, ctx.Err()
			})
			d, _ := New(Profile{Version: 1}, nil, resolver)
			c, err := d.Open(context.Background(), "127.0.0.1:0")
			if err != nil {
				t.Fatal(err)
			}
			defer c.Close()
			result := make(chan error, 1)
			go func() { _, err := c.WriteTo([]byte("x"), Address{Host: "blocked.example", Port: 53}); result <- err }()
			<-entered
			if action == "close" {
				c.Close()
			} else if action == "write-deadline" {
				c.SetWriteDeadline(time.Now().Add(-time.Second))
			} else {
				c.SetDeadline(time.Now().Add(-time.Second))
			}
			select {
			case err := <-result:
				if action == "close" && !errors.Is(err, net.ErrClosed) {
					t.Fatal(err)
				}
				if action != "close" && !errors.Is(err, os.ErrDeadlineExceeded) {
					t.Fatal(err)
				}
			case <-time.After(time.Second):
				t.Fatal("pending DNS write was not unblocked")
			}
		})
	}
}
func TestPendingDNSDeadlineCanBeCleared(t *testing.T) {
	server := udptest.Start(t, udptest.Config{Direct: true})
	entered := make(chan struct{})
	release := make(chan struct{})
	resolver := resolverFunc(func(ctx context.Context, _ string) ([]net.IPAddr, error) {
		close(entered)
		select {
		case <-ctx.Done():
			return nil, ctx.Err()
		case <-release:
			return []net.IPAddr{{IP: net.IPv4(127, 0, 0, 1)}}, nil
		}
	})
	d, _ := New(Profile{Version: 1}, nil, resolver)
	c, _ := d.Open(context.Background(), "127.0.0.1:0")
	defer c.Close()
	c.SetWriteDeadline(time.Now().Add(100 * time.Millisecond))
	result := make(chan error, 1)
	target, _ := net.ResolveUDPAddr("udp", server.Address)
	go func() {
		_, err := c.WriteTo([]byte("x"), Address{Host: "blocked.example", Port: target.Port})
		result <- err
	}()
	<-entered
	c.SetWriteDeadline(time.Time{})
	time.Sleep(150 * time.Millisecond)
	close(release)
	if err := <-result; err != nil {
		t.Fatal(err)
	}
	c.SetReadDeadline(time.Now().Add(time.Second))
	if _, _, err := c.ReadFrom(make([]byte, 8)); err != nil {
		t.Fatal(err)
	}
}

func TestStaleWriteDeadlineCannotCancelWrite(t *testing.T) {
	socket, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	c := newConn(socket, nil, nil)
	defer c.Close()
	expired := time.Now().Add(-time.Second)
	for _, updated := range []time.Time{time.Time{}, time.Now().Add(time.Hour)} {
		c.SetWriteDeadline(expired)
		c.SetWriteDeadline(updated)
		ctx, cancel := context.WithCancelCause(context.Background())
		if c.expireWriteDeadline(expired, cancel) || ctx.Err() != nil {
			t.Fatal("stale deadline cancelled write")
		}
		cancel(context.Canceled)
	}
	c.SetWriteDeadline(expired)
	ctx, cancel := context.WithCancelCause(context.Background())
	defer cancel(context.Canceled)
	if !c.expireWriteDeadline(expired, cancel) || !errors.Is(context.Cause(ctx), os.ErrDeadlineExceeded) {
		t.Fatal("current deadline did not expire")
	}
	_, err = c.WriteTo(nil, &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 53})
	if !errors.Is(err, os.ErrDeadlineExceeded) {
		t.Fatalf("native deadline: %v", err)
	}
}

type noProgressControl struct {
	net.Conn
	reads   int
	resumed chan struct{}
}

func (c *noProgressControl) Read(p []byte) (int, error) {
	c.reads++
	if c.reads == 1 {
		return 0, nil
	}
	if c.reads == 2 {
		close(c.resumed)
	}
	return c.Conn.Read(p)
}
func TestControlWatcherContinuesAfterNoProgress(t *testing.T) {
	socket, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	local, peer := net.Pipe()
	defer peer.Close()
	control := &noProgressControl{Conn: local, resumed: make(chan struct{})}
	c := newConn(socket, control, nil)
	defer c.Close()
	go c.watchControl()
	select {
	case <-control.resumed:
	case <-time.After(time.Second):
		t.Fatal("watcher did not retry")
	}
	select {
	case <-c.Done():
		t.Fatal("no progress closed association")
	default:
	}
	peer.Close()
	select {
	case <-c.Done():
	case <-time.After(time.Second):
		t.Fatal("EOF did not close association")
	}
}
