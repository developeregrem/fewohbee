<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Mcp\Security\McpToolException;

/**
 * Parsing helpers for MCP tool arguments. Failures become McpToolException with a message the
 * AI client can act on.
 */
final class McpInput
{
    public static function date(string $value, string $parameter): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$parsed instanceof \DateTimeImmutable || $parsed->format('Y-m-d') !== $value) {
            throw McpToolException::invalid(\sprintf("Invalid '%s': expected a date in the format YYYY-MM-DD.", $parameter));
        }

        return $parsed;
    }

    /** First day of the given YYYY-MM month. */
    public static function month(string $value, string $parameter): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value.'-01');
        if (!$parsed instanceof \DateTimeImmutable || $parsed->format('Y-m') !== $value) {
            throw McpToolException::invalid(\sprintf("Invalid '%s': expected a month in the format YYYY-MM.", $parameter));
        }

        return $parsed;
    }

    /**
     * Runs a callable of a shared API service and turns its \InvalidArgumentException into an
     * McpToolException with the same client-safe message.
     *
     * @template T
     *
     * @param callable(): T $callable
     *
     * @return T
     */
    public static function guard(callable $callable): mixed
    {
        try {
            return $callable();
        } catch (\InvalidArgumentException $e) {
            throw McpToolException::invalid($e->getMessage());
        }
    }
}
