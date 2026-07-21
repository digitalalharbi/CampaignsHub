<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class HealthTest extends TestCase
{
    public function test_health_returns_envelope(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJson(['success' => true, 'data' => ['status' => 'ok']])
            ->assertJsonStructure(['success', 'message', 'data', 'meta' => ['request_id'], 'errors'])
            ->assertHeader('X-Request-Id');
    }

    public function test_unknown_route_returns_error_envelope(): void
    {
        $this->getJson('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertJson(['success' => false, 'data' => null]);
    }
}
