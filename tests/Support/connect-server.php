<?php

// Independent loopback HTTP proxy fixture; never connects to the destination.
$case = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$bind = ($case['listen_host'] ?? '') === '::1' ? '[::1]' : '127.0.0.1';
$server = stream_socket_server('tcp://'.$bind.':0', $errno, $error);
echo stream_socket_get_name($server, false)."\n";
flush();
$peer = stream_socket_accept($server, 3);
fclose($server);
stream_set_timeout($peer, 3);
if (($case['proxy_scheme'] ?? 'http') === 'https') {
    if ($case['tls_stall'] ?? false) {
        fread($peer, 1);
        while (! feof($peer)) {
            fread($peer, 4096);
        } fclose($peer);
        echo json_encode(['request' => '']);
        exit;
    }
    $leaf = $case['tls_leaf'] ?? 'proxy';
    stream_context_set_option($peer, 'ssl', 'local_cert', __DIR__.'/../fixtures/tls/'.$leaf.'.pem');
    stream_context_set_option($peer, 'ssl', 'local_pk', __DIR__.'/../fixtures/tls/'.$leaf.'-key.pem');
    stream_context_set_option($peer, 'ssl', 'verify_peer', false);
    if (! @stream_socket_enable_crypto($peer, true, ($case['tls12'] ?? false) ? STREAM_CRYPTO_METHOD_TLSv1_2_SERVER : STREAM_CRYPTO_METHOD_TLS_SERVER)) {
        fclose($peer);
        echo json_encode(['request' => '']);
        exit;
    }
}

$request = '';
while (! str_ends_with($request, "\r\n\r\n") && strlen($request) < 4096) {
    $byte = fread($peer, 1);
    if ($byte === '' || $byte === false) {
        break;
    }
    $request .= $byte;
}
if ($case['stall'] ?? false) {
    fread($peer, 1);
} else {
    $response = hex2bin($case['response_hex']);
    if ($case['large'] ?? false) {
        $response = "HTTP/1.1 200 OK\r\nX: ".str_repeat('x', 32768)."\r\n\r\n";
    }
    if (isset($case['header_bytes'])) {
        $prefix = "HTTP/1.1 200 OK\r\nX: ";
        $response = $prefix.str_repeat('x', $case['header_bytes'] - strlen($prefix) - 4)."\r\n\r\n";
    }
    if ($case['success']) {
        $response .= "banner\0";
    }
    if ($case['fragment'] ?? false) {
        foreach (str_split($response) as $byte) {
            @fwrite($peer, $byte);
            usleep(100);
        }
    } else {
        @fwrite($peer, $response);
    }
    if ($case['success']) {
        $ping = '';
        while (strlen($ping) < 4 && ! feof($peer)) {
            $ping .= fread($peer, 4 - strlen($ping));
        }
        @fwrite($peer, $ping);
    }
}
fclose($peer);
echo json_encode(['request' => $request], JSON_THROW_ON_ERROR)."\n";
