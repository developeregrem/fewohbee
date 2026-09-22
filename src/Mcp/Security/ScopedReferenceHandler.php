<?php

declare(strict_types=1);

namespace App\Mcp\Security;

use App\Entity\Enum\ApiScope;
use App\Entity\Enum\McpToolCallOutcome;
use App\Security\Voter\ApiScopeVoter;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Central authorization and audit point for MCP tool calls, installed as the SDK's reference
 * handler by McpServerPass.
 *
 * Every tool call must pass the #[McpRequiresScope] declared on the tool (fail closed: a tool
 * without the attribute is refused), is timed and written to the audit log. Tools report expected
 * failures (bad input, unavailable room, ...) as McpToolException; it is converted into the SDK's
 * ToolCallException, whose message reaches the client. Anything else surfaces as the SDK's
 * generic "Error while executing tool".
 */
final class ScopedReferenceHandler implements ReferenceHandlerInterface
{
    public function __construct(
        private readonly ReferenceHandlerInterface $inner,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly McpToolAuditor $auditor,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function handle(ElementReference $reference, array $arguments): mixed
    {
        // Prompts only return static guidance; the firewall already required mcp:access.
        if (!$reference instanceof ToolReference) {
            return $this->inner->handle($reference, $arguments);
        }

        $toolName = $reference->tool->name;
        $startedAt = hrtime(true);

        $requiredScopes = self::requiredScopes($reference->handler);
        if (null === $requiredScopes) {
            $this->auditor->record($toolName, McpToolCallOutcome::DENIED, self::elapsedMs($startedAt));

            throw new ToolCallException('This tool is not available.');
        }

        foreach ($requiredScopes as $scope) {
            if (!$this->authorizationChecker->isGranted(ApiScopeVoter::attributeFor($scope))) {
                $this->auditor->record($toolName, McpToolCallOutcome::DENIED, self::elapsedMs($startedAt));

                throw new ToolCallException(\sprintf('This access token lacks the permission "%s" (or its owner lacks the matching role).', $scope->value));
            }
        }

        try {
            $result = $this->inner->handle($reference, $arguments);
        } catch (\Throwable $e) {
            [$outcome, $rethrow] = match (true) {
                $e instanceof McpToolException => [$e->outcome, new ToolCallException($e->getMessage(), 0, $e)],
                $e instanceof ToolCallException => [McpToolCallOutcome::INVALID, $e],
                default => [McpToolCallOutcome::ERROR, $e],
            };
            $this->auditor->record($toolName, $outcome, self::elapsedMs($startedAt));

            throw $rethrow;
        }

        $this->auditor->record($toolName, McpToolCallOutcome::OK, self::elapsedMs($startedAt));

        return $result;
    }

    /**
     * Resolves the scopes a tool handler declares through #[McpRequiresScope] on its method or,
     * failing that, its class. Returns null when neither declares one.
     *
     * @param \Closure|array{0: object|string, 1: string}|string $handler
     *
     * @return list<ApiScope>|null
     */
    public static function requiredScopes(\Closure|array|string $handler): ?array
    {
        if ($handler instanceof \Closure) {
            return null;
        }

        if (\is_string($handler)) {
            $handler = str_contains($handler, '::') ? explode('::', $handler, 2) : [$handler, '__invoke'];
        }

        [$class, $method] = $handler;
        if (!(\is_object($class) || class_exists($class)) || !method_exists($class, $method)) {
            return null;
        }

        $reflectionMethod = new \ReflectionMethod($class, $method);
        $attributes = $reflectionMethod->getAttributes(McpRequiresScope::class);
        if ([] === $attributes) {
            $attributes = $reflectionMethod->getDeclaringClass()->getAttributes(McpRequiresScope::class);
        }
        if ([] === $attributes) {
            return null;
        }

        return $attributes[0]->newInstance()->scopes;
    }

    private static function elapsedMs(int|float $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
