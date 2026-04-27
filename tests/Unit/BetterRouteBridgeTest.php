<?php

declare(strict_types=1);

namespace BetterData\Tests\Unit;

use BetterData\Exception\RequestParamCollisionException;
use BetterData\Route\BetterRouteBridge;
use BetterData\Tests\Fixtures\RouteWidgetDto;
use BetterData\Tests\Fixtures\SchemaTestDto;
use PHPUnit\Framework\TestCase;

final class BetterRouteBridgeTest extends TestCase
{
    public function testRegistersDtoBackedPostRoute(): void
    {
        $router = new FakeBetterRouteRouter();

        $builder = BetterRouteBridge::post(
            $router,
            '/widgets',
            SchemaTestDto::class,
            static fn (SchemaTestDto $dto): SchemaTestDto => $dto->with(['age' => 42]),
            [
                'operationId' => 'widgetsCreate',
                'tags' => ['Widgets'],
                'envelope' => true,
            ],
        );

        self::assertSame($router->routes[0]['builder'], $builder);
        self::assertSame('POST', $router->routes[0]['method']);
        self::assertSame('/widgets', $router->routes[0]['uri']);
        self::assertTrue($builder->args['email']['required']);
        self::assertSame('widgetsCreate', $builder->meta['operationId']);
        self::assertSame(['Widgets'], $builder->meta['tags']);
        self::assertSame('#/components/schemas/SchemaTest', $builder->meta['requestSchema']);
        self::assertSame('#/components/schemas/SchemaTest', $builder->meta['responseSchema']);

        $response = ($router->routes[0]['handler'])(new FakeBetterRouteRequest(
            json: [
                'email' => 'jane@example.com',
                'name' => 'Jane',
            ],
        ));

        self::assertSame('jane@example.com', $response['data']['email']);
        self::assertSame(42, $response['data']['age']);
    }

    public function testRouteFieldsAreMergedFromUrlParamsAndProtectedFromBodyCollision(): void
    {
        $request = new FakeBetterRouteRequest(
            json: ['name' => 'Updated'],
            url: ['id' => '12'],
        );

        $dto = BetterRouteBridge::hydrate($request, RouteWidgetDto::class, [
            'source' => 'json',
            'routeFields' => ['id'],
        ]);

        self::assertSame(12, $dto->id);
        self::assertSame('Updated', $dto->name);

        $this->expectException(RequestParamCollisionException::class);
        $this->expectExceptionMessage('Route-owned field(s) appeared in client-controlled payload: id');

        BetterRouteBridge::hydrate(
            new FakeBetterRouteRequest(
                json: ['id' => '99', 'name' => 'Updated'],
                url: ['id' => '12'],
            ),
            RouteWidgetDto::class,
            [
                'source' => 'json',
                'routeFields' => ['id'],
            ],
        );
    }

    public function testQueryRouteBuildsOpenApiQueryParameters(): void
    {
        $meta = BetterRouteBridge::meta(RouteWidgetDto::class, [
            'method' => 'GET',
            'source' => 'query',
        ]);

        self::assertSame('query', $meta['parameters'][0]['in']);
        self::assertSame('id', $meta['parameters'][0]['name']);
        self::assertSame(['type' => 'integer'], $meta['parameters'][0]['schema']);
        self::assertArrayNotHasKey('requestSchema', $meta);
    }

    public function testPathParametersAreGeneratedForRouteFields(): void
    {
        $meta = BetterRouteBridge::meta(RouteWidgetDto::class, [
            'method' => 'PATCH',
            'source' => 'json',
            'routeFields' => ['id'],
        ]);

        self::assertSame('path', $meta['parameters'][0]['in']);
        self::assertSame('id', $meta['parameters'][0]['name']);
        self::assertTrue($meta['parameters'][0]['required']);
        self::assertSame('#/components/schemas/RouteWidget', $meta['requestSchema']);
    }

    public function testOpenApiComponentsAreBuiltFromDtoSchemas(): void
    {
        $components = BetterRouteBridge::openApiComponents([
            SchemaTestDto::class,
            'WidgetWrite' => RouteWidgetDto::class,
        ]);

        self::assertArrayHasKey('SchemaTest', $components['schemas']);
        self::assertArrayHasKey('WidgetWrite', $components['schemas']);
        self::assertSame('object', $components['schemas']['SchemaTest']['type']);
        self::assertSame('integer', $components['schemas']['WidgetWrite']['properties']['id']['type']);
    }

    public function testDataObjectResultsArePresentedRecursively(): void
    {
        $handler = BetterRouteBridge::handler(
            RouteWidgetDto::class,
            static fn (RouteWidgetDto $dto): array => [
                'item' => $dto,
                'items' => [$dto->with(['id' => 2])],
            ],
            ['source' => 'json'],
        );

        $response = $handler(new FakeBetterRouteRequest(json: [
            'id' => '1',
            'name' => 'Widget',
        ]));

        self::assertSame(1, $response['item']['id']);
        self::assertSame(2, $response['items'][0]['id']);
    }
}

final class FakeBetterRouteRouter
{
    /**
     * @var list<array{method: string, uri: string, handler: callable, builder: FakeBetterRouteBuilder}>
     */
    public array $routes = [];

    public function get(string $uri, callable $handler): FakeBetterRouteBuilder
    {
        return $this->map('GET', $uri, $handler);
    }

    public function post(string $uri, callable $handler): FakeBetterRouteBuilder
    {
        return $this->map('POST', $uri, $handler);
    }

    public function put(string $uri, callable $handler): FakeBetterRouteBuilder
    {
        return $this->map('PUT', $uri, $handler);
    }

    public function patch(string $uri, callable $handler): FakeBetterRouteBuilder
    {
        return $this->map('PATCH', $uri, $handler);
    }

    public function delete(string $uri, callable $handler): FakeBetterRouteBuilder
    {
        return $this->map('DELETE', $uri, $handler);
    }

    private function map(string $method, string $uri, callable $handler): FakeBetterRouteBuilder
    {
        $builder = new FakeBetterRouteBuilder();
        $this->routes[] = [
            'method' => $method,
            'uri' => $uri,
            'handler' => $handler,
            'builder' => $builder,
        ];

        return $builder;
    }
}

final class FakeBetterRouteBuilder
{
    /**
     * @var array<string, mixed>
     */
    public array $args = [];

    /**
     * @var array<string, mixed>
     */
    public array $meta = [];

    /**
     * @var list<mixed>
     */
    public array $middlewares = [];

    public mixed $permissionCallback = null;

    /**
     * @param array<string, mixed> $args
     */
    public function args(array $args): self
    {
        $this->args = $args;

        return $this;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function meta(array $meta): self
    {
        $this->meta = $meta;

        return $this;
    }

    /**
     * @param list<mixed> $middlewares
     */
    public function middleware(array $middlewares): self
    {
        $this->middlewares = $middlewares;

        return $this;
    }

    public function permission(callable $permissionCallback): self
    {
        $this->permissionCallback = $permissionCallback;

        return $this;
    }
}

final readonly class FakeBetterRouteRequest
{
    /**
     * @param array<string, mixed> $json
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     * @param array<string, mixed> $url
     * @param array<string, mixed> $params
     */
    public function __construct(
        private array $json = [],
        private array $body = [],
        private array $query = [],
        private array $url = [],
        private array $params = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function get_json_params(): array
    {
        return $this->json;
    }

    /**
     * @return array<string, mixed>
     */
    public function get_body_params(): array
    {
        return $this->body;
    }

    /**
     * @return array<string, mixed>
     */
    public function get_query_params(): array
    {
        return $this->query;
    }

    /**
     * @return array<string, mixed>
     */
    public function get_url_params(): array
    {
        return $this->url;
    }

    /**
     * @return array<string, mixed>
     */
    public function get_params(): array
    {
        return $this->params !== []
            ? $this->params
            : array_replace($this->query, $this->body, $this->json, $this->url);
    }
}
