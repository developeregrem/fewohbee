<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Enum\PaymentMeansCode;
use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Entity\Template;
use App\Entity\TemplateType;
use App\Repository\TemplateRepository;
use App\Service\CustomFontManager;
use App\Service\MpdfService;
use App\Service\TemplatePreview\TemplateRenderParamsResolver;
use App\Service\TemplatesService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class TemplatesServiceTest extends TestCase
{
    /** @param 'array'|'collection'|'generator' $container */
    #[DataProvider('reservationContainers')]
    public function testReservationRenderingKeepsIterationOrderAndEscapesValues(string $container): void
    {
        $first = new Reservation();
        $first->setId(7);
        $first->setRemark('<b>First</b>');
        $second = new Reservation();
        $second->setId(9);
        $second->setRemark('Second');
        $reservations = [3 => $first, 8 => $second];
        $input = match ($container) {
            'array' => $reservations,
            'collection' => new ArrayCollection($reservations),
            'generator' => (static function () use ($reservations): \Generator {
                yield from $reservations;
            })(),
        };
        $template = $this->makeTemplate(
            5,
            'Reservations',
            '{% for item in reservations %}{{ item.id }}:{{ item.remark }};{% endfor %}',
            'TEMPLATE_RESERVATION_EMAIL',
        );
        $service = $this->createServiceWithTemplates([5 => $template]);

        $output = $service->renderTemplateForReservations('5', $input);

        self::assertSame('7:&lt;b&gt;First&lt;/b&gt;;9:Second;', $output);
    }

    /** @return iterable<string, array{'array'|'collection'|'generator'}> */
    public static function reservationContainers(): iterable
    {
        yield 'sparse array' => ['array'];
        yield 'sparse collection' => ['collection'];
        yield 'generator' => ['generator'];
    }

    #[DataProvider('renderFailures')]
    public function testFriendlyErrorsKeepTheirCauseAndRestoreStrictVariables(bool $subject, bool $strict, string $text, string $message): void
    {
        $twig = new Environment(new ArrayLoader(), ['strict_variables' => $strict]);
        $service = $this->createService($twig);

        try {
            if ($subject) {
                $service->renderSubjectString($text, []);
            } else {
                $service->renderTemplateString($text, []);
            }
            self::fail('Invalid template content must produce a friendly error.');
        } catch (\RuntimeException $e) {
            self::assertSame($message, $e->getMessage());
            self::assertInstanceOf(\Twig\Error\Error::class, $e->getPrevious());
        }

        self::assertSame($strict, $twig->isStrictVariables());
    }

    /** @return iterable<string, array{bool, bool, string, string}> */
    public static function renderFailures(): iterable
    {
        foreach ([false, true] as $subject) {
            foreach ([false, true] as $strict) {
                $prefix = ($subject ? 'subject' : 'body').' '.($strict ? 'strict' : 'lenient');
                yield $prefix.' missing variable' => [$subject, $strict, '[[ missing ]]', 'templates.preview.render.error.variable'];
                yield $prefix.' syntax error' => [$subject, $strict, '[[ unfinished', 'templates.preview.render.error.syntax'];
            }
        }
    }

    public function testRenderTemplateStringReplacesLegacyPseudoTwigSyntax(): void
    {
        $service = $this->createService();

        $output = $service->renderTemplateString(
            '[% for item in items %][[ item ]],[% endfor %]',
            ['items' => ['A', 'B']]
        );

        self::assertSame('A,B,', preg_replace('/\s+/', '', $output));
    }

    public function testRenderTemplateStringEscapesDynamicHtmlValues(): void
    {
        $service = $this->createService();

        $output = $service->renderTemplateString(
            '<p>[[ value ]]</p>',
            ['value' => '<img src=x onerror=alert(1)>']
        );

        self::assertSame('<p>&lt;img src=x onerror=alert(1)&gt;</p>', $output);
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

    public function testRenderTemplateStringDecodesEncodedArrowInDataRepeatExpression(): void
    {
        $service = $this->createService();

        $output = $service->renderTemplateString(
            '<table><tbody><tr data-repeat="invoice.positions|filter(p =&gt; p.positionGroup == \'apartment_modifier\')" data-repeat-as="position"><td>[[ position.description ]]</td></tr></tbody></table>',
            [
                'invoice' => [
                    'positions' => [
                        ['description' => 'Keep', 'positionGroup' => 'apartment_modifier'],
                        ['description' => 'Skip', 'positionGroup' => 'tourist_tax'],
                    ],
                ],
            ]
        );

        self::assertStringContainsString('<td>Keep</td>', $output);
        self::assertStringNotContainsString('<td>Skip</td>', $output);
        self::assertStringNotContainsString('&gt;', $output);
    }

    public function testRenderTemplateStringDecodesEncodedGreaterThanInDataRepeatExpression(): void
    {
        $service = $this->createService();

        $output = $service->renderTemplateString(
            '<p><span data-repeat="vats|filter((v, k) =&gt; k &gt; 0)" data-repeat-key="key" data-repeat-as="value">[[ key ]]=[[ value ]];</span></p>',
            ['vats' => [0 => 'zero', 7 => 'seven', 19 => 'nineteen']]
        );

        self::assertStringNotContainsString('0=zero;', $output);
        self::assertStringContainsString('7=seven;', $output);
        self::assertStringContainsString('19=nineteen;', $output);
        self::assertStringNotContainsString('&gt;', $output);
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

    public function testRenderTemplateStringConvertsDataIfOnTable(): void
    {
        $service = $this->createService();
        $template = <<<'HTML'
            <table data-if="invoice.positions|filter(p => p.positionGroup == 'apartment_modifier')|length > 0"><tbody><tr><th>Modifiers</th></tr></tbody></table>
            HTML;

        $hiddenOutput = $service->renderTemplateString($template, [
            'invoice' => ['positions' => [
                ['positionGroup' => 'tourist_tax'],
            ]],
        ]);
        $visibleOutput = $service->renderTemplateString($template, [
            'invoice' => ['positions' => [
                ['positionGroup' => 'apartment_modifier'],
            ]],
        ]);

        self::assertStringNotContainsString('<table', $hiddenOutput);
        self::assertStringContainsString('<table><tbody><tr><th>Modifiers</th></tr></tbody></table>', $visibleOutput);
        self::assertStringNotContainsString('data-if=', $visibleOutput);
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

    #[DataProvider('generalEmailHosts')]
    public function testIsTemplateIncludeCompatibleGeneralEmailFitsMailTemplatesOnly(string $hostTypeName, bool $compatible): void
    {
        self::assertSame($compatible, $this->createService()->isTemplateIncludeCompatible($hostTypeName, 'TEMPLATE_GENERAL_EMAIL'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function generalEmailHosts(): iterable
    {
        yield 'general mail' => ['TEMPLATE_GENERAL_EMAIL', true];
        yield 'reservation mail' => ['TEMPLATE_RESERVATION_EMAIL', true];
        yield 'invoice mail' => ['TEMPLATE_INVOICE_EMAIL', true];
        yield 'newsletter' => ['TEMPLATE_NEWSLETTER_EMAIL', true];
        yield 'reservation PDF' => ['TEMPLATE_RESERVATION_PDF', false];
        yield 'invoice PDF' => ['TEMPLATE_INVOICE_PDF', false];
    }

    public function testIsTemplateIncludeCompatibleKeepsTypedMailsInTheirBaseType(): void
    {
        $service = $this->createService();

        self::assertFalse($service->isTemplateIncludeCompatible('TEMPLATE_INVOICE_EMAIL', 'TEMPLATE_RESERVATION_EMAIL'));
        self::assertFalse($service->isTemplateIncludeCompatible('TEMPLATE_GENERAL_EMAIL', 'TEMPLATE_RESERVATION_EMAIL'));
    }

    public function testResolveTemplateIncludesEmbedsGeneralMailIntoInvoiceMail(): void
    {
        $signature = $this->makeTemplate(63, 'Signatur', '<p>Max Mustermann</p>', 'TEMPLATE_GENERAL_EMAIL');
        $service = $this->createServiceWithTemplates([63 => $signature]);

        $output = $service->resolveTemplateIncludes($this->includeSpan(63, 'Signatur'), 'TEMPLATE_INVOICE_EMAIL');

        self::assertSame('<p>Max Mustermann</p>', $output);
    }

    public function testResolveTemplateIncludesUsesVariantInHostLanguage(): void
    {
        $signature = $this->makeTemplate(63, 'Signatur', '<p>Viele Grüße</p>', 'TEMPLATE_GENERAL_EMAIL');
        $this->makeVariant($signature, 64, 'en', '<p>Kind regards</p>');
        $service = $this->createServiceWithTemplates([63 => $signature]);

        $output = $service->resolveTemplateIncludes($this->includeSpan(63, 'Signatur'), 'TEMPLATE_RESERVATION_EMAIL', locale: 'en');

        self::assertSame('<p>Kind regards</p>', $output);
    }

    public function testResolveTemplateIncludesKeepsEmbeddedTextWithoutVariantInHostLanguage(): void
    {
        $signature = $this->makeTemplate(63, 'Signatur', '<p>Viele Grüße</p>', 'TEMPLATE_GENERAL_EMAIL');
        $this->makeVariant($signature, 64, 'en', '<p>Kind regards</p>');
        $service = $this->createServiceWithTemplates([63 => $signature]);

        $output = $service->resolveTemplateIncludes($this->includeSpan(63, 'Signatur'), 'TEMPLATE_RESERVATION_EMAIL', locale: 'fr');

        self::assertSame('<p>Viele Grüße</p>', $output);
    }

    public function testResolveTemplateIncludesKeepsEmbeddedTextForHostWithoutLanguage(): void
    {
        $signature = $this->makeTemplate(63, 'Signatur', '<p>Viele Grüße</p>', 'TEMPLATE_GENERAL_EMAIL');
        $this->makeVariant($signature, 64, 'en', '<p>Kind regards</p>');
        $service = $this->createServiceWithTemplates([63 => $signature]);

        $output = $service->resolveTemplateIncludes($this->includeSpan(63, 'Signatur'), 'TEMPLATE_RESERVATION_EMAIL');

        self::assertSame('<p>Viele Grüße</p>', $output);
    }

    public function testResolveTemplateIncludesKeepsLanguageForNestedIncludes(): void
    {
        $inner = $this->makeTemplate(2, 'Inner', '<b>Innen</b>', 'TEMPLATE_FILE_PDF');
        $this->makeVariant($inner, 12, 'en', '<b>Inside</b>');
        $outer = $this->makeTemplate(3, 'Outer', '<div>'.$this->includeSpan(2, 'Inner').'</div>', 'TEMPLATE_FILE_PDF');
        $service = $this->createServiceWithTemplates([2 => $inner, 3 => $outer]);

        $output = $service->resolveTemplateIncludes($this->includeSpan(3, 'Outer'), 'TEMPLATE_RESERVATION_PDF', locale: 'en');

        self::assertSame('<div><b>Inside</b></div>', $output);
    }

    public function testResolveTemplateIncludesDetectsCycleThroughVariant(): void
    {
        $host = $this->makeTemplate(1, 'Host', '', 'TEMPLATE_FILE_PDF');
        $hostVariant = $this->makeVariant($host, 11, 'en', $this->includeSpan(2, 'Embedded'));
        $embedded = $this->makeTemplate(2, 'Embedded', '<p>Ohne Zyklus</p>', 'TEMPLATE_FILE_PDF');
        $this->makeVariant($embedded, 12, 'en', $this->includeSpan(1, 'Host'));
        $service = $this->createServiceWithTemplates([1 => $host, 2 => $embedded]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/circular/');

        $service->resolveTemplateIncludes((string) $hostVariant->getText(), 'TEMPLATE_FILE_PDF', locale: 'en');
    }

    public function testRenderTemplateEmbedsVariantInLanguageOfVariantHost(): void
    {
        $signature = $this->makeTemplate(63, 'Signatur', '<p>Viele Grüße</p>', 'TEMPLATE_GENERAL_EMAIL');
        $this->makeVariant($signature, 64, 'en', '<p>Kind regards</p>');
        $host = $this->makeTemplate(1, 'Mail', '<p>Hallo</p>'.$this->includeSpan(63, 'Signatur'), 'TEMPLATE_RESERVATION_EMAIL');
        $hostVariant = $this->makeVariant($host, 2, 'en', '<p>Hello</p>'.$this->includeSpan(63, 'Signatur'));
        $service = $this->createServiceWithTemplates([1 => $host, 2 => $hostVariant, 63 => $signature]);

        self::assertSame('<p>Hallo</p><p>Viele Grüße</p>', $service->renderTemplate(1, null));
        self::assertSame('<p>Hello</p><p>Kind regards</p>', $service->renderTemplate(2, null));
    }

    public function testRenderTemplateStringEmbedsVariantInGivenLanguage(): void
    {
        $signature = $this->makeTemplate(63, 'Signatur', '<p>Viele Grüße</p>', 'TEMPLATE_GENERAL_EMAIL');
        $this->makeVariant($signature, 64, 'en', '<p>Kind regards</p>');
        $service = $this->createServiceWithTemplates([63 => $signature]);

        $output = $service->renderTemplateString($this->includeSpan(63, 'Signatur'), [], 'TEMPLATE_RESERVATION_EMAIL', 'en');

        self::assertSame('<p>Kind regards</p>', $output);
    }

    public function testGetEmbeddableTemplatesOffersGeneralMailInMailHostsOnly(): void
    {
        $signature = $this->makeTemplate(63, 'Signatur', '<p>Grüße</p>', 'TEMPLATE_GENERAL_EMAIL');
        $service = $this->createServiceWithTemplates([63 => $signature]);

        self::assertSame([$signature], $service->getEmbeddableTemplates($this->makeType('TEMPLATE_INVOICE_EMAIL')));
        self::assertSame([], $service->getEmbeddableTemplates($this->makeType('TEMPLATE_INVOICE_PDF')));
    }

    public function testGetEmbeddableTemplatesLeavesOutVariants(): void
    {
        $signature = $this->makeTemplate(63, 'Signatur', '<p>Grüße</p>', 'TEMPLATE_GENERAL_EMAIL');
        $variant = $this->makeVariant($signature, 64, 'en', '<p>Regards</p>');
        $service = $this->createServiceWithTemplates([63 => $signature, 64 => $variant]);

        self::assertSame([$signature], $service->getEmbeddableTemplates($this->makeType('TEMPLATE_RESERVATION_EMAIL')));
    }

    public function testGetEmbeddableTemplatesLeavesOutTheEditedTemplateAndWhatItTranslates(): void
    {
        $edited = $this->makeTemplate(1, 'Mail', '<p>Hallo</p>', 'TEMPLATE_GENERAL_EMAIL');
        $variant = $this->makeVariant($edited, 2, 'en', '<p>Hello</p>');
        $other = $this->makeTemplate(3, 'Signatur', '<p>Grüße</p>', 'TEMPLATE_GENERAL_EMAIL');
        $service = $this->createServiceWithTemplates([1 => $edited, 2 => $variant, 3 => $other]);
        $hostType = $this->makeType('TEMPLATE_GENERAL_EMAIL');

        self::assertSame([$other], $service->getEmbeddableTemplates($hostType, 1));
        self::assertSame([$other], $service->getEmbeddableTemplates($hostType, 2));
    }

    // ─── Export image embedding (S3 migration gap) ──────────────────────────────

    public function testPrepareImagesEmbedsLegacyLocalExportImageFromStorage(): void
    {
        $png = $this->onePixelPng();
        $service = $this->createServiceWithExportStorage($this->exportStorageWith('logo-abc123.png', $png));

        // After a local→S3 migration the stored src still points at the old local path,
        // but the bytes now live in the export storage. It must be inlined, not host-rewritten.
        $output = $service->prepareImagesForRendering('<p><img src="/resources/images/export/logo-abc123.png"></p>');

        self::assertStringContainsString('src="data:image/png;base64,'.base64_encode($png).'"', $output);
        self::assertStringNotContainsString('/resources/images/export/', $output);
    }

    public function testPrepareImagesEmbedsAbsoluteS3ExportUrlFromStorage(): void
    {
        $png = $this->onePixelPng();
        $service = $this->createServiceWithExportStorage($this->exportStorageWith('logo-abc123.png', $png));

        $output = $service->prepareImagesForRendering(
            '<img src="https://bucket.fsn1.your-objectstorage.com/tenant-uuid/export/logo-abc123.png">'
        );

        self::assertStringContainsString('src="data:image/png;base64,'.base64_encode($png).'"', $output);
        self::assertStringNotContainsString('your-objectstorage.com', $output);
    }

    public function testPrepareImagesLeavesSrcUntouchedWhenFileMissing(): void
    {
        $storage = $this->createStub(FilesystemOperator::class);
        $storage->method('fileExists')->willReturn(false);
        $service = $this->createServiceWithExportStorage($storage);

        $output = $service->prepareImagesForRendering('<img src="/resources/images/export/missing.png">');

        // No inlining possible → original src stays; mPDF simply skips the broken image.
        self::assertStringContainsString('src="/resources/images/export/missing.png"', $output);
        self::assertStringNotContainsString('data:image', $output);
    }

    public function testPrepareImagesPicksJpegMimeFromExtension(): void
    {
        $jpg = $this->onePixelPng(); // bytes are irrelevant for the mime derivation
        $service = $this->createServiceWithExportStorage($this->exportStorageWith('photo.jpeg', $jpg));

        $output = $service->prepareImagesForRendering('<img src="/resources/images/export/photo.jpeg">');

        self::assertStringContainsString('data:image/jpeg;base64,', $output);
    }

    public function testGetPdfOutputEmbedsMigratedExportImageIntoPdf(): void
    {
        $png = $this->onePixelPng();

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('https://tenant.example/'));

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $fontTestDirectory = sys_get_temp_dir().'/fewohbee-test-no-custom-fonts-'.bin2hex(random_bytes(6));
        $customFonts = new CustomFontManager(
            new Filesystem(new InMemoryFilesystemAdapter()),
            $fontTestDirectory,
            $fontTestDirectory.'/analysis-cache',
            $this->createStub(LoggerInterface::class),
        );

        $service = new TemplatesService(
            new Environment(new ArrayLoader()),
            $this->createStub(EntityManagerInterface::class),
            $requestStack,
            new MpdfService($requestStack, $customFonts, sys_get_temp_dir().'/mpdf-test', 'de'),
            $translator,
            $this->createStub(TemplateRenderParamsResolver::class),
            $this->exportStorageWith('logo-abc123.png', $png)
        );

        $template = new Template();
        $template->setParams(json_encode([
            'format' => 'A4',
            'orientation' => 'P',
            'marginLeft' => 25.0,
            'marginRight' => 20.0,
            'marginTop' => 20.0,
            'marginBottom' => 20.0,
            'marginHeader' => 9.0,
            'marginFooter' => 9.0,
        ]));

        $pdf = $service->getPDFOutput(
            '<p>Hallo</p><img src="/resources/images/export/logo-abc123.png">',
            'test',
            $template
        );

        // A well-formed PDF whose body carries an embedded image object proves mPDF
        // accepted the inlined data URI (a broken/absent image would be silently skipped).
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('/Image', $pdf);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    public function testInvoiceTemplateBoundToThePaymentMeansWins(): void
    {
        [$default, $cash] = $this->invoiceTemplates();
        $service = $this->createServiceWithInvoiceTemplates([$cash, $default]);

        $invoice = (new Invoice())->setPaymentMeans(PaymentMeansCode::CASH);

        self::assertSame($cash, $service->resolveInvoiceTemplate($invoice, $default));
        self::assertSame($cash, $service->resolveInvoiceTemplate($invoice));
    }

    public function testInvoiceWithoutBoundTemplateUsesThePreferredOne(): void
    {
        [$default, $cash, $other] = $this->invoiceTemplates();
        $service = $this->createServiceWithInvoiceTemplates([$cash, $default, $other]);

        $invoice = (new Invoice())->setPaymentMeans(PaymentMeansCode::SEPA_CREDIT_TRANSFER);

        self::assertSame($other, $service->resolveInvoiceTemplate($invoice, $other));
        self::assertSame($default, $service->resolveInvoiceTemplate($invoice));
    }

    public function testPreferredTemplateBoundToAnotherPaymentMeansIsNotUsed(): void
    {
        [$default, $cash] = $this->invoiceTemplates();
        $service = $this->createServiceWithInvoiceTemplates([$cash, $default]);

        $invoice = (new Invoice())->setPaymentMeans(PaymentMeansCode::SEPA_CREDIT_TRANSFER);

        self::assertSame($default, $service->resolveInvoiceTemplate($invoice, $cash));
    }

    public function testHiddenTemplateIsNotPickedForItsPaymentMeans(): void
    {
        [$default, $cash] = $this->invoiceTemplates();
        $cash->setHidden(true);
        $service = $this->createServiceWithInvoiceTemplates([$cash, $default]);

        $invoice = (new Invoice())->setPaymentMeans(PaymentMeansCode::CASH);

        self::assertSame($default, $service->resolveInvoiceTemplate($invoice));
    }

    public function testSecondTemplateForTheSamePaymentMeansIsAConflict(): void
    {
        [$default, $cash] = $this->invoiceTemplates();
        $service = $this->createServiceWithInvoiceTemplates([$cash, $default]);

        $second = (new Template())->setName('Rechnung A4 bar')->setPaymentMeans(PaymentMeansCode::CASH);

        self::assertSame($cash, $service->findPaymentMeansConflict($second));
    }

    public function testTemplateIsNoConflictWithItself(): void
    {
        [$default, $cash] = $this->invoiceTemplates();
        $service = $this->createServiceWithInvoiceTemplates([$cash, $default]);

        self::assertNull($service->findPaymentMeansConflict($cash));
    }

    public function testOtherPaymentMeansOrHiddenTemplatesAreNoConflict(): void
    {
        [$default, $cash] = $this->invoiceTemplates();
        $service = $this->createServiceWithInvoiceTemplates([$cash, $default]);

        $card = (new Template())->setName('Rechnung Karte')->setPaymentMeans(PaymentMeansCode::CARD_PAYMENT);
        $hidden = (new Template())->setName('Baustein bar')->setPaymentMeans(PaymentMeansCode::CASH)->setHidden(true);

        self::assertNull($service->findPaymentMeansConflict($card));
        self::assertNull($service->findPaymentMeansConflict($hidden));
        self::assertNull($service->findPaymentMeansConflict($default));
    }

    public function testTemplateBoundToAnotherPaymentMeansIsNeverAFallback(): void
    {
        [, $cash] = $this->invoiceTemplates();
        $service = $this->createServiceWithInvoiceTemplates([$cash]);

        self::assertNull($service->resolveInvoiceTemplate(new Invoice()));
        self::assertNull($service->resolveInvoiceTemplate((new Invoice())->setPaymentMeans(PaymentMeansCode::SEPA_CREDIT_TRANSFER), $cash));
    }

    public function testBindingTheLastUnboundInvoiceTemplateIsRefused(): void
    {
        [$default, $cash] = $this->invoiceTemplates();
        $service = $this->createServiceWithInvoiceTemplates([$cash, $default]);

        $default->setPaymentMeans(PaymentMeansCode::CARD_PAYMENT);

        self::assertTrue($service->leavesNoUnboundInvoiceTemplate($default));
    }

    public function testDeletingTheLastUnboundInvoiceTemplateIsRefused(): void
    {
        [$default, $cash] = $this->invoiceTemplates();
        $service = $this->createServiceWithInvoiceTemplates([$cash, $default]);

        self::assertTrue($service->leavesNoUnboundInvoiceTemplate($default, true));
        self::assertFalse($service->leavesNoUnboundInvoiceTemplate($cash, true));
    }

    public function testAnotherUnboundInvoiceTemplateOrNoBindingAtAllIsFine(): void
    {
        [$default, $cash, $other] = $this->invoiceTemplates();

        $service = $this->createServiceWithInvoiceTemplates([$cash, $default, $other]);
        self::assertFalse($service->leavesNoUnboundInvoiceTemplate($default, true));

        $service = $this->createServiceWithInvoiceTemplates([$default]);
        self::assertFalse($service->leavesNoUnboundInvoiceTemplate($default, true));
    }

    public function testEmbeddedTemplateIsNeverPickedForAnInvoice(): void
    {
        [$default, , $other] = $this->invoiceTemplates();
        $default->setHidden(true);
        $service = $this->createServiceWithInvoiceTemplates([$default, $other]);

        self::assertSame($other, $service->resolveInvoiceTemplate(new Invoice()));
        self::assertSame($other, $service->resolveInvoiceTemplate(new Invoice(), $default));

        $other->setHidden(true);
        self::assertNull($service->resolveInvoiceTemplate(new Invoice()));
    }

    public function testEmbeddedTemplateDoesNotCountAsTheRemainingUnboundOne(): void
    {
        [$default, $cash, $other] = $this->invoiceTemplates();
        $other->setHidden(true);
        $service = $this->createServiceWithInvoiceTemplates([$cash, $default, $other]);

        // Binding or embedding the last visible unbound template is refused alike.
        $default->setPaymentMeans(PaymentMeansCode::CARD_PAYMENT);
        self::assertTrue($service->leavesNoUnboundInvoiceTemplate($default));

        $default->setPaymentMeans(null)->setHidden(true);
        self::assertTrue($service->leavesNoUnboundInvoiceTemplate($default));
    }

    /**
     * @return array{Template, Template, Template} default, bound to cash, plain
     */
    private function invoiceTemplates(): array
    {
        $type = new TemplateType();
        $type->setName('TEMPLATE_INVOICE_PDF');

        $default = (new Template())->setName('Rechnung')->setIsDefault(true)->setTemplateType($type);
        $cash = (new Template())->setName('Rechnung A5 bar')->setPaymentMeans(PaymentMeansCode::CASH)->setTemplateType($type);
        $other = (new Template())->setName('Rechnung Bemerkungen')->setTemplateType($type);

        return [$default, $cash, $other];
    }

    public function testVariantCopiesTheTemplateButNeverTakesOverDefaultOrPaymentMeans(): void
    {
        $type = new TemplateType();
        $type->setName('TEMPLATE_INVOICE_PDF');
        $base = new Template();
        $base->setName('Rechnung')->setSubject('Ihre Rechnung')->setText('<p>Text</p>')->setTemplateType($type)
            ->setIsDefault(true)->setPaymentMeans(PaymentMeansCode::CASH);

        $variant = $this->createService()->createVariant($base, 'en', 'Rechnung (Englisch)');

        self::assertSame('en', $variant->getLocale());
        self::assertSame($base, $variant->getBaseTemplate());
        self::assertSame($variant, $base->getVariant('en'));
        self::assertSame('<p>Text</p>', $variant->getText());
        self::assertSame('Ihre Rechnung', $variant->getSubject());
        self::assertSame($type, $variant->getTemplateType());
        self::assertFalse($variant->getIsDefault());
        self::assertNull($variant->getPaymentMeans());
    }

    public function testOnlyOneVariantPerLanguage(): void
    {
        $base = new Template();
        $base->setName('Bestätigung')->setText('x');
        $service = $this->createService();
        $service->createVariant($base, 'en', 'Bestätigung (Englisch)');

        $this->expectException(\InvalidArgumentException::class);
        $service->createVariant($base, 'en', 'Noch eine');
    }

    public function testTemplateRendersInItsLanguage(): void
    {
        $localeSwitcher = new \Symfony\Component\Translation\LocaleSwitcher('de', []);
        $intlDefault = \Locale::getDefault();
        $service = new TemplatesService(
            new Environment(new ArrayLoader()),
            $this->createStub(EntityManagerInterface::class),
            new RequestStack(),
            $this->createStub(MpdfService::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(TemplateRenderParamsResolver::class),
            $this->createStub(FilesystemOperator::class),
            $localeSwitcher,
        );
        $variant = (new Template())->setLocale('en');

        try {
            self::assertSame('en', $service->inTemplateLanguage($variant, static fn (): string => $localeSwitcher->getLocale()));
            self::assertSame('de', $service->inTemplateLanguage(new Template(), static fn (): string => $localeSwitcher->getLocale()));
            self::assertSame('de', $localeSwitcher->getLocale());
        } finally {
            \Locale::setDefault($intlDefault);
        }
    }

    /**
     * @param Template[] $templates
     */
    private function createServiceWithInvoiceTemplates(array $templates): TemplatesService
    {
        $repo = $this->createStub(TemplateRepository::class);
        $repo->method('loadByTypeName')->willReturn($templates);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        return new TemplatesService(
            new Environment(new ArrayLoader()),
            $em,
            new RequestStack(),
            $this->createStub(MpdfService::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(TemplateRenderParamsResolver::class),
            $this->createStub(FilesystemOperator::class)
        );
    }

    private function createService(?Environment $twig = null): TemplatesService
    {
        $twig ??= new Environment(new ArrayLoader());
        $translator = $this->createStub(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnCallback(static fn (string $id, array $params = []): string => strtr($id, $params));

        return new TemplatesService(
            $twig,
            $this->createStub(EntityManagerInterface::class),
            new RequestStack(),
            $this->createStub(MpdfService::class),
            $translator,
            $this->createStub(TemplateRenderParamsResolver::class),
            $this->createStub(FilesystemOperator::class)
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
            ->willReturnCallback(static fn (int|string $id) => $templates[$id] ?? null);
        $repo->method('findAll')
            ->willReturn(array_values($templates));

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')
            ->willReturn($repo);

        return new TemplatesService(
            $twig,
            $em,
            new RequestStack(),
            $this->createStub(MpdfService::class),
            $translator,
            $this->createStub(TemplateRenderParamsResolver::class),
            $this->createStub(FilesystemOperator::class)
        );
    }

    private function createServiceWithExportStorage(FilesystemOperator $exportStorage): TemplatesService
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new TemplatesService(
            new Environment(new ArrayLoader()),
            $this->createStub(EntityManagerInterface::class),
            new RequestStack(),
            $this->createStub(MpdfService::class),
            $translator,
            $this->createStub(TemplateRenderParamsResolver::class),
            $exportStorage
        );
    }

    private function exportStorageWith(string $filename, string $bytes): FilesystemOperator
    {
        $storage = $this->createStub(FilesystemOperator::class);
        $storage->method('fileExists')->willReturnCallback(static fn (string $path): bool => $path === $filename);
        $storage->method('read')->willReturnCallback(static fn (string $path): string => $path === $filename ? $bytes : '');

        return $storage;
    }

    /** A minimal, valid 1×1 transparent PNG. */
    private function onePixelPng(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
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

    private function makeVariant(Template $base, int $id, string $locale, string $text): Template
    {
        $variant = $this->makeTemplate($id, $base->getName().' ('.$locale.')', $text, (string) $base->getTemplateType()?->getName());
        $variant->setLocale($locale);
        $variant->setBaseTemplate($base);
        $base->getVariants()->add($variant);

        return $variant;
    }

    private function makeType(string $name): TemplateType
    {
        $type = new TemplateType();
        $type->setName($name);

        return $type;
    }

    /** The markup the editor stores for an embedded template. */
    private function includeSpan(int $id, string $name): string
    {
        return '<span data-template-id="'.$id.'" class="template-include" contenteditable="false">'.$name.'</span>';
    }
}
