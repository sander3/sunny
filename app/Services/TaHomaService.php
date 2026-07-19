<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TaHomaService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function devices(): array
    {
        /** @var list<array<string, mixed>> $devices */
        $devices = $this->client()
            ->get('/setup/devices')
            ->throw()
            ->json();

        return $devices;
    }

    /**
     * @param  list<mixed>  $parameters
     */
    public function execute(string $deviceUrl, string $command, array $parameters = [], ?string $label = null): string
    {
        $response = $this->client()
            ->post('/exec/apply', [
                'label' => $label ?? "Command: {$command}",
                'actions' => [
                    [
                        'deviceURL' => $deviceUrl,
                        'commands' => [
                            [
                                'name' => $command,
                                'parameters' => $parameters,
                            ],
                        ],
                    ],
                ],
            ])
            ->throw();

        $executionId = $response->json('execId');

        if (! is_string($executionId) || $executionId === '') {
            throw new RuntimeException('TaHoma did not return an execution id.');
        }

        return $executionId;
    }

    public function open(string $deviceUrl): string
    {
        return $this->execute($deviceUrl, 'open', label: 'Open awning');
    }

    public function close(string $deviceUrl): string
    {
        return $this->execute($deviceUrl, 'close', label: 'Close awning');
    }

    public function controlAwning(string $action): string
    {
        $deviceUrl = $this->awningDeviceUrl();

        return match ($action) {
            'open' => $this->open($deviceUrl),
            'close' => $this->close($deviceUrl),
            default => throw new RuntimeException("Unsupported awning action [{$action}]."),
        };
    }

    public function awningDeviceUrl(): string
    {
        $configured = config('services.tahoma.device_url');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        foreach ($this->devices() as $device) {
            $uiClass = data_get($device, 'definition.uiClass') ?? data_get($device, 'uiClass');

            if ($uiClass === 'Awning' && is_string($device['deviceURL'] ?? null)) {
                return $device['deviceURL'];
            }
        }

        throw new RuntimeException('No awning device found. Set TAHOMA_DEVICE_URL.');
    }

    protected function client(): PendingRequest
    {
        $token = config('services.tahoma.token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('TAHOMA_TOKEN is not configured.');
        }

        $client = Http::baseUrl($this->baseUrl())
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(10)
            ->connectTimeout(3);

        if (! config('services.tahoma.verify_ssl')) {
            $client->withoutVerifying();
        }

        return $client;
    }

    protected function baseUrl(): string
    {
        $host = config('services.tahoma.host');
        $port = config('services.tahoma.port');

        return "https://{$host}:{$port}/enduser-mobile-web/1/enduserAPI";
    }
}
