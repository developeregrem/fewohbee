<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\MpdfService;
use App\Service\TemplatePreview\TemplateRenderParamsResolver;
use App\Service\TemplatesService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class TemplatesServiceTest extends TestCase
{
    public function testRenderTemplateStringReplacesLegacyPseudoTwigSyntax(): void
    {
        $service = $this->createService();

        $output = $service->renderTemplateString(
            '[% for item in items %][[ item ]],[% endfor %]',
            ['items' => ['A', 'B']]
        );

        self::assertSame('A,B,', preg_replace('/\s+/', '', $output));
    }

    public function testRenderTemplateStringConvertsDataRepeatOnTableRows(): void
    {
        $service = $this->createService();

        $output = $service->renderTemplateString(
            '<table><tbody><tr data-repeat="items" data-repeat-as="item"><td>[[ item ]]</td></tr></tbody></table>',
            ['items' => ['A', 'B']]
        );

        self::assertStringContainsString('<td>A</td>', $output);
        self::assertStringContainsString('<td>B</td>', $output);
        self::assertStringNotContainsString('data-repeat=', $output);
    }

    public function testRenderTemplateStringConvertsDataRepeatWithKey(): void
    {
        $service = $this->createService();

        $output = $service->renderTemplateString(
            '<p><span data-repeat="pairs" data-repeat-key="key" data-repeat-as="value">[[ key ]]=[[ value ]];</span></p>',
            ['pairs' => ['x' => '1', 'y' => '2']]
        );

        self::assertStringContainsString('x=1;', $output);
        self::assertStringContainsString('y=2;', $output);
        self::assertStringNotContainsString('data-repeat-key=', $output);
    }

    public function testRenderTemplateStringConvertsDataIfBlocks(): void
    {
        $service = $this->createService();

        $output = $service->renderTemplateString(
            '<div data-if="show">YES</div><div data-if="hide">NO</div>',
            ['show' => true, 'hide' => false]
        );

        self::assertStringContainsString('YES', $output);
        self::assertStringNotContainsString('>NO<', $output);
        self::assertStringNotContainsString('data-if=', $output);
    }

    public function testRenderTemplateStringKeepsStyleAndClassOnDataRepeatElement(): void
    {
        $service = $this->createService();

        $output = $service->renderTemplateString(
            '<span class="badge important" style="color:#111;background:#eee" data-repeat="items" data-repeat-as="item">[[ item ]]</span>',
            ['items' => ['A']]
        );

        self::assertStringContainsString('<span class="badge important" style="color:#111;background:#eee">A</span>', $output);
        self::assertStringNotContainsString('data-repeat=', $output);
    }

    public function testRenderTemplateStringKeepsStyleAndClassOnDataIfElement(): void
    {
        $service = $this->createService();

        $output = $service->renderTemplateString(
            '<div class="notice" style="border:1px solid #333" data-if="show">OK</div>',
            ['show' => true]
        );

        self::assertStringContainsString('<div class="notice" style="border:1px solid #333">OK</div>', $output);
        self::assertStringNotContainsString('data-if=', $output);
    }

    public function testRenderTemplateStringKeepsNestedRepeatStructure(): void
    {
        $service = $this->createService();

        $output = $service->renderTemplateString(
            '<p><span data-repeat="items" data-repeat-as="item"><span>[[ item.name ]]</span>: <span>[[ item.value ]]</span><br /></span></p>',
            ['items' => [
                ['name' => 'A', 'value' => '1'],
                ['name' => 'B', 'value' => '2'],
            ]]
        );

        self::assertStringContainsString('A', $output);
        self::assertStringContainsString('1', $output);
        self::assertStringContainsString('B', $output);
        self::assertStringContainsString('2', $output);
    }

    public function testRenderTemplateStringRestoresEncodedStyleTokens(): void
    {
        $service = $this->createService();
        $style = '<style>table{border-collapse:collapse;}</style>';
        $encoded = base64_encode($style);
        $input = '<p data-template-style="'.$encoded.'" class="template-editor-style-token">CSS</p><p>Body</p>';

        $output = $service->renderTemplateString($input, []);

        self::assertStringContainsString($style, $output);
        self::assertStringContainsString('<p>Body</p>', $output);
    }

    public function testRenderTemplateStringConvertsHeaderAndFooterContainers(): void
    {
        $service = $this->createService();

        $output = $service->renderTemplateString(
            '<div class="header">Header</div><p>Body</p><div class="footer">Footer</div>',
            []
        );

        self::assertStringContainsString('<htmlpageheader name="header">Header</htmlpageheader>', $output);
        self::assertStringContainsString('<htmlpagefooter name="footer">Footer</htmlpagefooter>', $output);
    }

    private function createService(): TemplatesService
    {
        $twig = new Environment(new ArrayLoader());
        $translator = $this->createStub(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnCallback(static fn (string $id, array $params = []): string => strtr($id, $params));

        return new TemplatesService(
            'http://example.test',
            $twig,
            $this->createStub(EntityManagerInterface::class),
            new RequestStack(),
            $this->createStub(MpdfService::class),
            $translator,
            $this->createStub(TemplateRenderParamsResolver::class)
        );
    }
}
