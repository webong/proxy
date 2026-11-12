package tcpdial

import (
	"crypto/x509"
	"fmt"
)

// Option configures runtime-only TCP behavior, outside portable profiles.
type Option func(*Dialer) error

// WithProxyRootCAs replaces system roots for HTTPS proxy verification. The pool
// is snapshotted by New. Proxy hostname verification is always enabled.
func WithProxyRootCAs(roots *x509.CertPool) Option {
	return func(d *Dialer) error {
		if roots == nil {
			return fmt.Errorf("proxy root CA pool must not be nil")
		}
		d.proxyRoots = roots.Clone()
		return nil
	}
}
