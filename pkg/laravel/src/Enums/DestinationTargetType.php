<?php

declare(strict_types=1);

namespace Webong\Proxy\Enums;

enum DestinationTargetType: string
{
    case REQUEST = 'request';
    case EVENT = 'event';
    case JOB = 'job';
}
