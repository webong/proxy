<?php

declare(strict_types=1);

namespace Webong\Proxy\Tcp;

use Webong\Proxy\Connection\SocketProfile;

final readonly class TcpProfile extends SocketProfile
{
    protected const PROXY_SCHEMES = ['socks5', 'socks5h', 'http'];
}
