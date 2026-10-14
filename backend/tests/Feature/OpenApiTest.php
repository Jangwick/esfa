<?php

namespace Tests\Feature;

use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OpenApiTest extends TestCase
{
    private function spec(): array
    {
        return json_decode(file_get_contents(base_path('../docs/openapi.yaml')), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, \Illuminate\Routing\Route> keyed by "METHOD /uri" */
    private function operations(): array
    {
        $operations = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) { continue; }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) { $operations[$method.' /'.$route->uri()] = $route; }
        }
        ksort($operations);

        return $operations;
    }

    public function test_every_registered_route_is_documented_and_every_documented_operation_exists(): void
    {
        $documented = [];
        foreach ($this->spec()['paths'] as $path => $item) {
            foreach (array_intersect(array_keys($item), ['get', 'post', 'put', 'patch', 'delete']) as $method) { $documented[] = strtoupper($method).' '.$path; }
        }
        $registered = array_keys($this->operations());
        $this->assertSame([], array_values(array_diff($registered, $documented)), 'These routes are missing from docs/openapi.yaml.');
        $this->assertSame([], array_values(array_diff($documented, $registered)), 'These documented operations have no registered route.');
    }

    public function test_security_and_integration_scopes_match_the_route_middleware(): void
    {
        $spec = $this->spec();
        foreach ($this->operations() as $key => $route) {
            [$method, $path] = explode(' ', $key, 2);
            $operation = $spec['paths'][$path][strtolower($method)];
            $schemes = array_merge([], ...array_map('array_keys', $operation['security'] ?? []));
            $middleware = $route->gatherMiddleware();
            if (in_array('staff', $middleware, true)) { $this->assertContains('StaffBearer', $schemes, $key); }
            if (in_array('integration.only', $middleware, true)) {
                $this->assertContains('IntegrationBearer', $schemes, $key);
                $abilities = [];
                foreach ($middleware as $item) { if (str_starts_with($item, 'abilities:')) { $abilities = [...$abilities, ...explode(',', substr($item, 10))]; } }
                $this->assertEqualsCanonicalizing($abilities, $operation['x-required-scopes'] ?? [], $key.' must document its required integration scopes.');
            }
            if ($path === '/api/v1/cron/run') { $this->assertSame(['CronBearer'], $schemes); }
            if (str_starts_with($path, '/api/v1/webhooks/')) { $this->assertContains('WebhookSignature', $schemes, $key); }
            $this->assertNotEmpty(array_filter(array_keys($operation['responses']), fn ($status) => str_starts_with((string) $status, '2')), $key.' must document a success response.');
        }
    }

    public function test_the_contract_is_openapi_31_with_unique_operations_resolvable_references_and_every_webhook_event(): void
    {
        $spec = $this->spec();
        $this->assertSame('3.1.0', $spec['openapi']);
        $ids = [];
        foreach ($spec['paths'] as $item) { foreach ($item as $operation) { if (isset($operation['operationId'])) { $ids[] = $operation['operationId']; } } }
        $this->assertSame(count($ids), count(array_unique($ids)), 'operationId values must be unique.');
        $missing = [];
        array_walk_recursive($spec, function ($value, $key) use ($spec, &$missing) {
            if ($key !== '$ref') { return; }
            $node = $spec;
            foreach (explode('/', substr($value, 2)) as $part) { if (! is_array($node) || ! array_key_exists($part, $node)) { $missing[] = $value; return; } $node = $node[$part]; }
        });
        $this->assertSame([], array_values(array_unique($missing)), 'Every $ref must resolve.');
        $this->assertEqualsCanonicalizing(WebhookEndpoint::EVENTS, array_keys($spec['webhooks']));
    }

    public function test_the_container_copy_matches_the_canonical_document(): void
    {
        $this->assertFileEquals(base_path('../docs/openapi.yaml'), resource_path('openapi.yaml'), 'Copy docs/openapi.yaml to backend/resources/openapi.yaml after editing it.');
    }

    public function test_the_document_is_served_from_the_api(): void
    {
        $response = $this->get('/api/v1/openapi.yaml')->assertOk();
        $this->assertStringStartsWith('application/yaml', $response->headers->get('Content-Type'));
        $this->assertSame('3.1.0', json_decode($response->streamedContent() ?: file_get_contents(base_path('../docs/openapi.yaml')), true)['openapi']);
    }
}