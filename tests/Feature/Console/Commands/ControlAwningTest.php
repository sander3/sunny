<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ControlAwningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.tahoma.host' => '192.168.1.42',
            'services.tahoma.port' => 8443,
            'services.tahoma.token' => 'test-token',
            'services.tahoma.device_url' => 'io://0817-9354-9770/15968589',
            'services.tahoma.verify_ssl' => false,
        ]);

        Http::preventStrayRequests();
    }

    public function test_it_opens_the_awning(): void
    {
        Http::fake([
            'https://192.168.1.42:8443/enduser-mobile-web/1/enduserAPI/exec/apply' => Http::response([
                'execId' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            ]),
        ]);

        $this->artisan('awning:control', ['action' => 'open'])
            ->expectsOutputToContain('Awning open command sent (execution: aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa).')
            ->assertSuccessful();
    }

    public function test_it_closes_the_awning(): void
    {
        Http::fake([
            'https://192.168.1.42:8443/enduser-mobile-web/1/enduserAPI/exec/apply' => Http::response([
                'execId' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            ]),
        ]);

        $this->artisan('awning:control', ['action' => 'close'])
            ->expectsOutputToContain('Awning close command sent (execution: bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb).')
            ->assertSuccessful();
    }

    public function test_it_rejects_invalid_actions(): void
    {
        $this->artisan('awning:control', ['action' => 'stop'])
            ->expectsOutputToContain('Action must be either "open", "close", or "auto".')
            ->assertFailed();
    }

    public function test_it_reports_api_failures(): void
    {
        Http::fake([
            'https://192.168.1.42:8443/enduser-mobile-web/1/enduserAPI/exec/apply' => Http::response([
                'error' => 'Unauthorized',
                'errorCode' => 'AUTHENTICATION_ERROR',
            ], 401),
        ]);

        $this->artisan('awning:control', ['action' => 'open'])
            ->expectsOutputToContain('HTTP request returned status code 401')
            ->assertFailed();
    }
}
