package tcpdial

import "github.com/webong/proxy/socketprofile"

type Profile = socketprofile.Profile

func Parse(data []byte) (Profile, error) { return socketprofile.Parse(data) }
