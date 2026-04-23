<?php

declare(strict_types=1);

namespace BetterData\Source;

use BetterData\DataObject;
use BetterData\Exception\CapabilityCheckFailedException;
use BetterData\Exception\NonceVerificationFailedException;

/**
 * Builder that hydrates a DataObject from a `WP_REST_Request` with
 * optional nonce and capability guards.
 *
 * Guards execute in the order they were declared, before the payload is
 * read. A failing guard throws; a successful chain reaches `into()`,
 * which feeds `WP_REST_Request::get_params()` (URL + body + JSON merged
 * the WP standard way) to `DataObject::fromArray()`.
 *
 * ```php
 * $dto = RequestSource::from($request)
 *     ->requireNonce('my_plugin_save')
 *     ->requireCapability('manage_options')
 *     ->into(SaveSettingsDto::class);
 * ```
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

        /** @var array<string, mixed> $params */
        $params = $this->request->get_params();

        return $dtoClass::fromArray($params);
    }
}
