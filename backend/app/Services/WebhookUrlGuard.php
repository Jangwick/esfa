<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class WebhookUrlGuard
{
    public function transportOptions(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            $this->reject();
        }
        $host = strtolower(trim($parts['host'], '[]'));
        $scheme = strtolower($parts['scheme'] ?? '');
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $local = $host === 'localhost' && app()->environment(['local', 'testing'])
            && (bool) config('integrations.allow_localhost');
        if (! $local && $scheme !== 'https') {
            $this->reject('Webhook URLs must use HTTPS. Development may explicitly allow http://localhost.');
        }
        if ($local && ! in_array($scheme, ['http', 'https'], true)) {
            $this->reject();
        }
        $addresses = $local ? ['127.0.0.1'] : $this->resolveAddresses($host);
        if ($addresses === []) {
            $this->reject('The webhook hostname must resolve to a public IP address.');
        }
        foreach ($addresses as $address) {
            if (! $local && ! $this->isPublicAddress($address)) {
                $this->reject('Webhook URLs cannot target private, local or reserved networks.');
            }
        }
        // Pin DNS for this request so a hostname cannot rebind after validation.
        if (! defined('CURLOPT_RESOLVE')) {
            $this->reject('The PHP cURL extension is required for safe webhook delivery.');
        }
        $address = $addresses[0];
        $resolved = str_contains($address, ':') ? '['.$address.']' : $address;

        return [
            'allow_redirects' => false,
            'proxy' => '',
            'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$resolved}"]],
        ];
    }

    protected function resolveAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $addresses = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            if (isset($record['ip'])) {
                $addresses[] = $record['ip'];
            }
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }

    protected function isPublicAddress(string $address): bool
    {
        if (str_starts_with(strtolower($address), '::ffff:') || str_starts_with(strtolower($address), 'ff')) {
            return false;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $firstOctet = (int) explode('.', $address)[0];
            if ($firstOctet >= 224) { return false; }
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
    }

    private function reject(string $message = 'Enter a valid webhook URL without credentials or a fragment.'): never
    {
        throw ValidationException::withMessages(['url' => [$message]]);
    }
}
