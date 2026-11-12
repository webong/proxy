package contract_test

import (
	"context"
	"crypto/x509"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"

	"github.com/webong/proxy/httpclient"
	"github.com/webong/proxy/httpprofile"
	"github.com/webong/proxy/socketprofile"
	"github.com/webong/proxy/tcpdial"
	"github.com/webong/proxy/udpdial"
)

var (
	_ net.PacketConn = (*udpdial.Conn)(nil)
	_ error          = tcpdial.ErrConnectProtocol
	_ error          = tcpdial.ErrProtocol
	_ error          = tcpdial.ErrAuthentication
	_ error          = (*tcpdial.ConnectError)(nil)
	_ error          = (*tcpdial.ReplyError)(nil)
	_ error          = udpdial.ErrMessageTooLarge
	_ error          = udpdial.ErrFragment
	_ error          = udpdial.ErrAuthentication
	_ error          = udpdial.ErrProtocol
)

func TestPublicProfileAndSocketComposition(t *testing.T) {
	shared := socketprofile.Profile{Version: 1}
	if _, err := tcpdial.New(tcpdial.Profile(shared), nil, nil); err != nil {
		t.Fatal(err)
	}
	if _, err := udpdial.New(shared, nil, nil); err != nil {
		t.Fatal(err)
	}
	tcp, err := tcpdial.Parse([]byte(`{"version":1,"proxy":"https://proxy.example"}`))
	if err != nil {
		t.Fatal(err)
	}
	if _, err = tcpdial.New(tcp, nil, nil, tcpdial.WithProxyRootCAs(x509.NewCertPool())); err != nil {
		t.Fatal(err)
	}
	wire, err := json.Marshal(tcp)
	if err != nil {
		t.Fatal(err)
	}
	if _, err = tcpdial.Parse(wire); err != nil {
		t.Fatal(err)
	}
	if _, err = udpdial.Parse(wire); err == nil {
		t.Fatal("UDP accepted TCP-only proxy")
	}
	address := udpdial.Address{Host: "service.example", Port: 9000}
	frame, err := udpdial.EncodeDatagram([]byte{0, 255}, address)
	if err != nil {
		t.Fatal(err)
	}
	payload, decoded, err := udpdial.DecodeDatagram(frame)
	if err != nil || len(payload) != 2 || decoded != address {
		t.Fatalf("datagram contract: %v", err)
	}
	if address.Network() != "udp" || address.String() != "service.example:9000" {
		t.Fatal("address methods changed")
	}
	if (&tcpdial.ReplyError{Code: 5}).Error() == "" || (&tcpdial.ConnectError{StatusCode: 407}).Error() == "" {
		t.Fatal("missing typed error messages")
	}
}

func TestHTTPConsumerOwnsHeadersDialAndRedirectPolicy(t *testing.T) {
	var redirects atomic.Int32
	denied := errors.New("caller denied redirect")
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("X-Caller-Policy") != "retained" {
			t.Error("request headers lost")
		}
		if r.URL.Path == "/target" {
			redirects.Add(1)
			return
		}
		http.Redirect(w, r, "/target", http.StatusFound)
	}))
	defer server.Close()
	var dials atomic.Int32
	base := &http.Transport{DialContext: func(ctx context.Context, network, address string) (net.Conn, error) {
		dials.Add(1)
		if address != server.Listener.Addr().String() {
			return nil, errors.New("caller denied destination")
		}
		return (&net.Dialer{}).DialContext(ctx, network, address)
	}}
	profile, err := httpprofile.Parse([]byte(`{"version":1,"connect_timeout_seconds":1,"request_timeout_seconds":2}`))
	if err != nil {
		t.Fatal(err)
	}
	client, err := httpclient.New(profile, base)
	if err != nil {
		t.Fatal(err)
	}
	defer client.CloseIdleConnections()
	client.CheckRedirect = func(*http.Request, []*http.Request) error { return denied }
	request, _ := http.NewRequest("GET", server.URL+"/start", nil)
	request.Header.Set("X-Caller-Policy", "retained")
	response, err := client.Do(request)
	if response != nil && response.Body != nil {
		response.Body.Close()
	}
	if !errors.Is(err, denied) || redirects.Load() != 0 || dials.Load() != 1 {
		t.Fatalf("caller policy lost: %v dials=%d redirects=%d", err, dials.Load(), redirects.Load())
	}
	// A forbidden initial destination must still pass through the supplied dialer.
	response, err = client.Get("http://blocked.invalid/")
	if response != nil {
		io.Copy(io.Discard, response.Body)
		response.Body.Close()
	}
	if err == nil || dials.Load() != 2 {
		t.Fatal("destination policy bypassed")
	}
}
