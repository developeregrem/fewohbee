<?php

declare(strict_types=1);

namespace App\Tests\Unit\TemplateSandbox;

use App\Entity\Reservation;
use App\Entity\User;
use App\Service\TemplateSandbox\TemplateSandbox;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Error\Error;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Sandbox\SecurityNotAllowedMethodError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityNotAllowedTestError;

/** Verify that user-authored template text cannot leave the template sandbox. */
final class TemplateSandboxTest extends TestCase
{
    /** @param class-string<Error> $expected */
    #[DataProvider('blockedTemplates')]
    public function testDangerousTemplateFeaturesAreRejected(string $source, string $expected): void
    {
        $reservation = new Reservation();
        $reservation->setRemark('unchanged');

        try {
            $this->createSandbox()->render($source, ['r' => $reservation, 'u' => new User(), 'items' => ['probe']]);
            self::fail('The sandbox must reject: '.$source);
        } catch (Error $e) {
            self::assertInstanceOf($expected, $e);
        }

        self::assertSame('unchanged', $reservation->getRemark());
    }

    /** @return iterable<string, array{string, class-string<Error>}> */
    public static function blockedTemplates(): iterable
    {
        // PHP callables: Twig only accepts arrow functions inside a sandbox. strtoupper is a
        // harmless stand-in for system/passthru.
        foreach (['map', 'filter', 'sort', 'find'] as $filter) {
            yield 'PHP function via '.$filter => ["{{ items|$filter('strtoupper')|join }}", RuntimeError::class];
        }
        yield 'PHP function via reduce' => ["{{ items|reduce('strtoupper') }}", RuntimeError::class];
        yield 'invoke filter' => ["{{ 'strtoupper'|invoke('x') }}", SecurityNotAllowedFilterError::class];

        // Other templates, files and constants.
        foreach (["{% include 'base.html.twig' %}", "{% extends 'base.html.twig' %}", "{% embed 'base.html.twig' %}{% endembed %}", "{% use 'base.html.twig' %}", '{% import _self as m %}', '{% macro m() %}{% endmacro %}', '{% trans %}x{% endtrans %}'] as $tag) {
            yield 'tag '.$tag => [$tag, SecurityNotAllowedTagError::class];
        }
        yield 'include function' => ["{{ include('base.html.twig') }}", SecurityNotAllowedFunctionError::class];
        yield 'source function' => ["{{ source('base.html.twig') }}", SecurityNotAllowedFunctionError::class];
        yield 'constant function' => ["{{ constant('PHP_VERSION') }}", SecurityNotAllowedFunctionError::class];
        yield 'constant test' => ["{{ 1 is constant('PHP_INT_SIZE') }}", SecurityNotAllowedTestError::class];
        yield 'attribute function' => ["{{ attribute(r, 'setRemark', ['changed']) }}", SecurityNotAllowedFunctionError::class];

        // Features of the application environment do not exist in the sandbox at all.
        yield 'file excerpt filter' => ["{{ '/etc/passwd'|file_excerpt(1) }}", SyntaxError::class];
        yield 'controller rendering' => ["{{ render(controller('App\\\\Controller\\\\DashboardController::indexAction')) }}", SyntaxError::class];

        // Data access: read-only, no accounts.
        yield 'entity setter' => ["{{ r.setRemark('changed') }}", SecurityNotAllowedMethodError::class];
        yield 'password hash' => ['{{ u.password }}', SecurityError::class];
        yield 'password getter' => ['{{ u.getPassword() }}', SecurityNotAllowedMethodError::class];
    }

    public function testTemplatesKeepTheirEverydayFeatures(): void
    {
        $reservation = new Reservation();
        $reservation->setRemark('<b>Late arrival</b>');
        $source = <<<'TWIG'
            {% set total = 0 %}{% for value in values|filter(v => v > 1)|sort((a, b) => b <=> a) %}{% set total = total + value %}{{ value }},{% endfor %}
            {{ total }}|{{ r.remark }}|{{ r.remark|raw }}|{{ day|date('Y-m-d') }}|{{ day.format('d.m.') }}
            {% if list.count == 1 %}{{ list.first.remark|striptags|upper }}{% endif %}|{{ 'greeting'|trans }}|{{ app.request.locale }}
            TWIG;

        $output = $this->createSandbox()->render($source, [
            'values' => [1, 3, 2],
            'r' => $reservation,
            'day' => new \DateTimeImmutable('2026-03-01'),
            'list' => new ArrayCollection([$reservation]),
        ]);

        self::assertSame(
            "3,2,5|&lt;b&gt;Late arrival&lt;/b&gt;|<b>Late arrival</b>|2026-03-01|01.03.\nLATE ARRIVAL|translated:greeting|de",
            trim($output),
        );
    }

    public function testValidationReportsDisallowedFeaturesWithoutData(): void
    {
        $sandbox = $this->createSandbox();
        $sandbox->validate('{{ reservation.remark|upper }}');

        $this->expectException(SecurityError::class);
        $sandbox->validate("{{ source('base.html.twig') }}");
    }

    public function testStrictVariablesApplyPerRenderAndRestoreTheDefault(): void
    {
        $sandbox = $this->createSandbox();

        self::assertSame('', $sandbox->render('{{ missing }}'));
        try {
            $sandbox->render('{{ missing }}', strictVariables: true);
            self::fail('Unknown variables must fail in strict mode.');
        } catch (RuntimeError) {
        }

        self::assertFalse($sandbox->isStrictVariables());
    }

    private function createSandbox(): TemplateSandbox
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => 'translated:'.$id);
        $translator->method('getLocale')->willReturn('de');

        return new TemplateSandbox($translator);
    }
}
