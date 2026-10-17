<?php

declare(strict_types=1);

namespace App\Mcp\DependencyInjection;

use App\Mcp\Security\McpToolAuditor;
use App\Mcp\Security\ScopedReferenceHandler;
use App\Service\Mcp\McpSettings;
use Mcp\Capability\Registry\ReferenceHandler;
use Symfony\AI\McpBundle\Http\MiddlewareFactory;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Hardens the MCP bundle's "default" server where its configuration offers no hook:
 *
 * - installs ScopedReferenceHandler around the SDK's reference handler, so every tool call is
 *   authorized against #[McpRequiresScope] and audited;
 * - feeds the DNS rebinding allowlist from the settings at runtime (the bundle only accepts a
 *   literal array in its configuration);
 * - stops advertising list-changed notifications: the tools are static, and clients that see the
 *   capability open a subscriptions/listen stream that would pin a PHP-FPM worker for its whole
 *   lifetime (McpAccessSubscriber refuses such streams as well).
 *
 * Runs after the bundle's McpPass, which registers the tool service locator on the builder.
 */
final class McpServerPass implements CompilerPassInterface
{
    private const SERVER = 'default';

    public function process(ContainerBuilder $container): void
    {
        $builderId = 'mcp.server.'.self::SERVER.'.builder';
        if (!$container->hasDefinition($builderId)) {
            return;
        }

        $builder = $container->getDefinition($builderId);
        $locator = null;
        foreach ($builder->getMethodCalls() as [$method, $arguments]) {
            if ('setContainer' === $method) {
                $locator = $arguments[0];
            }
        }
        if (null === $locator) {
            throw new \LogicException('The MCP server "default" exposes no tool services; ScopedReferenceHandler cannot be installed.');
        }

        $container->register('app.mcp.reference_handler', ScopedReferenceHandler::class)
            ->setArguments([
                new Definition(ReferenceHandler::class, [$locator]),
                new Reference('security.authorization_checker'),
                new Reference(McpToolAuditor::class),
            ]);
        $builder->addMethodCall('setReferenceHandler', [new Reference('app.mcp.reference_handler')]);

        // Without a dispatcher the SDK announces no *ListChanged capability.
        $builder->removeMethodCall('setEventDispatcher');

        $middlewareFactoryId = 'mcp.server.'.self::SERVER.'.middleware_factory';
        if ($container->hasDefinition($middlewareFactoryId)) {
            $container->getDefinition($middlewareFactoryId)
                ->setClass(MiddlewareFactory::class)
                ->setFactory([self::class, 'createMiddlewareFactory'])
                ->setArguments([new Reference(McpSettings::class)]);
        }
    }

    /** Builds the bundle's middleware factory with the allowed hosts from the settings. */
    public static function createMiddlewareFactory(McpSettings $settings): MiddlewareFactory
    {
        return new MiddlewareFactory($settings->getAllowedHosts());
    }
}
