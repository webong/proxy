// Package tcpdial provides direct and SOCKS5 TCP connections.
package tcpdial

import (
	"context"
	"encoding/binary"
	"errors"
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

var ErrProtocol = errors.New("invalid SOCKS5 response")
var ErrAuthentication = errors.New("SOCKS5 authentication failed")

type ReplyError struct{ Code byte }

func (e *ReplyError) Error() string {
	return fmt.Sprintf("SOCKS5 connection rejected (code %d)", e.Code)
}

// New snapshots configuration. A custom base sees the proxy hop for SOCKS traffic;
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
	target, err := d.target(ctx, host, port)
	if err != nil {
		return nil, err
	}
	conn, err := d.connect(ctx, proxyurl.Address(d.proxy))
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
	err = d.handshake(conn, target)
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
	var result []byte
	if ip != nil {
		if v4 := ip.To4(); v4 != nil {
			result = append([]byte{1}, v4...)
		} else {
			result = append([]byte{4}, ip.To16()...)
		}
	} else {
		result = append([]byte{3, byte(len(host))}, []byte(host)...)
	}
	return binary.BigEndian.AppendUint16(result, uint16(port)), nil
}
func (d *Dialer) handshake(conn net.Conn, target []byte) error {
	method := byte(0)
	if d.proxy.User != nil {
		method = 2
	}
	if err := writeAll(conn, []byte{5, 1, method}); err != nil {
		return err
	}
	var response [2]byte
	if _, err := io.ReadFull(conn, response[:]); err != nil {
		return err
	}
	if response[0] != 5 {
		return ErrProtocol
	}
	if response[1] != method {
		return ErrAuthentication
	}
	if method == 2 {
		user := d.proxy.User.Username()
		password, _ := d.proxy.User.Password()
		message := append([]byte{1, byte(len(user))}, []byte(user)...)
		message = append(message, byte(len(password)))
		message = append(message, []byte(password)...)
		if err := writeAll(conn, message); err != nil {
			return err
		}
		if _, err := io.ReadFull(conn, response[:]); err != nil {
			return err
		}
		if response[0] != 1 || response[1] != 0 {
			return ErrAuthentication
		}
	}
	if err := writeAll(conn, append([]byte{5, 1, 0}, target...)); err != nil {
		return err
	}
	var header [4]byte
	if _, err := io.ReadFull(conn, header[:]); err != nil {
		return err
	}
	if header[0] != 5 || header[2] != 0 {
		return ErrProtocol
	}
	if header[1] != 0 {
		return &ReplyError{Code: header[1]}
	}
	length := 0
	switch header[3] {
	case 1:
		length = 4
	case 4:
		length = 16
	case 3:
		var size [1]byte
		if _, err := io.ReadFull(conn, size[:]); err != nil {
			return err
		}
		length = int(size[0])
		if length == 0 {
			return ErrProtocol
		}
	default:
		return ErrProtocol
	}
	_, err := io.ReadFull(conn, make([]byte, length+2))
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
