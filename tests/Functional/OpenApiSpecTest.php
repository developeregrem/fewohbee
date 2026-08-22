<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps docs/openapi.yaml and the actual routes from drifting apart.
 */
final class OpenApiSpecTest extends KernelTestCase
{
    private const BASE_PATH = '/api/v1';

    public function testSpecAndRouterDescribeTheSameEndpoints(): void
    {
        $spec = $this->loadSpec();

        $documented = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[strtoupper($method).' '.self::BASE_PATH.$path] = true;
            }
        }

        $routed = [];
        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            if (!str_starts_with($route->getPath(), self::BASE_PATH)) {
                continue;
            }
            foreach ($route->getMethods() ?: ['GET'] as $method) {
                $routed[$method.' '.$route->getPath()] = $name;
            }
        }

        foreach ($routed as $endpoint => $routeName) {
            self::assertArrayHasKey(
                $endpoint,
                $documented,
                sprintf('Route "%s" (%s) is missing from docs/openapi.yaml.', $routeName, $endpoint)
            );
        }

        foreach (array_keys($documented) as $endpoint) {
            self::assertArrayHasKey(
                $endpoint,
                $routed,
                sprintf('docs/openapi.yaml documents "%s", but no such route exists.', $endpoint)
            );
        }
    }

    public function testSpecDeclaresBothAuthenticationSchemes(): void
    {
        $spec = $this->loadSpec();

        self::assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);
        self::assertArrayHasKey('basicAuth', $spec['components']['securitySchemes']);
        self::assertSame('bearer', $spec['components']['securitySchemes']['bearerAuth']['scheme']);
        self::assertSame('basic', $spec['components']['securitySchemes']['basicAuth']['scheme']);
        self::assertNotEmpty($spec['security'], 'Security must be applied globally.');
    }

    public function testEveryOperationIsUsableAndDocumentsAuthErrors(): void
    {
        $spec = $this->loadSpec();

        $operationIds = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $label = strtoupper($method).' '.$path;

                self::assertArrayHasKey('operationId', $operation, $label.' needs an operationId.');
                self::assertNotContains(
                    $operation['operationId'],
                    $operationIds,
                    'Duplicate operationId: '.$operation['operationId']
                );
                $operationIds[] = $operation['operationId'];

                self::assertArrayHasKey('summary', $operation, $label.' needs a summary.');
                self::assertArrayHasKey('200', $operation['responses'], $label.' needs a 200 response.');
                self::assertArrayHasKey('401', $operation['responses'], $label.' must document 401.');
            }
        }
    }

    private function loadSpec(): array
    {
        self::bootKernel();
        $path = self::getContainer()->getParameter('kernel.project_dir').'/docs/openapi.yaml';
        self::assertFileExists($path);

        $spec = Yaml::parseFile($path);
        self::assertIsArray($spec);
        self::assertArrayHasKey('paths', $spec);

        return $spec;
    }
}
