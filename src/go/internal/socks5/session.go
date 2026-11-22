// Package socks5 implements shared CONNECT/UDP ASSOCIATE negotiation and framing.
package socks5

import (
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"net"
	"net/url"
	"regexp"
	"strconv"
)

var ErrProtocol = errors.New("invalid SOCKS5 response")
var ErrAuthentication = errors.New("SOCKS5 authentication failed")
var ErrFragment = errors.New("SOCKS5 fragmentation is unsupported")

type ReplyError struct{ Code byte }

func (e *ReplyError) Error() string { return fmt.Sprintf("SOCKS5 request rejected (code %d)", e.Code) }

type Address struct {
	Host string
	Port int
}

func (a Address) Network() string { return "udp" }
func (a Address) String() string  { return net.JoinHostPort(a.Host, strconv.Itoa(a.Port)) }

var hostname = regexp.MustCompile(`^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\.?$`)

func Validate(host string, port int, allowZero bool) error {
	if port < 0 || port > 65535 || (!allowZero && port == 0) || host == "" || (net.ParseIP(host) == nil && (!hostname.MatchString(host) || len(host) > 253)) {
		return fmt.Errorf("invalid socket address")
	}
	return nil
}
func Encode(a Address, allowZero bool) ([]byte, error) {
	if err := Validate(a.Host, a.Port, allowZero); err != nil {
		return nil, err
	}
	ip := net.ParseIP(a.Host)
	var data []byte
	if ip != nil {
		if v4 := ip.To4(); v4 != nil {
			data = append([]byte{1}, v4...)
		} else {
			data = append([]byte{4}, ip.To16()...)
		}
	} else {
		data = append([]byte{3, byte(len(a.Host))}, []byte(a.Host)...)
	}
	return binary.BigEndian.AppendUint16(data, uint16(a.Port)), nil
}
func ReadAddress(r io.Reader) (Address, error) {
	var a Address
	var tag [1]byte
	if _, err := io.ReadFull(r, tag[:]); err != nil {
		return a, err
	}
	var host []byte
	switch tag[0] {
	case 1:
		host = make([]byte, 4)
	case 4:
		host = make([]byte, 16)
	case 3:
		var size [1]byte
		if _, err := io.ReadFull(r, size[:]); err != nil {
			return a, err
		}
		if size[0] == 0 {
			return a, ErrProtocol
		}
		host = make([]byte, int(size[0]))
	default:
		return a, ErrProtocol
	}
	if _, err := io.ReadFull(r, host); err != nil {
		return a, err
	}
	if tag[0] == 3 {
		a.Host = string(host)
	} else {
		a.Host = net.IP(host).String()
	}
	var port [2]byte
	if _, err := io.ReadFull(r, port[:]); err != nil {
		return a, err
	}
	a.Port = int(binary.BigEndian.Uint16(port[:]))
	return a, nil
}
func Authenticate(conn io.ReadWriter, proxy *url.URL) error {
	method := byte(0)
	if proxy.User != nil {
		method = 2
	}
	if err := WriteAll(conn, []byte{5, 1, method}); err != nil {
		return err
	}
	var reply [2]byte
	if _, err := io.ReadFull(conn, reply[:]); err != nil {
		return err
	}
	if reply[0] != 5 {
		return ErrProtocol
	}
	if reply[1] != method {
		return ErrAuthentication
	}
	if method == 2 {
		user := proxy.User.Username()
		password, _ := proxy.User.Password()
		data := append([]byte{1, byte(len(user))}, []byte(user)...)
		data = append(data, byte(len(password)))
		data = append(data, []byte(password)...)
		if err := WriteAll(conn, data); err != nil {
			return err
		}
		if _, err := io.ReadFull(conn, reply[:]); err != nil {
			return err
		}
		if reply[0] != 1 || reply[1] != 0 {
			return ErrAuthentication
		}
	}
	return nil
}
func Request(conn io.ReadWriter, command byte, address []byte) (Address, error) {
	var a Address
	if err := WriteAll(conn, append([]byte{5, command, 0}, address...)); err != nil {
		return a, err
	}
	var header [3]byte
	if _, err := io.ReadFull(conn, header[:]); err != nil {
		return a, err
	}
	if header[0] != 5 || header[2] != 0 {
		return a, ErrProtocol
	}
	if header[1] != 0 {
		return a, &ReplyError{Code: header[1]}
	}
	return ReadAddress(conn)
}
func WriteAll(w io.Writer, data []byte) error {
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
