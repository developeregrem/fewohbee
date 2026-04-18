<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\ErrorRenderer\HtmlErrorRenderer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies that production-like environments are configured to hide internal
 * error details (stack traces, exception messages, file paths) from users.
 *
 * Background: APP_DEBUG must be false in prod/redis environments.
 * This is enforced via "extra.runtime.prod_envs" in composer.json, which
 * instructs symfony/runtime to disable debug mode for those environments.
 */
final class ProductionErrorRenderingTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function productionLikeEnvironmentsProvider(): iterable
    {
        yield 'prod' => ['prod'];
        yield 'redis' => ['redis'];
    }

    /**
     * Ensures that composer.json declares both prod and redis as production-like
     * environments so that symfony/runtime keeps APP_DEBUG=false for them.
     */
    #[DataProvider('productionLikeEnvironmentsProvider')]
    public function testRuntimeProdEnvsContainsEnvironment(string $environment): void
    {
        $composerJson = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
            true,
            flags: \JSON_THROW_ON_ERROR
        );

        $prodEnvs = $composerJson['extra']['runtime']['prod_envs'] ?? [];

        self::assertContains(
            $environment,
            $prodEnvs,
            sprintf(
                "Environment '%s' must be listed in extra.runtime.prod_envs in composer.json "
                . "so that symfony/runtime sets APP_DEBUG=false.",
                $environment
            )
        );
    }

    /**
     * Ensures that when APP_DEBUG is false (as it will be in prod/redis due to the
     * prod_envs config), a 500 error response does not expose exception details.
     */
    public function testErrorResponseDoesNotExposeInternalsWhenDebugIsDisabled(): void
    {
        $exception = new \RuntimeException(sprintf(
            'Sensitive marker for prod with template typo invoice.di in %s',
            __FILE__
        ));

        $renderer = new HtmlErrorRenderer(debug: false);
        $flattenException = $renderer->render($exception);
        $content = $flattenException->getAsString();

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $flattenException->getStatusCode());
        self::assertStringContainsString('text/html', (string) ($flattenException->getHeaders()['Content-Type'] ?? ''));
        self::assertNotSame('', trim($content));
        self::assertStringNotContainsString('Sensitive marker', $content);
        self::assertStringNotContainsString('invoice.di', $content);
        self::assertStringNotContainsString('RuntimeException', $content);
        self::assertStringNotContainsString(basename(__FILE__), $content);
        self::assertArrayNotHasKey('X-Debug-Exception', $flattenException->getHeaders());
        self::assertArrayNotHasKey('X-Debug-Exception-File', $flattenException->getHeaders());
    }
}
