<?php

namespace Tests\Unit;

use Illuminate\Http\Request;
use Tests\TestCase;

class KycInvitationRoutesTest extends TestCase
{
    public function test_invitation_generation_and_sms_routes_require_staff_permissions(): void
    {
        foreach ([['GET', 'staff/kyc/invite', false], ['POST', 'staff/kyc/invite', false],
            ['POST', 'staff/kyc/invite/sms', true]] as [$method, $path, $sms]) {
            $route = app('router')->getRoutes()->match(Request::create('/t/haven/'.$path, $method));
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth', $middleware);
            $this->assertContains('tenant.member', $middleware);
            $this->assertContains('permission:finance.manage', $middleware);
            if ($sms) $this->assertContains('permission:messaging.manage', $middleware);
            if ($method === 'POST') {
                $this->assertTrue((bool) array_filter($middleware, fn ($item) => is_string($item) && str_starts_with($item, 'throttle:')));
            }
        }
    }
}
