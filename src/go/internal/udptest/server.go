// Package udptest provides independent loopback UDP/SOCKS protocol peers for tests.
package udptest

import (
	"encoding/binary"
	"encoding/hex"
	"fmt"
	"io"
	"net"
	"sync"
	"testing"
	"time"
)

type Config struct {
	Direct, Auth, BadAuth, Drop, Inject, CloseOnPacket, CloseImmediately, Stall, ZeroPort, IPv6, FragmentReply bool
	Relay                                                                                                      string
	Status                                                                                                     byte
	Count                                                                                                      int
}
type Packet struct {
	Host    string
	Port    int
	ATYP    byte
	Payload []byte
}
type Record struct {
	Command        string
	Request        Packet
	User, Password string
	ControlClosed  bool
	Packets        []Packet
}
type Server struct {
	Address  string
	udp      *net.UDPConn
	listener net.Listener
	control  net.Conn
	done     chan struct{}
	mu       sync.Mutex
	record   Record
}

func Start(t *testing.T, c Config) *Server {
	t.Helper()
	ip := net.IPv4(127, 0, 0, 1)
	network := "udp4"
	tcp := "tcp4"
	if c.IPv6 {
		ip = net.IPv6loopback
		network = "udp6"
		tcp = "tcp6"
	}
	socket, err := net.ListenUDP(network, &net.UDPAddr{IP: ip})
	if err != nil {
		t.Fatal(err)
	}
	server := &Server{udp: socket, done: make(chan struct{})}
	if c.Direct {
		server.Address = socket.LocalAddr().String()
	} else {
		listener, err := net.Listen(tcp, net.JoinHostPort(ip.String(), "0"))
		if err != nil {
			socket.Close()
			t.Fatal(err)
		}
		server.listener = listener
		server.Address = listener.Addr().String()
	}
	go func() { defer close(server.done); defer socket.Close(); server.serve(c, ip) }()
	t.Cleanup(func() {
		socket.Close()
		if server.listener != nil {
			server.listener.Close()
		}
		server.mu.Lock()
		if server.control != nil {
			server.control.Close()
		}
		server.mu.Unlock()
		<-server.done
	})
	return server
}
func (s *Server) Record() Record { <-s.done; return s.record }
func readAddress(r io.Reader) (Packet, error) {
	var p Packet
	var tag [1]byte
	if _, err := io.ReadFull(r, tag[:]); err != nil {
		return p, err
	}
	p.ATYP = tag[0]
	length := 0
	switch tag[0] {
	case 1:
		length = 4
	case 4:
		length = 16
	case 3:
		if _, err := io.ReadFull(r, tag[:]); err != nil {
			return p, err
		}
		length = int(tag[0])
	default:
		return p, fmt.Errorf("bad type")
	}
	host := make([]byte, length)
	if _, err := io.ReadFull(r, host); err != nil {
		return p, err
	}
	if p.ATYP == 3 {
		p.Host = string(host)
	} else {
		p.Host = net.IP(host).String()
	}
	var port [2]byte
	if _, err := io.ReadFull(r, port[:]); err != nil {
		return p, err
	}
	p.Port = int(binary.BigEndian.Uint16(port[:]))
	return p, nil
}
func (s *Server) serve(c Config, ip net.IP) {
	if !c.Direct {
		conn, err := s.listener.Accept()
		if err != nil {
			return
		}
		s.mu.Lock()
		s.control = conn
		s.mu.Unlock()
		defer conn.Close()
		conn.SetDeadline(time.Now().Add(3 * time.Second))
		var header [2]byte
		if _, err = io.ReadFull(conn, header[:]); err != nil {
			return
		}
		methods := make([]byte, int(header[1]))
		if _, err = io.ReadFull(conn, methods); err != nil {
			return
		}
		if c.Stall {
			time.Sleep(150 * time.Millisecond)
			return
		}
		method := byte(0)
		if c.Auth {
			method = 2
		}
		conn.Write([]byte{5, method})
		if c.Auth {
			if _, err = io.ReadFull(conn, header[:]); err != nil {
				return
			}
			user := make([]byte, int(header[1]))
			if _, err = io.ReadFull(conn, user); err != nil {
				return
			}
			var size [1]byte
			if _, err = io.ReadFull(conn, size[:]); err != nil {
				return
			}
			password := make([]byte, int(size[0]))
			if _, err = io.ReadFull(conn, password); err != nil {
				return
			}
			s.record.User = string(user)
			s.record.Password = string(password)
			status := byte(0)
			if c.BadAuth {
				status = 1
			}
			conn.Write([]byte{1, status})
			if c.BadAuth {
				return
			}
		}
		var command [3]byte
		if _, err = io.ReadFull(conn, command[:]); err != nil {
			return
		}
		s.record.Command = hex.EncodeToString(command[:])
		s.record.Request, err = readAddress(conn)
		if err != nil {
			return
		}
		relay := ip
		if c.Relay == "wildcard" {
			if c.IPv6 {
				relay = net.IPv6zero
			} else {
				relay = net.IPv4zero
			}
		}
		response := []byte{5, c.Status, 0}
		if c.Relay == "domain" {
			response = append(response, 3, 9)
			response = append(response, []byte("localhost")...)
		} else if relay.To4() != nil {
			response = append(response, 1)
			response = append(response, relay.To4()...)
		} else {
			response = append(response, 4)
			response = append(response, relay.To16()...)
		}
		port := s.udp.LocalAddr().(*net.UDPAddr).Port
		if c.ZeroPort {
			port = 0
		}
		response = binary.BigEndian.AppendUint16(response, uint16(port))
		if c.FragmentReply {
			for _, b := range response {
				if _, err = conn.Write([]byte{b}); err != nil {
					return
				}
				time.Sleep(time.Millisecond)
			}
		} else {
			if _, err = conn.Write(response); err != nil {
				return
			}
		}
		if c.Status != 0 || c.ZeroPort || c.CloseImmediately {
			return
		}
		conn.SetDeadline(time.Time{})
		controlDone := make(chan struct{})
		go func() {
			var b [1]byte
			conn.Read(b[:])
			s.mu.Lock()
			s.record.ControlClosed = true
			s.mu.Unlock()
			s.udp.Close()
			close(controlDone)
		}()
		defer func() { conn.Close(); <-controlDone }()
	}
	wire := make([]byte, 65535)
	for {
		s.udp.SetReadDeadline(time.Now().Add(3 * time.Second))
		n, source, err := s.udp.ReadFromUDP(wire)
		if err != nil {
			return
		}
		data := append([]byte(nil), wire[:n]...)
		p := Packet{Payload: data}
		offset := 0
		if !c.Direct {
			reader := &countReader{data: data[3:]}
			p, err = readAddress(reader)
			if err != nil {
				return
			}
			offset = 3 + reader.offset
			p.Payload = append([]byte(nil), data[offset:]...)
		}
		s.record.Packets = append(s.record.Packets, p)
		if c.CloseOnPacket {
			return
		}
		if c.Drop {
			continue
		}
		if c.Inject && !c.Direct {
			bad := append([]byte(nil), data...)
			bad[2] = 1
			s.udp.WriteToUDP(bad, source)
			bad = append([]byte(nil), data...)
			bad[0] = 1
			s.udp.WriteToUDP(bad, source)
			spoof, err := net.ListenUDP("udp", &net.UDPAddr{IP: ip})
			if err != nil {
				return
			}
			spoof.WriteToUDP(append(append([]byte(nil), data[:offset]...), []byte("spoof")...), source)
			spoof.Close()
			time.Sleep(5 * time.Millisecond)
		}
		if _, err = s.udp.WriteToUDP(data, source); err != nil {
			return
		}
		if c.Direct && len(s.record.Packets) >= max(1, c.Count) {
			return
		}
	}
}

type countReader struct {
	data   []byte
	offset int
}

func (r *countReader) Read(p []byte) (int, error) {
	if r.offset == len(r.data) {
		return 0, io.EOF
	}
	n := copy(p, r.data[r.offset:])
	r.offset += n
	return n, nil
}
