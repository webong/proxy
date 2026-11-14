package udpdial

import "github.com/webong/proxy/go/socketprofile"

type Profile = socketprofile.Profile

func Parse(data []byte) (Profile, error) { return socketprofile.Parse(data) }
