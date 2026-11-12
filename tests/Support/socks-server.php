<?php

// One-connection loopback fixture. Never connects to external networks.
$scenario = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) { exit(2); }
echo stream_socket_get_name($server, false)."\n";
flush();
$socket = stream_socket_accept($server, 3);
fclose($server);
if ($socket === false) { exit(3); }
stream_set_timeout($socket, 3);
$record = [];
$certificateFile = null;
$opensslConfig = null;
$randomFile = null;
function take($socket, int $length): string {
    $data = '';
    while (strlen($data) < $length) {
        $piece = fread($socket, $length - strlen($data));
        if ($piece === false || $piece === '') { throw new RuntimeException('Fixture peer closed.'); }
        $data .= $piece;
    }
    return $data;
}
function sendBytes($socket, string $bytes, bool $fragment = false): void {
    if ($fragment) {
        foreach (str_split($bytes) as $byte) { fwrite($socket, $byte); usleep(1000); }
    } else { fwrite($socket, $bytes); }
}
try {
    if (! in_array($scenario['mode'] ?? '', ['direct', 'http-direct'], true)) {
        $record['greeting'] = bin2hex(take($socket, 3));
        if ($scenario['stall'] ?? false) { usleep(150000); throw new RuntimeException('Stall complete.'); }
        $greeting = hex2bin($scenario['greeting']);
        sendBytes($socket, $greeting, $scenario['fragment'] ?? false);
        $expected = ($scenario['credentials'] ?? false) ? '0502' : '0500';
        if (bin2hex($greeting) !== $expected) { throw new RuntimeException('Greeting rejected.'); }
        if ($scenario['credentials'] ?? false) {
            $header = take($socket, 2);
            $record['auth_version'] = ord($header[0]);
            $record['username'] = take($socket, ord($header[1]));
            $record['password'] = take($socket, ord(take($socket, 1)));
            sendBytes($socket, hex2bin($scenario['auth_reply']), $scenario['fragment'] ?? false);
            if ($scenario['auth_reply'] !== '0100') { throw new RuntimeException('Authentication rejected.'); }
        }
        $header = take($socket, 4);
        $record['command'] = bin2hex(substr($header, 0, 3));
        $record['atyp'] = ord($header[3]);
        $record['host'] = match ($record['atyp']) {
            1 => inet_ntop(take($socket, 4)),
            4 => inet_ntop(take($socket, 16)),
            3 => take($socket, ord(take($socket, 1))),
            default => throw new RuntimeException('Bad client address.'),
        };
        $record['port'] = unpack('n', take($socket, 2))[1];
        sendBytes($socket, hex2bin($scenario['reply']), $scenario['fragment'] ?? false);
        if (! $scenario['success']) { throw new RuntimeException('Response rejected.'); }
    }
    if (($scenario['mode'] ?? '') === 'https') {
        $opensslConfig = tempnam(sys_get_temp_dir(), 'proxy-test-openssl-');
        $randomFile = tempnam(sys_get_temp_dir(), 'proxy-test-rand-');
        file_put_contents($opensslConfig, "RANDFILE = ".$randomFile."\n[req]\ndistinguished_name = dn\n[dn]\n");
        $options = ['config' => $opensslConfig, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = openssl_pkey_new($options);
        $csr = openssl_csr_new(['commonName' => 'unresolved.example'], $key, $options);
        $certificate = openssl_csr_sign($csr, null, $key, 1, $options);
        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($key, $keyPem);
        $certificateFile = tempnam(sys_get_temp_dir(), 'proxy-test-tls-');
        file_put_contents($certificateFile, $certificatePem.$keyPem);
        stream_context_set_option($socket, 'ssl', 'local_cert', $certificateFile);
        stream_context_set_option($socket, 'ssl', 'verify_peer', false);
        if (! @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
            throw new RuntimeException('Fixture TLS handshake failed.');
        }
    }
    if (in_array($scenario['mode'] ?? '', ['http', 'https', 'http-direct'], true)) {
        $request = '';
        while (! str_contains($request, "\r\n\r\n")) { $request .= take($socket, 1); }
        $record['request'] = strtok($request, "\r\n");
        fwrite($socket, "HTTP/1.1 204 No Content\r\nConnection: close\r\n\r\n");
    } else {
        $record['echo'] = take($socket, 4);
        fwrite($socket, $record['echo']);
    }
} catch (Throwable) {
    // Malformed-response scenarios deliberately terminate the connection.
} finally {
    fclose($socket);
    foreach ([$certificateFile, $opensslConfig, $randomFile] as $file) {
        if ($file !== null) { unlink($file); }
    }
    echo json_encode($record, JSON_THROW_ON_ERROR)."\n";
}
