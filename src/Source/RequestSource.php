<?php

declare(strict_types=1);

namespace BetterData\Source;

use BetterData\DataObject;
use BetterData\Exception\CapabilityCheckFailedException;
use BetterData\Exception\NonceVerificationFailedException;
use BetterData\Exception\RequestParamCollisionException;

/**
 * Builder that hydrates a DataObject from a `WP_REST_Request` with
 * optional nonce, capability, and param-source guards.
 *
 * Guards execute in the order they were declared, before the payload is
 * read. A failing guard throws; a successful chain reaches `into()`,
 * which feeds a chosen param bucket to `DataObject::fromArray()`.
 *
 * ```php
 * $dto = RequestSource::from($request)
 *     ->requireNonce('my_plugin_save')
 *     ->requireCapability('manage_options')
 *     ->bodyOnly()
 *     ->noCollision(['id'])
 *     ->into(SaveSettingsDto::class);
 * ```
 *
 * Param-source scoping (`bodyOnly` / `jsonOnly` / `queryOnly` / `urlOnly`)
 * limits which bucket the DTO is built from. Default — no call — keeps
 * the existing WP-merged behaviour via `get_params()`, preserving
 * backward compatibility.
 *
 * `noCollision($routeOwnedFields)` asserts that none of the listed
 * fields appear in any client-controlled bucket (body, JSON, or query)
 * — only URL/route params are allowed to supply them. Use this to stop
 * a malicious body from overriding an id owned by the URL.
 *
 * Exceptions propagate — the handler chooses whether to catch and
 * translate to `WP_REST_Response` / `WP_Error`, or let the framework
 * above do it.
 */
final class RequestSource
{
    /**
     * @var list<callable(\WP_REST_Request): void>
     */
    private array $guards = [];

    /**
     * @var null|'body'|'json'|'query'|'url'
     */
    private ?string $source = null;

    private function __construct(private readonly \WP_REST_Request $request)
    {
    }

    public static function from(\WP_REST_Request $request): self
    {
        return new self($request);
    }

    /**
     * Verify a WP nonce. Reads from request parameter `$paramName` first,
     * falling back to the `X-WP-Nonce` header (WP REST standard).
     */
    public function requireNonce(string $action, string $paramName = '_wpnonce'): self
    {
        $this->guards[] = static function (\WP_REST_Request $request) use ($action, $paramName): void {
            $candidate = $request->get_param($paramName);
            if (!is_string($candidate) || $candidate === '') {
                $header = $request->get_header('x_wp_nonce');
                $candidate = is_string($header) ? $header : '';
            }

            if (!\function_exists('wp_verify_nonce') || !\wp_verify_nonce($candidate, $action)) {
                throw NonceVerificationFailedException::forAction($action);
            }
        };

        return $this;
    }

    /**
     * Require the current user to hold the given capability.
     * Optional context args (object id, etc.) are forwarded to `current_user_can`.
     */
    public function requireCapability(string $capability, mixed ...$args): self
    {
        $this->guards[] = static function () use ($capability, $args): void {
            if (!\function_exists('current_user_can') || !\current_user_can($capability, ...$args)) {
                throw CapabilityCheckFailedException::for($capability);
            }
        };

        return $this;
    }

    /**
     * Restrict hydration input to the request body params
     * (`WP_REST_Request::get_body_params()`).
     */
    public function bodyOnly(): self
    {
        $this->source = 'body';

        return $this;
    }

    /**
     * Restrict hydration input to the parsed JSON body
     * (`WP_REST_Request::get_json_params()`).
     */
    public function jsonOnly(): self
    {
        $this->source = 'json';

        return $this;
    }

    /**
     * Restrict hydration input to the query string
     * (`WP_REST_Request::get_query_params()`).
     */
    public function queryOnly(): self
    {
        $this->source = 'query';

        return $this;
    }

    /**
     * Restrict hydration input to URL/route params
     * (`WP_REST_Request::get_url_params()`) — path segments like `{id}`.
     */
    public function urlOnly(): self
    {
        $this->source = 'url';

        return $this;
    }

    /**
     * Assert that none of the listed field names appear in any
     * client-controlled bucket (body, JSON, or query). Only URL/route
     * params may supply them. Throws `RequestParamCollisionException`
     * when violated.
     *
     * @param list<string> $routeOwnedFields
     */
    public function noCollision(array $routeOwnedFields): self
    {
        $this->guards[] = static function (\WP_REST_Request $request) use ($routeOwnedFields): void {
            $clientBuckets = [
                $request->get_body_params(),
                (array) $request->get_json_params(),
                $request->get_query_params(),
            ];

            $collisions = [];
            foreach ($routeOwnedFields as $field) {
                foreach ($clientBuckets as $bucket) {
                    if (is_array($bucket) && array_key_exists($field, $bucket)) {
                        $collisions[] = $field;
                        continue 2;
                    }
                }
            }

            if ($collisions !== []) {
                throw RequestParamCollisionException::forFields($collisions);
            }
        };

        return $this;
    }

    /**
     * Run all guards, then hydrate.
     *
     * @template T of DataObject
     * @param class-string<T> $dtoClass
     * @return T
     */
    public function into(string $dtoClass): DataObject
    {
        foreach ($this->guards as $guard) {
            $guard($this->request);
        }

        $params = $this->resolveParams();

        return $dtoClass::fromArray($params);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveParams(): array
    {
        /** @var array<string, mixed> $params */
        $params = match ($this->source) {
            'body' => $this->request->get_body_params(),
            'json' => (array) $this->request->get_json_params(),
            'query' => $this->request->get_query_params(),
            'url' => $this->request->get_url_params(),
            default => $this->request->get_params(),
        };

        return $params;
    }
}
