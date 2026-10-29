<?php

namespace McpHub\Laravel;

use Throwable;

final class Doctor
{
    public function inspect(bool $online = false): array
    {
        $checks = [];
        try {
            $connection = app(Connection::class);
            $checks['application_key'] = preg_match('/^[a-z][a-z0-9_]{0,62}$/D', (string) $connection->get('app_key')) === 1;
            $checks['app_token'] = strlen((string) $connection->get('app_token')) >= 32;
            $checks['delegation_secret'] = strlen((string) $connection->get('delegation_secret')) >= 32;
            $checks['client_key'] = (string) $connection->get('client_key') !== '';
            $checks['manifest'] = is_array(app(Registry::class)->manifest($connection));
            $checks = array_merge($checks, app(Support\Metadata::class)->inspect());
            if ($online) {
                $checks['hub_tools'] = is_array(app(HubClient::class)->rpc('tools/list')['tools'] ?? null);
            }
        } catch (Throwable $error) {
            $checks['operation'] = false;
        }
        return ['checks' => $checks, 'ok' => ! in_array(false, $checks, true)];
    }
}
