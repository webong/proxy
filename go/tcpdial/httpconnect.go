package tcpdial

import (
	"encoding/base64"
	"errors"
	"fmt"
	"io"
	"net"
	"regexp"
	"strconv"
	"strings"
)

// ErrConnectProtocol identifies malformed or truncated HTTP CONNECT responses.
var ErrConnectProtocol = errors.New("invalid HTTP CONNECT response")

// ConnectError reports a rejected tunnel without exposing proxy credentials or
// untrusted response text. No response body is consumed or redirects followed.
type ConnectError struct{ StatusCode int }

func (e *ConnectError) Error() string {
	return fmt.Sprintf("HTTP CONNECT rejected (status %d)", e.StatusCode)
}

var statusLine = regexp.MustCompile(`^HTTP/1\.[01] ([1-5][0-9]{2}) `)
var headerName = regexp.MustCompile("^[!#$%&'*+.^_`|~0-9A-Za-z-]+$")

func (d *Dialer) connectHandshake(conn net.Conn, authority string) error {
	request := "CONNECT " + authority + " HTTP/1.1\r\nHost: " + authority + "\r\n"
	if d.proxy.User != nil {
		password, _ := d.proxy.User.Password()
		request += "Proxy-Authorization: Basic " + base64.StdEncoding.EncodeToString([]byte(d.proxy.User.Username()+":"+password)) + "\r\n"
	}
	if err := writeAll(conn, []byte(request+"\r\n")); err != nil {
		return err
	}
	// Read exactly through the header terminator: a proxy may send tunnel bytes
	// together with its response. Neither consume nor interpret those bytes here.
	budget := 32768
	for interim := 0; ; interim++ {
		line, err := connectLine(conn, &budget)
		if err != nil {
			return err
		}
		if !statusLine.MatchString(line) || !validConnectValue(line[13:]) {
			return fmt.Errorf("%w: invalid HTTP CONNECT status", ErrConnectProtocol)
		}
		status, _ := strconv.Atoi(line[9:12])
		for {
			line, err = connectLine(conn, &budget)
			if err != nil {
				return err
			}
			if line == "" {
				break
			}
			name, value, found := strings.Cut(line, ":")
			if !found || !headerName.MatchString(name) || !validConnectValue(value) {
				return fmt.Errorf("%w: invalid HTTP CONNECT header", ErrConnectProtocol)
			}
		}
		if status >= 100 && status < 200 && status != 101 && interim < 8 {
			continue
		}
		if status >= 200 && status < 300 {
			return nil
		}
		return &ConnectError{StatusCode: status}
	}
}
func connectLine(r io.Reader, budget *int) (string, error) {
	var line strings.Builder
	var b [1]byte
	for *budget > 0 {
		*budget--
		if _, err := io.ReadFull(r, b[:]); err != nil {
			if errors.Is(err, io.EOF) || errors.Is(err, io.ErrUnexpectedEOF) {
				return "", fmt.Errorf("%w: %w", ErrConnectProtocol, err)
			}
			return "", err
		}
		line.WriteByte(b[0])
		if b[0] == '\n' {
			value := line.String()
			if len(value) < 2 || value[len(value)-2] != '\r' {
				return "", fmt.Errorf("%w: invalid HTTP CONNECT line ending", ErrConnectProtocol)
			}
			return value[:len(value)-2], nil
		}
	}
	return "", fmt.Errorf("%w: HTTP CONNECT headers exceed 32768 bytes", ErrConnectProtocol)
}

// HTTP field values and reason phrases are octets, not necessarily UTF-8.
func validConnectValue(value string) bool {
	for i := 0; i < len(value); i++ {
		if (value[i] < 32 && value[i] != '\t') || value[i] == 127 {
			return false
		}
	}
	return true
}
