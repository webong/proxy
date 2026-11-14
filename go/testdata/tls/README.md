# Loopback TLS fixtures

These certificates and private keys are public test data, for loopback peers
only. The private CA key is not retained. The normal leaf is valid 2020–2045
for proxy.test, localhost, 127.0.0.1 and ::1. The expired leaf ended in 2021.
Both PHP and Go use these fixtures to verify trust, identity and expiry checks.
