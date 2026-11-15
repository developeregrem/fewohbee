<?php

declare(strict_types=1);

namespace App\Service\TemplateSandbox;

use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Error\Error;
use Twig\Error\SyntaxError;
use Twig\Extension\ExtensionInterface;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\Sandbox;
use Twig\Sandbox\SecurityError;

/**
 * Renders user-authored template text (editor syntax already converted to Twig).
 *
 * The text runs in a dedicated, always-sandboxed Twig environment instead of the application
 * environment: only the extensions passed here exist in it, and {@see TemplateSecurityPolicy}
 * decides which of their tags, filters, functions, methods and properties may run. Twig itself
 * then only accepts arrow functions in filters such as map/filter/sort/reduce, so template text
 * cannot reach PHP functions, files, other templates or application services.
 */
final class TemplateSandbox
{
    private readonly Environment $environment;
    private readonly Sandbox $sandbox;

    /**
     * @param iterable<ExtensionInterface> $extensions Application extensions whose allowed functions/filters templates use
     * @param string|false                 $cache      Compiled template cache directory, false to disable
     */
    public function __construct(
        private readonly TranslatorInterface $translator,
        iterable $extensions = [],
        private readonly bool $strictVariables = false,
        string|false $cache = false,
        bool $autoReload = false,
        private readonly ?LocaleSwitcher $localeSwitcher = null,
    ) {
        $this->environment = new Environment(new ArrayLoader(), [
            'autoescape' => 'html',
            'strict_variables' => $strictVariables,
            'cache' => $cache,
            'auto_reload' => $autoReload,
        ]);
        $this->environment->addExtension(new TranslationExtension($translator));
        foreach ($extensions as $extension) {
            $this->environment->addExtension($extension);
        }

        $this->sandbox = new Sandbox($this->environment, new TemplateSecurityPolicy());
    }

    /**
     * @param array<string, mixed> $context
     * @param bool|null            $strictVariables Fail on unknown variables/attributes; null keeps the configured default
     *
     * @throws Error including Twig\Sandbox\SecurityError for anything the policy rejects
     */
    public function render(string $source, array $context = [], ?bool $strictVariables = null): string
    {
        $strict = $strictVariables ?? $this->strictVariables;
        $strict ? $this->environment->enableStrictVariables() : $this->environment->disableStrictVariables();

        try {
            return $this->sandbox->createTemplate($source)->render($context + $this->compatibilityContext());
        } finally {
            $this->strictVariables ? $this->environment->enableStrictVariables() : $this->environment->disableStrictVariables();
        }
    }

    /**
     * Check the source without real data.
     *
     * Twig checks a template's tags, filters, functions and tests right before it executes any
     * of it, so one render with an empty context finds every such violation. Without data, other
     * runtime errors are expected and ignored. Methods and properties depend on the data and are
     * checked when the template is rendered for real.
     *
     * @throws SyntaxError
     * @throws SecurityError
     */
    public function validate(string $source): void
    {
        // Syntax errors surface here; the render below reuses the compiled template.
        $this->sandbox->createTemplate($source);

        try {
            $this->render($source, [], strictVariables: false);
        } catch (SecurityError $e) {
            throw $e;
        } catch (\Throwable) {
            // Missing data, not a sandbox violation.
        }
    }

    public function isStrictVariables(): bool
    {
        return $this->environment->isStrictVariables();
    }

    /**
     * Templates written for the application environment read the language from
     * app.request.locale; only that value is kept, as plain data, in the render language.
     *
     * @return array<string, mixed>
     */
    private function compatibilityContext(): array
    {
        $locale = $this->localeSwitcher?->getLocale() ?? $this->translator->getLocale();

        return ['app' => ['request' => ['locale' => $locale]]];
    }
}
