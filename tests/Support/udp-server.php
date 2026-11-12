<?php

// Independent, loopback-only UDP/SOCKS fixture.
$config = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$family6 = $config['ipv6'] ?? false;
$ip = $family6 ? '::1' : '127.0.0.1';
$endpoint = $family6 ? '[::1]:0' : '127.0.0.1:0';
$udp = stream_socket_server('udp://'.$endpoint, $errno, $error, STREAM_SERVER_BIND);
if ($udp === false) {
    echo "UNAVAILABLE\n";
    exit(2);
}
$udpName = stream_socket_get_name($udp, false);
$udpPort = (int) substr($udpName, strrpos($udpName, ':') + 1);
$direct = $config['direct'] ?? false;
$control = null;
$listener = null;
if (! $direct) {
    $listener = stream_socket_server('tcp://'.$endpoint, $errno, $error);
}
echo ($direct ? $udpName : stream_socket_get_name($listener, false))."\n";
flush();
$record = ['packets' => []];
function udpTake($stream, int $length): string
{
    $data = '';
    while (strlen($data) < $length) {
        $chunk = fread($stream, $length - strlen($data));
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('EOF');
        } $data .= $chunk;
    }

return $data;
}
function udpAddress(string $data, int &$offset): array
{
    $type = ord($data[$offset++]);
    $size = match ($type) {
        1 => 4, 4 => 16, 3 => ord($data[$offset++])
    };
    $host = substr($data, $offset, $size);
    $offset += $size;
    $port = unpack('n', substr($data, $offset, 2))[1];
    $offset += 2;

    return ['host' => $type === 3 ? $host : inet_ntop($host), 'port' => $port, 'atyp' => $type];
}
try {
    if (! $direct) {
        $control = stream_socket_accept($listener, 3);
        fclose($listener);
        $listener = null;
        stream_set_timeout($control, 3);
        $greeting = udpTake($control, 2);
        $record['greeting'] = bin2hex($greeting.udpTake($control, ord($greeting[1])));
        if ($config['stall'] ?? false) {
            usleep(150000);
            throw new RuntimeException('stall');
        }
        fwrite($control, ($config['auth'] ?? false) ? "\x05\x02" : "\x05\x00");
        if ($config['auth'] ?? false) {
            $header = udpTake($control, 2);
            $record['user'] = udpTake($control, ord($header[1]));
            $record['password'] = udpTake($control, ord(udpTake($control, 1)));
            fwrite($control, ($config['bad_auth'] ?? false) ? "\x01\x01" : "\x01\x00");
            if ($config['bad_auth'] ?? false) {
                throw new RuntimeException('auth rejected');
            }
        }
        $head = udpTake($control, 4);
        $record['command'] = bin2hex(substr($head, 0, 3));
        $size = match (ord($head[3])) {
            1 => 4, 4 => 16, 3 => ord(udpTake($control, 1))
        };
        $request = $head[3].(ord($head[3]) === 3 ? chr($size) : '').udpTake($control, $size + 2);
        $offset = 0;
        $record['request'] = udpAddress($request, $offset);
        $relay = $config['relay'] ?? 'literal';
        $replyAddress = $relay === 'domain' ? "\x03\x09localhost" : (($family6 ? "\x04" : "\x01").inet_pton($relay === 'wildcard' ? ($family6 ? '::' : '0.0.0.0') : $ip));
        $reply = "\x05".chr($config['status'] ?? 0)."\x00".$replyAddress.pack('n', ($config['zero_port'] ?? false) ? 0 : $udpPort);
        if ($config['fragment_reply'] ?? false) {
            foreach (str_split($reply) as $byte) {
                fwrite($control, $byte);
                usleep(1000);
            }
        } else {
            fwrite($control, $reply);
        }
        if (($config['status'] ?? 0) !== 0 || ($config['zero_port'] ?? false)) {
            while (! feof($control)) {
                fread($control, 1);
            } $record['control_closed'] = true;
            throw new RuntimeException('rejected');
        }
        if ($config['close_immediately'] ?? false) {
            fclose($control);
            $control = null;
            throw new RuntimeException('closed');
        }
    }
    while (true) {
        $read = $control === null ? [$udp] : [$control, $udp];
        $write = [];
        $except = [];
        if (stream_select($read, $write, $except, 3) <= 0) {
            throw new RuntimeException('timeout');
        }
        if ($control !== null && in_array($control, $read, true)) {
            $record['control_closed'] = true;
            break;
        }
        if (! in_array($udp, $read, true)) {
            continue;
        }
        $data = stream_socket_recvfrom($udp, 65535, 0, $source);
        if ($data === false) {
            continue;
        }
        $offset = 3;
        $address = $direct ? [] : udpAddress($data, $offset);
        $record['packets'][] = $address + ['payload' => bin2hex($direct ? $data : substr($data, $offset))];
        if ($config['close_on_packet'] ?? false) {
            fclose($control);
            $control = null;
            break;
        }
        if ($config['drop'] ?? false) {
            continue;
        }
        if (! $direct && ($config['inject'] ?? false)) {
            $bad = $data;
            $bad[2] = "\x01";
            stream_socket_sendto($udp, $bad, 0, $source);
            $bad = $data;
            $bad[0] = "\x01";
            stream_socket_sendto($udp, $bad, 0, $source);
            $spoof = stream_socket_server('udp://'.$endpoint, $errno, $error, STREAM_SERVER_BIND);
            stream_socket_sendto($spoof, substr($data, 0, $offset).'spoof', 0, $source);
            fclose($spoof);
            usleep(5000);
        }
        stream_socket_sendto($udp, $data, 0, $source);
        if ($direct && count($record['packets']) >= ($config['count'] ?? 1)) {
            break;
        }
    }
} catch (Throwable $exception) {
    $record['note'] = $exception->getMessage();
} finally {
    if (is_resource($control)) {
        fclose($control);
    } if (is_resource($listener)) {
        fclose($listener);
    } fclose($udp);
    echo json_encode($record, JSON_THROW_ON_ERROR)."\n";
}
