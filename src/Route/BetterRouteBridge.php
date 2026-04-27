<?php

declare(strict_types=1);

namespace BetterData\Route;

use BetterData\DataObject;
use BetterData\Exception\DataObjectException;
use BetterData\Exception\RequestGuardException;
use BetterData\Exception\RequestParamCollisionException;
use BetterData\Exception\ValidationException;
use BetterData\Presenter\PresentationContext;
use BetterData\Presenter\Presenter;
use BetterData\Registration\MetaKeyRegistry;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;

/**
 * Optional adapter between better-data DTOs and better-route's fluent
 * router. Kept dependency-light on purpose: the bridge talks to
 * Router/RouteBuilder-compatible objects by method name so better-data
 * can be installed without requiring better-route at runtime.
 */
final class BetterRouteBridge
{
    /**
     * @var array<string, string>
     */
    private const ROUTER_METHODS = [
        'GET' => 'get',
        'POST' => 'post',
        'PUT' => 'put',
        'PATCH' => 'patch',
        'DELETE' => 'delete',
    ];

    /**
     * @var list<string>
     */
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH'];

    /**
     * @var list<string>
     */
    private const SOURCES = ['auto', 'merged', 'json', 'body', 'query', 'url'];

    /**
     * @param class-string<DataObject> $dtoClass
     * @param array<string, mixed>     $options
     */
    public static function get(
        object $router,
        string $uri,
        string $dtoClass,
        callable $handler,
        array $options = [],
    ): object {
        return self::register($router, 'GET', $uri, $dtoClass, $handler, $options);
    }

    /**
     * @param class-string<DataObject> $dtoClass
     * @param array<string, mixed>     $options
     */
    public static function post(
        object $router,
        string $uri,
        string $dtoClass,
        callable $handler,
        array $options = [],
    ): object {
        return self::register($router, 'POST', $uri, $dtoClass, $handler, $options);
    }

    /**
     * @param class-string<DataObject> $dtoClass
     * @param array<string, mixed>     $options
     */
    public static function put(
        object $router,
        string $uri,
        string $dtoClass,
        callable $handler,
        array $options = [],
    ): object {
        return self::register($router, 'PUT', $uri, $dtoClass, $handler, $options);
    }

    /**
     * @param class-string<DataObject> $dtoClass
     * @param array<string, mixed>     $options
     */
    public static function patch(
        object $router,
        string $uri,
        string $dtoClass,
        callable $handler,
        array $options = [],
    ): object {
        return self::register($router, 'PATCH', $uri, $dtoClass, $handler, $options);
    }

    /**
     * @param class-string<DataObject> $dtoClass
     * @param array<string, mixed>     $options
     */
    public static function delete(
        object $router,
        string $uri,
        string $dtoClass,
        callable $handler,
        array $options = [],
    ): object {
        return self::register($router, 'DELETE', $uri, $dtoClass, $handler, $options);
    }

    /**
     * Register a DTO-backed route on a better-route Router-compatible
     * object and return the produced RouteBuilder-compatible object.
     *
     * Supported options:
     *  - source: auto|merged|json|body|query|url
     *  - routeFields: list of URL-owned DTO fields, e.g. ['id']
     *  - validate: bool, defaults true
     *  - envelope: bool, wraps normalized handler result in ['data' => ...]
     *  - args: array override, or false to skip builder->args()
     *  - meta: extra route meta merged over generated better-route meta
     *  - permissionCallback: forwarded to builder->permission()
     *  - middlewares: list forwarded to builder->middleware()
     *
     * @param class-string<DataObject> $dtoClass
     * @param array<string, mixed>     $options
     */
    public static function register(
        object $router,
        string $method,
        string $uri,
        string $dtoClass,
        callable $handler,
        array $options = [],
    ): object {
        self::assertDataObjectClass($dtoClass);

        $method = strtoupper($method);
        $routerMethod = self::ROUTER_METHODS[$method] ?? null;
        if ($routerMethod === null) {
            throw new InvalidArgumentException(sprintf('Unsupported HTTP method "%s".', $method));
        }

        $options = self::withMethodDefault($options, $method);
        $builder = self::invokeObjectMethod($router, $routerMethod, [
            $uri,
            self::handler($dtoClass, $handler, $options),
        ]);

        if (!is_object($builder)) {
            throw new InvalidArgumentException('Router method must return a RouteBuilder-compatible object.');
        }

        self::configureBuilder($builder, $dtoClass, $method, $uri, $options);

        return $builder;
    }

    /**
     * Build a better-route-compatible handler that hydrates a DTO from
     * the incoming request, validates it, invokes the user callback, and
     * normalizes DataObject results through Presenter with
     * PresentationContext::rest().
     *
     * @param class-string<DataObject> $dtoClass
     * @param array<string, mixed>     $options
     */
    public static function handler(string $dtoClass, callable $handler, array $options = []): \Closure
    {
        self::assertDataObjectClass($dtoClass);

        return static function (mixed $request) use ($dtoClass, $handler, $options): mixed {
            try {
                $dto = self::hydrate($request, $dtoClass, $options);
                $result = self::invokeHandler($handler, $dto, $request);

                return self::presentResult($result, $options);
            } catch (ValidationException $exception) {
                self::throwRouteError(
                    'Invalid request.',
                    400,
                    'validation_failed',
                    ['fieldErrors' => $exception->errors()],
                );
            } catch (RequestParamCollisionException $exception) {
                self::throwRouteError(
                    $exception->getMessage(),
                    400,
                    'request_param_collision',
                    [],
                );
            } catch (RequestGuardException $exception) {
                self::throwRouteError(
                    $exception->getMessage(),
                    403,
                    'request_guard_failed',
                    [],
                );
            } catch (DataObjectException $exception) {
                $details = [];
                $field = $exception->getFieldName();
                if ($field !== null) {
                    $details['fieldErrors'] = [$field => [$exception->getMessage()]];
                }

                self::throwRouteError(
                    'Invalid request.',
                    400,
                    'validation_failed',
                    $details,
                );
            }
        };
    }

    /**
     * Hydrate a DTO from a request-shaped object/array. When routeFields
     * are configured, URL params are merged as authoritative values and
     * the same keys are rejected from JSON/body/query buckets.
     *
     * @template T of DataObject
     * @param class-string<T>      $dtoClass
     * @param array<string, mixed> $options
     * @return T
     */
    public static function hydrate(mixed $request, string $dtoClass, array $options = []): DataObject
    {
        self::assertDataObjectClass($dtoClass);

        $source = self::sourceFromOptions($options);
        $payload = $source === 'auto'
            ? self::autoPayload($request)
            : self::requestBucket($request, $source);

        $routeFields = self::routeFields($options);
        if ($routeFields !== []) {
            self::assertNoRouteFieldCollisions($request, $routeFields);
            $url = self::requestBucket($request, 'url');
            foreach ($routeFields as $field) {
                if (array_key_exists($field, $url)) {
                    $payload[$field] = $url[$field];
                }
            }
        }

        /** @var T $dto */
        $dto = $dtoClass::fromArray($payload);
        if (($options['validate'] ?? true) === true) {
            $dto->validate()->throwIfInvalid();
        }

        return $dto;
    }

    /**
     * Build the better-route args map from a DTO.
     *
     * @param class-string<DataObject> $dtoClass
     * @param array<string, mixed>     $options
     * @return array<string, array<string, mixed>>
     */
    public static function args(string $dtoClass, array $options = []): array
    {
        self::assertDataObjectClass($dtoClass);

        $args = MetaKeyRegistry::toRestArgs($dtoClass);
        foreach (self::routeFields($options) as $field) {
            if (isset($args[$field])) {
                $args[$field]['required'] = true;
            }
        }

        return $args;
    }

    /**
     * Generate better-route meta for OpenAPI export.
     *
     * @param class-string<DataObject> $dtoClass
     * @param array<string, mixed>     $options
     * @return array<string, mixed>
     */
    public static function meta(string $dtoClass, array $options = []): array
    {
        self::assertDataObjectClass($dtoClass);

        $method = strtoupper((string) ($options['method'] ?? 'POST'));
        $responseDto = self::responseDtoClass($dtoClass, $options);

        $responseSchema = array_key_exists('responseSchema', $options)
            ? self::stringOrNull($options['responseSchema'])
            : self::schemaRef($responseDto, self::stringOrNull($options['responseSchemaName'] ?? null));

        $meta = [
            'tags' => self::stringList($options['tags'] ?? []) ?: [self::schemaName($dtoClass)],
            'parameters' => self::parameters($dtoClass, $options),
        ];

        if ($responseSchema !== null && $responseSchema !== '') {
            $meta['responseSchema'] = $responseSchema;
        }

        $operationId = self::stringOrNull($options['operationId'] ?? null);
        if ($operationId !== null && $operationId !== '') {
            $meta['operationId'] = $operationId;
        }

        if (in_array($method, self::WRITE_METHODS, true)) {
            $requestSchema = array_key_exists('requestSchema', $options)
                ? self::stringOrNull($options['requestSchema'])
                : self::schemaRef($dtoClass, self::stringOrNull($options['requestSchemaName'] ?? null));

            if ($requestSchema !== null && $requestSchema !== '') {
                $meta['requestSchema'] = $requestSchema;
            }
        }

        $scopes = self::stringList($options['scopes'] ?? []);
        if ($scopes !== []) {
            $meta['scopes'] = $scopes;
        }

        if (array_key_exists('security', $options)) {
            $meta['security'] = $options['security'];
        }

        if (array_key_exists('openapi', $options) && is_array($options['openapi'])) {
            $meta['openapi'] = $options['openapi'];
        }

        return $meta;
    }

    /**
     * Build OpenAPI components suitable for BetterRoute\OpenApiExporter.
     *
     * @param array<int|string, class-string<DataObject>> $dtoClasses
     * @return array{schemas: array<string, array<string, mixed>>}
     */
    public static function openApiComponents(array $dtoClasses): array
    {
        $schemas = [];
        foreach ($dtoClasses as $name => $dtoClass) {
            self::assertDataObjectClass($dtoClass);
            $schemaName = is_string($name) ? $name : self::schemaName($dtoClass);
            $schemas[$schemaName] = MetaKeyRegistry::toJsonSchema($dtoClass);
        }

        return ['schemas' => $schemas];
    }

    /**
     * @param class-string<DataObject> $dtoClass
     */
    public static function schemaRef(string $dtoClass, ?string $schemaName = null): string
    {
        self::assertDataObjectClass($dtoClass);

        return '#/components/schemas/' . ($schemaName ?? self::schemaName($dtoClass));
    }

    /**
     * @param class-string<DataObject> $dtoClass
     */
    public static function schemaName(string $dtoClass): string
    {
        self::assertDataObjectClass($dtoClass);

        $shortName = (new ReflectionClass($dtoClass))->getShortName();
        if (str_ends_with($shortName, 'Dto')) {
            return substr($shortName, 0, -3);
        }

        return $shortName;
    }

    /**
     * @param class-string<DataObject> $dtoClass
     * @param array<string, mixed>     $options
     * @return list<array<string, mixed>>
     */
    public static function parameters(string $dtoClass, array $options = []): array
    {
        $args = self::args($dtoClass, $options);
        $routeFields = self::routeFields($options);
        $source = self::sourceFromOptions($options);
        $parameters = [];

        foreach ($args as $name => $arg) {
            $in = in_array($name, $routeFields, true) ? 'path' : null;
            if ($in === null && in_array($source, ['query', 'merged'], true)) {
                $in = 'query';
            }

            if ($in === null) {
                continue;
            }

            $schema = $arg;
            unset($schema['required'], $schema['description'], $schema['sanitize_callback'], $schema['validate_callback']);

            $parameter = [
                'in' => $in,
                'name' => $name,
                'required' => $in === 'path' || (($arg['required'] ?? false) === true),
                'schema' => $schema,
            ];

            if (isset($arg['description']) && is_string($arg['description']) && $arg['description'] !== '') {
                $parameter['description'] = $arg['description'];
            }

            $parameters[] = $parameter;
        }

        return $parameters;
    }

    /**
     * @param class-string<DataObject> $dtoClass
     * @param array<string, mixed>     $options
     */
    private static function configureBuilder(
        object $builder,
        string $dtoClass,
        string $method,
        string $uri,
        array $options,
    ): void {
        $args = $options['args'] ?? null;
        if ($args !== false) {
            self::invokeObjectMethod($builder, 'args', [
                is_array($args) ? $args : self::args($dtoClass, $options),
            ]);
        }

        $generatedMeta = self::meta($dtoClass, $options + ['method' => $method, 'uri' => $uri]);
        $extraMeta = is_array($options['meta'] ?? null) ? $options['meta'] : [];
        self::invokeObjectMethod($builder, 'meta', [
            array_replace_recursive($generatedMeta, $extraMeta),
        ]);

        $permission = $options['permissionCallback'] ?? null;
        if (is_callable($permission)) {
            self::invokeObjectMethod($builder, 'permission', [$permission]);
        }

        $middlewares = $options['middlewares'] ?? null;
        if (is_array($middlewares) && $middlewares !== []) {
            self::invokeObjectMethod($builder, 'middleware', [$middlewares]);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function withMethodDefault(array $options, string $method): array
    {
        if (!isset($options['method'])) {
            $options['method'] = $method;
        }

        if (!isset($options['source'])) {
            $options['source'] = match ($method) {
                'GET' => 'query',
                'DELETE' => 'url',
                default => 'auto',
            };
        }

        return $options;
    }

    /**
     * @param list<mixed> $args
     */
    private static function invokeObjectMethod(object $target, string $method, array $args): mixed
    {
        $callable = [$target, $method];
        if (!is_callable($callable)) {
            throw new InvalidArgumentException(sprintf(
                'Object of class %s must expose method %s().',
                $target::class,
                $method,
            ));
        }

        return $callable(...$args);
    }

    private static function invokeHandler(callable $handler, DataObject $dto, mixed $request): mixed
    {
        try {
            $reflection = self::reflectCallable($handler);
            $args = [$dto, $request];

            if ($reflection->isVariadic()) {
                return $handler(...$args);
            }

            return $handler(...array_slice($args, 0, $reflection->getNumberOfParameters()));
        } catch (ReflectionException) {
            return $handler($dto);
        }
    }

    private static function reflectCallable(callable $handler): ReflectionFunction|ReflectionMethod
    {
        if (is_array($handler) && count($handler) === 2) {
            return new ReflectionMethod($handler[0], (string) $handler[1]);
        }

        if (is_object($handler) && !$handler instanceof \Closure) {
            return new ReflectionMethod($handler, '__invoke');
        }

        return new ReflectionFunction(\Closure::fromCallable($handler));
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function presentResult(mixed $result, array $options): mixed
    {
        $presented = self::presentValue($result);

        return ($options['envelope'] ?? false) === true
            ? ['data' => $presented]
            : $presented;
    }

    private static function presentValue(mixed $value): mixed
    {
        if ($value instanceof DataObject) {
            return Presenter::for($value)
                ->context(PresentationContext::rest())
                ->toArray();
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::presentValue($item);
            }

            return $out;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function sourceFromOptions(array $options): string
    {
        $source = $options['source'] ?? 'merged';
        if (!is_string($source)) {
            throw new InvalidArgumentException('Route source must be a string.');
        }

        $source = strtolower($source);
        if (!in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Route source must be one of: %s.',
                implode(', ', self::SOURCES),
            ));
        }

        return $source;
    }

    /**
     * @return array<string, mixed>
     */
    private static function autoPayload(mixed $request): array
    {
        $json = self::requestBucket($request, 'json');
        if ($json !== []) {
            return $json;
        }

        $body = self::requestBucket($request, 'body');
        if ($body !== []) {
            return $body;
        }

        return self::requestBucket($request, 'merged');
    }

    /**
     * @return array<string, mixed>
     */
    private static function requestBucket(mixed $request, string $bucket): array
    {
        if (is_object($request)) {
            $method = match ($bucket) {
                'json' => 'get_json_params',
                'body' => 'get_body_params',
                'query' => 'get_query_params',
                'url' => 'get_url_params',
                default => 'get_params',
            };

            if (method_exists($request, $method)) {
                return self::stringKeyedArray($request->{$method}());
            }

            return [];
        }

        if (!is_array($request)) {
            return [];
        }

        if ($bucket === 'merged') {
            if (isset($request['params']) && is_array($request['params'])) {
                return self::stringKeyedArray($request['params']);
            }

            return self::stringKeyedArray($request);
        }

        $value = $request[$bucket] ?? [];

        return self::stringKeyedArray($value);
    }

    /**
     * @return array<string, mixed>
     */
    private static function stringKeyedArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $out[$key] = $item;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $routeFields
     */
    private static function assertNoRouteFieldCollisions(mixed $request, array $routeFields): void
    {
        $clientBuckets = [
            self::requestBucket($request, 'json'),
            self::requestBucket($request, 'body'),
            self::requestBucket($request, 'query'),
        ];

        $collisions = [];
        foreach ($routeFields as $field) {
            foreach ($clientBuckets as $bucket) {
                if (array_key_exists($field, $bucket)) {
                    $collisions[] = $field;
                    continue 2;
                }
            }
        }

        if ($collisions !== []) {
            throw RequestParamCollisionException::forFields($collisions);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return list<string>
     */
    private static function routeFields(array $options): array
    {
        $fields = $options['routeFields'] ?? ($options['routeOwnedFields'] ?? []);

        return self::stringList($fields);
    }

    /**
     * @param class-string<DataObject> $fallback
     * @param array<string, mixed>     $options
     * @return class-string<DataObject>
     */
    private static function responseDtoClass(string $fallback, array $options): string
    {
        $responseDto = $options['responseDto'] ?? $fallback;
        if (!is_string($responseDto)) {
            throw new InvalidArgumentException('responseDto must be a DataObject class-string.');
        }

        self::assertDataObjectClass($responseDto);

        return $responseDto;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return array_values($out);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * @phpstan-assert class-string<DataObject> $dtoClass
     */
    private static function assertDataObjectClass(string $dtoClass): void
    {
        if (!is_subclass_of($dtoClass, DataObject::class)) {
            throw new InvalidArgumentException(sprintf(
                'Expected a BetterData\\DataObject class-string, got "%s".',
                $dtoClass,
            ));
        }
    }

    /**
     * @param array<string, mixed> $details
     */
    private static function throwRouteError(string $message, int $status, string $code, array $details): never
    {
        $apiException = 'BetterRoute\\Http\\ApiException';
        if (class_exists($apiException)) {
            throw new $apiException($message, $status, $code, $details);
        }

        throw new InvalidArgumentException($message);
    }
}
