package socketprofile

import "encoding/json"

// TCPProfile describes direct TCP, SOCKS5 CONNECT, or HTTP CONNECT.
type TCPProfile Profile

func ParseTCP(data []byte) (TCPProfile, error) {
	p, err := parse(data, true)
	return TCPProfile(p), err
}
func (p TCPProfile) Validate() error { return Profile(p).validate(true) }
func (p *TCPProfile) UnmarshalJSON(data []byte) error {
	v, err := ParseTCP(data)
	if err == nil {
		*p = v
	}
	return err
}
func (p TCPProfile) MarshalJSON() ([]byte, error) {
	if err := p.Validate(); err != nil {
		return nil, err
	}
	type wire TCPProfile
	return json.Marshal(wire(p))
}
