<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\TaHomaService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TaHomaServiceTest extends TestCase
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

    public function test_it_lists_devices(): void
    {
        Http::fake([
            'https://192.168.1.42:8443/enduser-mobile-web/1/enduserAPI/setup/devices' => Http::response([
                [
                    'label' => 'balcony',
                    'deviceURL' => 'io://0817-9354-9770/15968589',
                    'definition' => ['uiClass' => 'Awning'],
                ],
            ]),
        ]);

        $devices = app(TaHomaService::class)->devices();

        $this->assertCount(1, $devices);
        $this->assertSame('balcony', $devices[0]['label']);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://192.168.1.42:8443/enduser-mobile-web/1/enduserAPI/setup/devices'
                && $request->hasHeader('Authorization', 'Bearer test-token');
        });
    }

    public function test_it_opens_the_configured_awning(): void
    {
        Http::fake([
            'https://192.168.1.42:8443/enduser-mobile-web/1/enduserAPI/exec/apply' => Http::response([
                'execId' => '11111111-1111-1111-1111-111111111111',
            ]),
        ]);

        $executionId = app(TaHomaService::class)->controlAwning('open');

        $this->assertSame('11111111-1111-1111-1111-111111111111', $executionId);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://192.168.1.42:8443/enduser-mobile-web/1/enduserAPI/exec/apply'
                && $request['actions'][0]['deviceURL'] === 'io://0817-9354-9770/15968589'
                && $request['actions'][0]['commands'][0]['name'] === 'open';
        });
    }

    public function test_it_closes_the_configured_awning(): void
    {
        Http::fake([
            'https://192.168.1.42:8443/enduser-mobile-web/1/enduserAPI/exec/apply' => Http::response([
                'execId' => '22222222-2222-2222-2222-222222222222',
            ]),
        ]);

        $executionId = app(TaHomaService::class)->controlAwning('close');

        $this->assertSame('22222222-2222-2222-2222-222222222222', $executionId);

        Http::assertSent(function (Request $request): bool {
            return $request['actions'][0]['commands'][0]['name'] === 'close';
        });
    }

    public function test_it_resolves_an_awning_device_when_not_configured(): void
    {
        config(['services.tahoma.device_url' => null]);

        Http::fake([
            'https://192.168.1.42:8443/enduser-mobile-web/1/enduserAPI/setup/devices' => Http::response([
                [
                    'label' => 'Box',
                    'deviceURL' => 'internal://0817-9354-9770/pod/0',
                    'definition' => ['uiClass' => 'Pod'],
                ],
                [
                    'label' => 'balcony',
                    'deviceURL' => 'io://0817-9354-9770/15968589',
                    'definition' => ['uiClass' => 'Awning'],
                ],
            ]),
        ]);

        $deviceUrl = app(TaHomaService::class)->awningDeviceUrl();

        $this->assertSame('io://0817-9354-9770/15968589', $deviceUrl);
    }

    public function test_it_rejects_unsupported_actions(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported awning action [stop].');

        app(TaHomaService::class)->controlAwning('stop');
    }
}
