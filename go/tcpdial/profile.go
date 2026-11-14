package tcpdial

import "github.com/webong/proxy/go/socketprofile"

// Profile describes direct TCP, SOCKS5 CONNECT, or HTTP CONNECT.
type Profile = socketprofile.TCPProfile

func Parse(data []byte) (Profile, error) { return socketprofile.ParseTCP(data) }
