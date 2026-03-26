<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Template;
use App\Entity\TemplateType;
use App\Repository\TemplateRepository;
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

    // ─── Template include tests ─────────────────────────────────────────────

    public function testResolveTemplateIncludesReplacesSpanWithContent(): void
    {
        $included = $this->makeTemplate(42, 'Fußzeile', '<p>Footer content</p>', 'TEMPLATE_FILE_PDF');
        $service = $this->createServiceWithTemplates([42 => $included]);

        $input = '<p>Body</p><span class="template-include" data-template-id="42">Fußzeile</span>';
        $output = $service->resolveTemplateIncludes($input, 'TEMPLATE_RESERVATION_PDF');

        self::assertStringContainsString('<p>Footer content</p>', $output);
        self::assertStringNotContainsString('template-include', $output);
        self::assertStringNotContainsString('data-template-id', $output);
    }

    public function testResolveTemplateIncludesThrowsOnNotFoundTemplate(): void
    {
        $service = $this->createServiceWithTemplates([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/notfound/');

        $service->resolveTemplateIncludes('<span class="template-include" data-template-id="99">Missing</span>', 'TEMPLATE_INVOICE_PDF');
    }

    public function testResolveTemplateIncludesThrowsOnCircularReference(): void
    {
        $selfRef = $this->makeTemplate(10, 'Self', '<span class="template-include" data-template-id="10">Self</span>', 'TEMPLATE_FILE_PDF');
        $service = $this->createServiceWithTemplates([10 => $selfRef]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/circular/');

        $service->resolveTemplateIncludes('<span class="template-include" data-template-id="10">Self</span>', 'TEMPLATE_FILE_PDF', 0, [10]);
    }

    public function testResolveTemplateIncludesRespectsMaxDepth(): void
    {
        // Create a chain of nested includes that exceeds max depth
        $deepTemplate = $this->makeTemplate(1, 'Deep', '<span class="template-include" data-template-id="1">Deep</span>', 'TEMPLATE_FILE_PDF');
        $service = $this->createServiceWithTemplates([1 => $deepTemplate]);

        // Start at depth 4 (limit is 5), so one more recursion should stop
        $input = '<span class="template-include" data-template-id="1">Deep</span>';
        $output = $service->resolveTemplateIncludes($input, 'TEMPLATE_FILE_PDF', 4);

        // The inner include should still be resolved (depth 4 → 5), but the next one won't
        self::assertStringContainsString('template-include', $output);
    }

    public function testResolveTemplateIncludesThrowsOnIncompatibleType(): void
    {
        $invoiceTemplate = $this->makeTemplate(50, 'Invoice Snippet', '<p>Invoice</p>', 'TEMPLATE_INVOICE_PDF');
        $service = $this->createServiceWithTemplates([50 => $invoiceTemplate]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/incompatible/');

        $service->resolveTemplateIncludes('<span class="template-include" data-template-id="50">Invoice Snippet</span>', 'TEMPLATE_RESERVATION_PDF');
    }

    public function testResolveTemplateIncludesAllowsFilePdfInAnyType(): void
    {
        $filePdf = $this->makeTemplate(5, 'Header', '<p>Header</p>', 'TEMPLATE_FILE_PDF');
        $service = $this->createServiceWithTemplates([5 => $filePdf]);

        $input = '<span class="template-include" data-template-id="5">Header</span>';
        $output = $service->resolveTemplateIncludes($input, 'TEMPLATE_INVOICE_PDF');

        self::assertStringContainsString('<p>Header</p>', $output);
    }

    public function testResolveTemplateIncludesAllowsSameBaseTypePdfAndEmail(): void
    {
        $emailTemplate = $this->makeTemplate(7, 'Res Email', '<p>Email</p>', 'TEMPLATE_RESERVATION_EMAIL');
        $service = $this->createServiceWithTemplates([7 => $emailTemplate]);

        $input = '<span class="template-include" data-template-id="7">Res Email</span>';
        $output = $service->resolveTemplateIncludes($input, 'TEMPLATE_RESERVATION_PDF');

        self::assertStringContainsString('<p>Email</p>', $output);
    }

    public function testResolveTemplateIncludesResolvesNestedIncludes(): void
    {
        $inner = $this->makeTemplate(2, 'Inner', '<b>Inner content</b>', 'TEMPLATE_FILE_PDF');
        $outer = $this->makeTemplate(3, 'Outer', '<div><span class="template-include" data-template-id="2">Inner</span></div>', 'TEMPLATE_FILE_PDF');
        $service = $this->createServiceWithTemplates([2 => $inner, 3 => $outer]);

        $input = '<span class="template-include" data-template-id="3">Outer</span>';
        $output = $service->resolveTemplateIncludes($input, 'TEMPLATE_RESERVATION_PDF');

        self::assertStringContainsString('<b>Inner content</b>', $output);
        self::assertStringNotContainsString('template-include', $output);
    }

    public function testResolveTemplateIncludesWorksWithReversedAttributeOrder(): void
    {
        $included = $this->makeTemplate(44, 'Fußzeile', '<p>Footer content</p>', 'TEMPLATE_FILE_PDF');
        $service = $this->createServiceWithTemplates([44 => $included]);

        // Tiptap outputs data-template-id before class
        $input = '<span data-template-id="44" class="template-include" contenteditable="false">Fußzeile</span>';
        $output = $service->resolveTemplateIncludes($input, 'TEMPLATE_RESERVATION_PDF');

        self::assertStringContainsString('<p>Footer content</p>', $output);
        self::assertStringNotContainsString('template-include', $output);
    }

    public function testResolveTemplateIncludesKeepsSurroundingContent(): void
    {
        $footer = $this->makeTemplate(8, 'Footer', '<footer>Foot</footer>', 'TEMPLATE_FILE_PDF');
        $service = $this->createServiceWithTemplates([8 => $footer]);

        $input = '<p>Before</p><span class="template-include" data-template-id="8">Footer</span><p>After</p>';
        $output = $service->resolveTemplateIncludes($input, 'TEMPLATE_RESERVATION_PDF');

        self::assertStringContainsString('<p>Before</p>', $output);
        self::assertStringContainsString('<footer>Foot</footer>', $output);
        self::assertStringContainsString('<p>After</p>', $output);
    }

    public function testIsTemplateIncludeCompatibleFilePdfAlwaysAllowed(): void
    {
        $service = $this->createService();
        self::assertTrue($service->isTemplateIncludeCompatible('TEMPLATE_INVOICE_PDF', 'TEMPLATE_FILE_PDF'));
        self::assertTrue($service->isTemplateIncludeCompatible('TEMPLATE_RESERVATION_EMAIL', 'TEMPLATE_FILE_PDF'));
        self::assertTrue($service->isTemplateIncludeCompatible('TEMPLATE_GDPR_PDF', 'TEMPLATE_FILE_PDF'));
    }

    public function testIsTemplateIncludeCompatibleSameBaseType(): void
    {
        $service = $this->createService();
        self::assertTrue($service->isTemplateIncludeCompatible('TEMPLATE_RESERVATION_PDF', 'TEMPLATE_RESERVATION_EMAIL'));
        self::assertTrue($service->isTemplateIncludeCompatible('TEMPLATE_RESERVATION_EMAIL', 'TEMPLATE_RESERVATION_PDF'));
    }

    public function testIsTemplateIncludeCompatibleDifferentBaseType(): void
    {
        $service = $this->createService();
        self::assertFalse($service->isTemplateIncludeCompatible('TEMPLATE_RESERVATION_PDF', 'TEMPLATE_INVOICE_PDF'));
        self::assertFalse($service->isTemplateIncludeCompatible('TEMPLATE_INVOICE_PDF', 'TEMPLATE_GDPR_PDF'));
    }

    public function testResolveTemplateIncludesNoMatchReturnsOriginal(): void
    {
        $service = $this->createService();

        $input = '<p>No includes here</p>';
        $output = $service->resolveTemplateIncludes($input, 'TEMPLATE_INVOICE_PDF');

        self::assertSame($input, $output);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

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

    /**
     * Create a TemplatesService with a mocked EntityManager that returns templates by ID.
     *
     * @param array<int, Template> $templates
     */
    private function createServiceWithTemplates(array $templates): TemplatesService
    {
        $twig = new Environment(new ArrayLoader());
        $translator = $this->createStub(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnCallback(static fn (string $id, array $params = []): string => strtr($id, $params));

        $repo = $this->createStub(TemplateRepository::class);
        $repo->method('find')
            ->willReturnCallback(static fn (int $id) => $templates[$id] ?? null);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')
            ->willReturn($repo);

        return new TemplatesService(
            'http://example.test',
            $twig,
            $em,
            new RequestStack(),
            $this->createStub(MpdfService::class),
            $translator,
            $this->createStub(TemplateRenderParamsResolver::class)
        );
    }

    private function makeTemplate(int $id, string $name, string $text, string $typeName): Template
    {
        $type = new TemplateType();
        $type->setName($typeName);

        $template = new Template();
        $template->setId($id);
        $template->setName($name);
        $template->setText($text);
        $template->setTemplateType($type);
        $template->setIsDefault(false);
        $template->setParams('{}');

        return $template;
    }
}
