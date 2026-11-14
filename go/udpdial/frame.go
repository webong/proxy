package udpdial

import (
	"bytes"
	"errors"
	"fmt"

	"github.com/webong/proxy/go/internal/socks5"
)

// MaxWireSize is the portable IPv4/IPv6 UDP payload ceiling, including SOCKS headers.
const MaxWireSize = 65507

var ErrMessageTooLarge = errors.New("UDP datagram exceeds portable wire limit")
var ErrProtocol = socks5.ErrProtocol
var ErrFragment = socks5.ErrFragment
var ErrAuthentication = socks5.ErrAuthentication

type ReplyError = socks5.ReplyError

type Address = socks5.Address

func EncodeDatagram(payload []byte, address Address) ([]byte, error) {
	header, err := socks5.Encode(address, false)
	if err != nil {
		return nil, err
	}
	if len(payload) > MaxWireSize-3-len(header) {
		return nil, ErrMessageTooLarge
	}
	data := append([]byte{0, 0, 0}, header...)
	return append(data, payload...), nil
}
func DecodeDatagram(data []byte) ([]byte, Address, error) {
	var address Address
	if len(data) > MaxWireSize || len(data) < 4 || data[0] != 0 || data[1] != 0 {
		return nil, address, ErrProtocol
	}
	if data[2] != 0 {
		return nil, address, ErrFragment
	}
	reader := bytes.NewReader(data[3:])
	address, err := socks5.ReadAddress(reader)
	if err != nil || socks5.Validate(address.Host, address.Port, false) != nil {
		return nil, Address{}, fmt.Errorf("%w: malformed UDP address", ErrProtocol)
	}
	return data[len(data)-reader.Len():], address, nil
}

// MaxPayloadSize reports available application bytes for an encoded destination.
func MaxPayloadSize(address Address) (int, error) {
	header, err := socks5.Encode(address, false)
	if err != nil {
		return 0, err
	}
	return MaxWireSize - 3 - len(header), nil
}
