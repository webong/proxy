// Package sockstest is a loopback-only protocol fixture shared by integration tests.
package sockstest

import (
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"io"
	"net"
	"os"
	"strings"
	"testing"
	"time"
)

type Case struct {
	Username    string  `json:"username"`
	Password    string  `json:"password"`
	Upstream    string  `json:"upstream"`
	Name        string  `json:"name"`
	Scheme      string  `json:"scheme"`
	Host        string  `json:"host"`
	Port        int     `json:"port"`
	Reply       string  `json:"reply"`
	Greeting    string  `json:"greeting"`
	AuthReply   string  `json:"auth_reply"`
	Credentials bool    `json:"credentials"`
	ResolvedIP  string  `json:"resolved_ip"`
	ATYP        byte    `json:"atyp"`
	WireHost    string  `json:"wire_host"`
	Success     bool    `json:"success"`
	Fragment    bool    `json:"fragment"`
	Stall       bool    `json:"stall"`
	Timeout     float64 `json:"timeout"`
	Mode        string  `json:"mode"`
}
type Record struct {
	Greeting       string
	User, Password string
	ATYP           byte
	Host           string
	Port           int
	Request        string
}
type Server struct {
	Address string
	done    chan struct{}
	record  Record
}

func Load(t *testing.T, path string) []Case {
	t.Helper()
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	var cases []Case
	if err = json.Unmarshal(data, &cases); err != nil {
		t.Fatal(err)
	}
	return cases
}
func Start(t *testing.T, c Case) *Server {
	t.Helper()
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	server := &Server{Address: listener.Addr().String(), done: make(chan struct{})}
	go func() {
		defer close(server.done)
		conn, err := listener.Accept()
		if err != nil {
			return
		}
		defer conn.Close()
		conn.SetDeadline(time.Now().Add(3 * time.Second))
		server.serve(conn, c)
	}()
	t.Cleanup(func() { listener.Close(); <-server.done })
	return server
}
func (s *Server) Record() Record { <-s.done; return s.record }
func (s *Server) serve(conn net.Conn, c Case) {
	read := func(n int) []byte {
		b := make([]byte, n)
		if _, err := io.ReadFull(conn, b); err != nil {
			return nil
		}
		return b
	}
	send := func(value string) bool {
		data, _ := hex.DecodeString(value)
		if c.Fragment {
			for _, b := range data {
				if _, err := conn.Write([]byte{b}); err != nil {
					return false
				}
				time.Sleep(time.Millisecond)
			}
		} else {
			if _, err := conn.Write(data); err != nil {
				return false
			}
		}
		return true
	}
	if c.Mode != "direct" {
		greeting := read(3)
		if greeting == nil {
			return
		}
		s.record.Greeting = hex.EncodeToString(greeting)
		if c.Stall {
			time.Sleep(150 * time.Millisecond)
			return
		}
		if !send(c.Greeting) {
			return
		}
		expected := "0500"
		if c.Credentials {
			expected = "0502"
		}
		if c.Greeting != expected {
			return
		}
		if c.Credentials {
			header := read(2)
			if header == nil {
				return
			}
			user := read(int(header[1]))
			if user == nil {
				return
			}
			size := read(1)
			if size == nil {
				return
			}
			password := read(int(size[0]))
			if password == nil {
				return
			}
			s.record.User = string(user)
			s.record.Password = string(password)
			if !send(c.AuthReply) || c.AuthReply != "0100" {
				return
			}
		}
		header := read(4)
		if header == nil {
			return
		}
		s.record.ATYP = header[3]
		switch header[3] {
		case 1:
			ip := read(4)
			if ip == nil {
				return
			}
			s.record.Host = net.IP(ip).String()
		case 4:
			ip := read(16)
			if ip == nil {
				return
			}
			s.record.Host = net.IP(ip).String()
		case 3:
			size := read(1)
			if size == nil {
				return
			}
			host := read(int(size[0]))
			if host == nil {
				return
			}
			s.record.Host = string(host)
		default:
			return
		}
		port := read(2)
		if port == nil {
			return
		}
		s.record.Port = int(binary.BigEndian.Uint16(port))
		if !send(c.Reply) || !c.Success {
			return
		}
	}
	if c.Mode == "tunnel" {
		upstream, err := net.Dial("tcp", c.Upstream)
		if err != nil {
			return
		}
		defer upstream.Close()
		done := make(chan struct{})
		go func() { io.Copy(conn, upstream); conn.Close(); close(done) }()
		io.Copy(upstream, conn)
		upstream.Close()
		<-done
		return
	}
	if c.Mode == "http" {
		var request strings.Builder
		for request.Len() < 8192 {
			b := read(1)
			if b == nil {
				return
			}
			request.Write(b)
			if strings.HasSuffix(request.String(), "\r\n\r\n") {
				break
			}
		}
		s.record.Request = strings.Split(request.String(), "\r\n")[0]
		io.WriteString(conn, "HTTP/1.1 204 No Content\r\nConnection: close\r\n\r\n")
	} else {
		data := read(4)
		if data != nil {
			conn.Write(data)
		}
	}
}
