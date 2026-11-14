<?php

declare(strict_types=1);

namespace Webong\Proxy\Enums;

enum WebhookProxyTargetType: string
{
    case REQUEST = 'request';
    case EVENT = 'event';
    case JOB = 'job';
}
