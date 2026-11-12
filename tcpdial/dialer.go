// Package tcpdial provides direct, SOCKS5 CONNECT, and HTTP CONNECT TCP connections.
package tcpdial

import (
	"context"
	"fmt"
	"io"
	"math"
	"net"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/webong/proxy/internal/proxyurl"
	"github.com/webong/proxy/internal/socks5"
)

type ContextDialer interface {
	DialContext(context.Context, string, string) (net.Conn, error)
}
type Resolver interface {
	LookupIPAddr(context.Context, string) ([]net.IPAddr, error)
}
type Dialer struct {
	profile  Profile
	proxy    *url.URL
	base     ContextDialer
	resolver Resolver
}

var ErrProtocol = socks5.ErrProtocol
var ErrAuthentication = socks5.ErrAuthentication

type ReplyError = socks5.ReplyError

// New snapshots configuration. A custom base sees the proxy hop for proxied traffic;
// it cannot authorize a remotely resolved destination. Custom dependencies must honor context.
func New(profile Profile, base ContextDialer, resolver Resolver) (*Dialer, error) {
	if err := profile.Validate(); err != nil {
		return nil, err
	}
	if base == nil {
		base = &net.Dialer{KeepAlive: 30 * time.Second}
	}
	if resolver == nil {
		resolver = net.DefaultResolver
	}
	d := &Dialer{profile: profile, base: base, resolver: resolver}
	if profile.Proxy != "" {
		d.proxy, _ = proxyurl.Parse(profile.Proxy)
	}
	return d, nil
}

var hostname = regexp.MustCompile(`^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\.?$`)

func destination(address string) (string, int, error) {
	host, port, err := net.SplitHostPort(address)
	if err != nil || host == "" || (net.ParseIP(host) == nil && (!hostname.MatchString(host) || len(host) > 253)) {
		return "", 0, fmt.Errorf("invalid TCP destination")
	}
	if port == "" || strings.Trim(port, "0123456789") != "" {
		return "", 0, fmt.Errorf("invalid TCP port")
	}
	n, err := strconv.Atoi(port)
	if err != nil || n < 1 || n > 65535 {
		return "", 0, fmt.Errorf("invalid TCP port")
	}
	return host, n, nil
}
func (d *Dialer) DialContext(ctx context.Context, network, address string) (net.Conn, error) {
	if network != "tcp" {
		return nil, fmt.Errorf("only the tcp network is supported")
	}
	host, port, err := destination(address)
	if err != nil {
		return nil, err
	}
	timeout := d.profile.ConnectTimeoutSeconds
	if timeout == 0 {
		timeout = 10
	}
	ctx, cancel := context.WithTimeout(ctx, time.Duration(math.Ceil(timeout*1000))*time.Millisecond)
	defer cancel()
	if d.proxy == nil {
		return d.connect(ctx, net.JoinHostPort(host, strconv.Itoa(port)))
	}
	var target []byte
	if d.proxy.Scheme != "http" {
		target, err = d.target(ctx, host, port)
	}
	if err != nil {
		return nil, err
	}
	hop := proxyurl.Address(d.proxy)
	if d.proxy.Scheme == "http" {
		p := d.proxy.Port()
		if p == "" {
			p = "80"
		}
		hop = net.JoinHostPort(d.proxy.Hostname(), p)
	}
	conn, err := d.connect(ctx, hop)
	if err != nil {
		return nil, err
	}
	deadline, _ := ctx.Deadline()
	if err = conn.SetDeadline(deadline); err != nil {
		conn.Close()
		return nil, err
	}
	stopped := make(chan struct{})
	stop := context.AfterFunc(ctx, func() { conn.Close(); close(stopped) })
	if d.proxy.Scheme == "http" {
		err = d.connectHandshake(conn, net.JoinHostPort(host, strconv.Itoa(port)))
	} else {
		err = d.handshake(conn, target)
	}
	if !stop() {
		<-stopped
	}
	if setupErr := setupError(ctx); setupErr != nil {
		err = setupErr
	}
	if err != nil {
		conn.Close()
		return nil, err
	}
	if err = conn.SetDeadline(time.Time{}); err != nil {
		conn.Close()
		return nil, err
	}
	return conn, nil
}
func (d *Dialer) connect(ctx context.Context, address string) (net.Conn, error) {
	if err := setupError(ctx); err != nil {
		return nil, err
	}
	conn, err := d.base.DialContext(ctx, "tcp", address)
	if setupErr := setupError(ctx); setupErr != nil {
		err = setupErr
	}
	if err != nil {
		if conn != nil {
			conn.Close()
		}
		return nil, err
	}
	if conn == nil {
		return nil, fmt.Errorf("TCP dialer returned no connection")
	}
	return conn, nil
}

// Network deadlines can fire before the context timer callback is scheduled.
func setupError(ctx context.Context) error {
	if err := ctx.Err(); err != nil {
		return err
	}
	if deadline, ok := ctx.Deadline(); ok && !time.Now().Before(deadline) {
		return context.DeadlineExceeded
	}
	return nil
}

func (d *Dialer) target(ctx context.Context, host string, port int) ([]byte, error) {
	ip := net.ParseIP(host)
	if ip == nil && d.proxy.Scheme == "socks5" {
		addresses, err := d.resolver.LookupIPAddr(ctx, host)
		if err != nil {
			return nil, fmt.Errorf("SOCKS local DNS: %w", err)
		}
		for _, address := range addresses {
			if address.Zone == "" && address.IP.To16() != nil {
				ip = address.IP
				break
			}
		}
		if ip == nil {
			return nil, fmt.Errorf("SOCKS local DNS returned no addresses")
		}
	}
	if ip != nil {
		host = ip.String()
	}
	return socks5.Encode(socks5.Address{Host: host, Port: port}, false)
}

func (d *Dialer) handshake(conn net.Conn, target []byte) error {
	if err := socks5.Authenticate(conn, d.proxy); err != nil {
		return err
	}
	_, err := socks5.Request(conn, 1, target)
	return err
}
func writeAll(w io.Writer, data []byte) error {
	for len(data) > 0 {
		n, err := w.Write(data)
		if err != nil {
			return err
		}
		if n == 0 {
			return io.ErrShortWrite
		}
		data = data[n:]
	}
	return nil
}
