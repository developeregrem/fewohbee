<?php

declare(strict_types=1);

namespace App\Tests\Unit\TemplatePreview;

use App\Entity\Template;
use App\Entity\TemplateType;
use App\Service\TemplatePreview\EntitylessEmailTemplatePreviewProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EntitylessEmailTemplatePreviewProviderTest extends TestCase
{
    #[DataProvider('supportedTemplateTypes')]
    public function testSupportsEntitylessEmailTemplateTypes(string $typeName): void
    {
        $provider = new EntitylessEmailTemplatePreviewProvider();
        $template = $this->createTemplate($typeName);

        self::assertTrue($provider->supportsPreview($template));
        self::assertSame([], $provider->buildRenderParams($template, null));
        self::assertSame([], $provider->buildPreviewRenderParams($template, []));
        self::assertSame([], $provider->getPreviewContextDefinition());
        self::assertSame([], $provider->buildSampleContext());
        self::assertSame([], $provider->getAvailableSnippets());
        self::assertSame([], $provider->getRenderParamsSchema());
    }

    public function testDoesNotSupportEntityBackedEmailTemplateTypes(): void
    {
        $provider = new EntitylessEmailTemplatePreviewProvider();

        self::assertFalse($provider->supportsPreview($this->createTemplate('TEMPLATE_RESERVATION_EMAIL')));
        self::assertFalse($provider->supportsPreview($this->createTemplate('TEMPLATE_INVOICE_EMAIL')));
    }

    public static function supportedTemplateTypes(): iterable
    {
        yield 'general email' => ['TEMPLATE_GENERAL_EMAIL'];
        yield 'newsletter email' => ['TEMPLATE_NEWSLETTER_EMAIL'];
    }

    private function createTemplate(string $typeName): Template
    {
        $type = new TemplateType();
        $type->setName($typeName);

        $template = new Template();
        $template->setTemplateType($type);

        return $template;
    }
}
