package udpdial

import (
	"context"
	"fmt"
	"io"
	"net"
	"strconv"
	"sync"
	"time"

	"github.com/webong/proxy/internal/socks5"
)

// Conn preserves datagram boundaries. Close terminates both UDP and TCP resources.
// A SOCKS peer closing (or sending unexpected control data) closes the association.
type Conn struct {
	socket          *net.UDPConn
	control         net.Conn
	dialer          *Dialer
	once            sync.Once
	done            chan struct{}
	mu              sync.RWMutex
	writeDeadline   time.Time
	deadlineChanged chan struct{}
	lifetime        context.Context
	cancelLifetime  context.CancelFunc
}

var _ net.PacketConn = (*Conn)(nil)

func newConn(socket *net.UDPConn, control net.Conn, d *Dialer) *Conn {
	lifetime, cancel := context.WithCancel(context.Background())
	return &Conn{socket: socket, control: control, dialer: d, done: make(chan struct{}), deadlineChanged: make(chan struct{}), lifetime: lifetime, cancelLifetime: cancel}
}
func (c *Conn) watchControl()         { var b [1]byte; c.control.Read(b[:]); c.Close() }
func (c *Conn) Done() <-chan struct{} { return c.done }
func (c *Conn) Close() error {
	var err error
	c.once.Do(func() {
		c.cancelLifetime()
		err = c.socket.Close()
		if c.control != nil {
			c.control.Close()
		}
		close(c.done)
	})
	return err
}
func (c *Conn) LocalAddr() net.Addr                { return c.socket.LocalAddr() }
func (c *Conn) SetDeadline(t time.Time) error      { return c.setWriteDeadline(t, true) }
func (c *Conn) SetReadDeadline(t time.Time) error  { return c.socket.SetReadDeadline(t) }
func (c *Conn) SetWriteDeadline(t time.Time) error { return c.setWriteDeadline(t, false) }
func (c *Conn) setWriteDeadline(t time.Time, both bool) error {
	c.mu.Lock()
	defer c.mu.Unlock()
	var err error
	if both {
		err = c.socket.SetDeadline(t)
	} else {
		err = c.socket.SetWriteDeadline(t)
	}
	if err != nil {
		return err
	}
	c.writeDeadline = t
	close(c.deadlineChanged)
	c.deadlineChanged = make(chan struct{})
	return nil
}

// DNS is part of a pending write: Close and deadline changes must interrupt it,
// just as they interrupt the native UDP send. Deadline extensions/clears rearm
// the timer without restarting a resolver operation.
func (c *Conn) writeContext() (context.Context, func()) {
	ctx, cancel := context.WithCancelCause(c.lifetime)
	done := make(chan struct{})
	go func() {
		defer close(done)
		for {
			c.mu.RLock()
			deadline, changed := c.writeDeadline, c.deadlineChanged
			c.mu.RUnlock()
			var timer *time.Timer
			var timeout <-chan time.Time
			if !deadline.IsZero() {
				if !time.Now().Before(deadline) {
					cancel(context.DeadlineExceeded)
					return
				}
				timer = time.NewTimer(time.Until(deadline))
				timeout = timer.C
			}
			select {
			case <-ctx.Done():
				if timer != nil {
					timer.Stop()
				}
				return
			case <-changed:
				if timer != nil {
					timer.Stop()
				}
				continue
			case <-timeout:
				c.mu.RLock()
				current := c.writeDeadline
				c.mu.RUnlock()
				if current != deadline || current.IsZero() || time.Now().Before(current) {
					continue
				}
				cancel(context.DeadlineExceeded)
				return
			}
		}
	}()
	return ctx, func() { cancel(context.Canceled); <-done }
}
func (c *Conn) WriteTo(payload []byte, address net.Addr) (written int, resultErr error) {
	select {
	case <-c.done:
		return 0, net.ErrClosed
	default:
	}
	if address == nil {
		return 0, fmt.Errorf("UDP destination is required")
	}
	host, port, err := net.SplitHostPort(address.String())
	if err != nil {
		return 0, err
	}
	n, err := strconv.Atoi(port)
	if err != nil || socks5.Validate(host, n, false) != nil {
		return 0, fmt.Errorf("invalid UDP destination")
	}
	if len(payload) > MaxWireSize {
		return 0, ErrMessageTooLarge
	}
	ctx, finish := c.writeContext()
	defer func() {
		if resultErr != nil {
			if c.lifetime.Err() != nil {
				resultErr = net.ErrClosed
			} else if cause := context.Cause(ctx); cause != nil {
				resultErr = cause
			}
		}
		finish()
	}()

	ip := net.ParseIP(host)
	if c.control == nil || (ip == nil && c.dialer.proxy.Scheme == "socks5") {
		if ip == nil {
			if c.control != nil {
				addresses, e := c.dialer.resolver.LookupIPAddr(ctx, host)
				if e != nil {
					return 0, e
				}
				for _, a := range addresses {
					if a.Zone == "" && a.IP.To16() != nil {
						ip = a.IP
						break
					}
				}
				if ip == nil {
					return 0, fmt.Errorf("SOCKS UDP DNS returned no addresses")
				}
			} else {
				ip, err = c.dialer.resolve(ctx, host, c.socket.LocalAddr().(*net.UDPAddr).IP.To4() != nil)
				if err != nil {
					return 0, err
				}
			}
		}
		host = ip.String()
	}
	if err = setupError(ctx); err != nil {
		return 0, err
	}
	if c.control == nil {
		return c.socket.WriteToUDP(payload, &net.UDPAddr{IP: ip, Port: n})
	}
	data, err := EncodeDatagram(payload, Address{Host: host, Port: n})
	if err != nil {
		return 0, err
	}
	sent, err := c.socket.Write(data)
	if err != nil {
		return 0, err
	}
	if sent != len(data) {
		return 0, io.ErrShortWrite
	}
	return len(payload), nil
}
func (c *Conn) ReadFrom(buffer []byte) (int, net.Addr, error) {
	wire := make([]byte, 65535)
	for {
		n, source, err := c.socket.ReadFromUDP(wire)
		if err != nil {
			return 0, nil, err
		}
		payload := wire[:n]
		var address net.Addr = source
		if n > MaxWireSize {
			continue
		}
		if c.control != nil {
			var a Address
			payload, a, err = DecodeDatagram(payload)
			if err != nil {
				continue
			}
			address = a
		}
		copied := copy(buffer, payload)
		if copied < len(payload) {
			return copied, address, io.ErrShortBuffer
		}
		return copied, address, nil
	}
}
