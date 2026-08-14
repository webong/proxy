<?php

declare(strict_types=1);

namespace Webong\WebProxy;

enum WebhookContextKeys: string
{
    case EXECUTION_PAYLOAD = 'webhook_execution_payload';

    case BASE_URL = 'webhook_base_url';

    case PATH_TEMPLATES = 'webhook_path_templates';
}
