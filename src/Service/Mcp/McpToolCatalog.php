<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Mcp\Security\ScopedReferenceHandler;
use Mcp\Capability\Attribute\McpTool;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Which MCP tools each token scope unlocks, read from #[McpTool] and #[McpRequiresScope] on the
 * tool classes, so the token form never has to list tools by hand.
 */
final class McpToolCatalog
{
    private const TOOL_NAMESPACE = 'App\\Mcp\\Tool\\';

    /** @var array<string, list<string>>|null */
    private ?array $toolsByScope = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/src/Mcp/Tool')]
        private readonly string $toolDirectory,
    ) {
    }

    /**
     * @return array<string, list<string>> scope value => names of the tools that require it
     */
    public function toolsByScope(): array
    {
        if (null !== $this->toolsByScope) {
            return $this->toolsByScope;
        }

        $toolsByScope = [];
        foreach (glob($this->toolDirectory.'/*.php') ?: [] as $file) {
            $class = self::TOOL_NAMESPACE.basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $attribute = $method->getAttributes(McpTool::class)[0] ?? null;
                if (null === $attribute) {
                    continue;
                }
                $name = $attribute->newInstance()->name ?? $method->getName();
                foreach (ScopedReferenceHandler::requiredScopes([$class, $method->getName()]) ?? [] as $scope) {
                    $toolsByScope[$scope->value][] = $name;
                }
            }
        }
        foreach ($toolsByScope as &$tools) {
            sort($tools);
        }
        unset($tools);

        return $this->toolsByScope = $toolsByScope;
    }
}
