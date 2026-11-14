// Package udpdial provides direct UDP sockets and SOCKS5 UDP associations.
package udpdial

import (
	"context"
	"fmt"
	"math"
	"net"
	"net/url"
	"strconv"
	"strings"
	"time"

	"github.com/webong/proxy/go/internal/proxyurl"
	"github.com/webong/proxy/go/internal/socks5"
	"github.com/webong/proxy/go/tcpdial"
)

type Dialer struct {
	profile  Profile
	proxy    *url.URL
	base     tcpdial.ContextDialer
	resolver tcpdial.Resolver
}

func New(profile Profile, base tcpdial.ContextDialer, resolver tcpdial.Resolver) (*Dialer, error) {
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
func (d *Dialer) timeout() time.Duration {
	n := d.profile.ConnectTimeoutSeconds
	if n == 0 {
		n = 10
	}
	return time.Duration(math.Ceil(n*1000)) * time.Millisecond
}
func bindAddress(value string) (*net.UDPAddr, error) {
	if value == "" {
		return &net.UDPAddr{IP: net.IPv4zero}, nil
	}
	host, port, err := net.SplitHostPort(value)
	if err != nil {
		return nil, fmt.Errorf("invalid UDP bind address")
	}
	if host == "" {
		host = "0.0.0.0"
	}
	ip := net.ParseIP(host)
	n, e := strconv.Atoi(port)
	if ip == nil || e != nil || port == "" || strings.Trim(port, "0123456789") != "" || n < 0 || n > 65535 {
		return nil, fmt.Errorf("UDP bind must use a numeric address and port")
	}
	return &net.UDPAddr{IP: ip, Port: n}, nil
}
func setupError(ctx context.Context) error {
	if err := ctx.Err(); err != nil {
		return err
	}
	if deadline, ok := ctx.Deadline(); ok && !time.Now().Before(deadline) {
		return context.DeadlineExceeded
	}
	return nil
}

// Open binds an ephemeral IPv4 socket by default. SOCKS defaults to the TCP
// control connection's source IP. Explicit bind addresses must be numeric.
func (d *Dialer) Open(ctx context.Context, localAddress string) (result *Conn, err error) {
	ctx, cancel := context.WithTimeout(ctx, d.timeout())
	defer cancel()
	local, err := bindAddress(localAddress)
	if err != nil {
		return nil, err
	}
	if err = setupError(ctx); err != nil {
		return nil, err
	}
	if d.proxy == nil {
		network := "udp4"
		if local.IP.To4() == nil {
			network = "udp6"
		}
		socket, e := net.ListenUDP(network, local)
		if e != nil {
			return nil, e
		}
		if e = setupError(ctx); e != nil {
			socket.Close()
			return nil, e
		}
		return newConn(socket, nil, d), nil
	}
	control, e := d.base.DialContext(ctx, "tcp", proxyurl.Address(d.proxy))
	if setupError(ctx) != nil {
		e = setupError(ctx)
	}
	if e != nil {
		if control != nil {
			control.Close()
		}
		return nil, e
	}
	if control == nil {
		return nil, fmt.Errorf("UDP control dialer returned no connection")
	}
	var socket *net.UDPConn
	defer func() {
		if err != nil {
			control.Close()
			if socket != nil {
				socket.Close()
			}
		}
	}()
	deadline, _ := ctx.Deadline()
	if err = control.SetDeadline(deadline); err != nil {
		return nil, err
	}
	done := make(chan struct{})
	stop := context.AfterFunc(ctx, func() { control.Close(); close(done) })
	watching := true
	defer func() {
		if watching && !stop() {
			<-done
		}
	}()
	if err = socks5.Authenticate(control, d.proxy); err != nil {
		if e = setupError(ctx); e != nil {
			err = e
		}
		return nil, err
	}
	// The UDP socket has not been allocated yet; RFC1928 requires an all-zero endpoint.
	unknown, _ := socks5.Encode(Address{Host: "0.0.0.0", Port: 0}, true)
	relay, e := socks5.Request(control, 3, unknown)
	if e != nil {
		err = e
		if e = setupError(ctx); e != nil {
			err = e
		}
		return nil, err
	}
	if socks5.Validate(relay.Host, relay.Port, false) != nil {
		return nil, ErrProtocol
	}
	remote, ok := control.RemoteAddr().(*net.TCPAddr)
	if !ok {
		return nil, fmt.Errorf("UDP control peer must expose a TCP address")
	}
	source, ok := control.LocalAddr().(*net.TCPAddr)
	if !ok {
		return nil, fmt.Errorf("UDP control source must expose a TCP address")
	}
	ip := net.ParseIP(relay.Host)
	if ip != nil && ip.IsUnspecified() {
		ip = remote.IP
	}
	if ip == nil {
		ip, err = d.resolve(ctx, relay.Host, source.IP.To4() != nil)
		if err != nil {
			return nil, err
		}
	}
	if relay.Port < 1 || relay.Port > 65535 || ip == nil || ip.IsUnspecified() {
		return nil, fmt.Errorf("invalid SOCKS UDP relay")
	}
	if (ip.To4() != nil) != (source.IP.To4() != nil) {
		return nil, fmt.Errorf("SOCKS UDP relay and TCP source address families differ")
	}
	if localAddress == "" || local.IP.IsUnspecified() {
		local.IP = source.IP
	} else if !local.IP.Equal(source.IP) {
		return nil, fmt.Errorf("SOCKS UDP must use the TCP source IP")
	}
	network := "udp4"
	if ip.To4() == nil {
		network = "udp6"
	}
	socket, err = net.DialUDP(network, local, &net.UDPAddr{IP: ip, Port: relay.Port})
	if err != nil {
		return nil, err
	}
	if err = setupError(ctx); err != nil {
		return nil, err
	}
	watching = false
	if !stop() {
		<-done
		if err = setupError(ctx); err != nil {
			return nil, err
		}
	}
	if err = control.SetDeadline(time.Time{}); err != nil {
		return nil, err
	}
	result = newConn(socket, control, d)
	go result.watchControl()
	return result, nil
}
func (d *Dialer) resolve(ctx context.Context, host string, ipv4 bool) (net.IP, error) {
	addresses, err := d.resolver.LookupIPAddr(ctx, host)
	if err != nil {
		return nil, err
	}
	for _, address := range addresses {
		if address.Zone == "" && address.IP.To16() != nil && (address.IP.To4() != nil) == ipv4 {
			return address.IP, nil
		}
	}
	return nil, fmt.Errorf("UDP DNS returned no address in the socket family")
}
