package tcpdial_test

import (
	"strings"
	"testing"

	"github.com/webong/proxy/socketprofile"
	"github.com/webong/proxy/tcpdial"
	"github.com/webong/proxy/udpdial"
)

func TestSharedProfileConversionAndTransportValidation(t *testing.T) {
	for _, proxy := range []string{"", "socks5h://proxy.example", "http://proxy.example", "https://proxy.example"} {
		shared := socketprofile.Profile{Version: 1, Proxy: proxy}
		if _, err := tcpdial.New(tcpdial.Profile(shared), nil, nil); err != nil {
			t.Fatalf("TCP conversion: %v", err)
		}
		if _, err := udpdial.New(udpdial.Profile(shared), nil, nil); (err == nil) != (!strings.HasPrefix(proxy, "http")) {
			t.Fatalf("UDP scheme validation: %v", err)
		}
	}
}
