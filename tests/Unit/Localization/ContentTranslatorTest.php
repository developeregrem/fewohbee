<?php

declare(strict_types=1);

namespace App\Tests\Unit\Localization;

use App\Entity\RoomCategory;
use App\Service\Localization\ContentTranslator;
use App\Service\Localization\GuestLocaleService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Translation\LocaleSwitcher;

final class ContentTranslatorTest extends TestCase
{
    public function testReturnsTranslationOfActiveLocale(): void
    {
        $category = $this->category('Doppelzimmer', ['en' => ['name' => 'Double room']]);

        self::assertSame('Double room', $this->translator('en')->text($category, 'name'));
    }

    public function testFallsBackToBaseTextWithoutTranslation(): void
    {
        $category = $this->category('Doppelzimmer', ['en' => ['details' => 'Sea view']]);

        self::assertSame('Doppelzimmer', $this->translator('en')->text($category, 'name'));
        self::assertSame('Doppelzimmer', $this->translator('fr')->text($category, 'name'));
        self::assertSame('Doppelzimmer', $this->translator('de')->text($category, 'name'));
    }

    public function testExplicitLocaleOverridesActiveLocale(): void
    {
        $category = $this->category('Doppelzimmer', ['en' => ['name' => 'Double room']]);

        self::assertSame('Double room', $this->translator('de')->text($category, 'name', 'en'));
        self::assertNull($this->translator('de')->text(null, 'name'));
    }

    public function testRejectsFieldsThatAreNotTranslatable(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->translator('en')->text($this->category('Doppelzimmer'), 'acronym');
    }

    public function testEntityDropsBlankTextsAndUnknownFields(): void
    {
        $category = $this->category('Doppelzimmer', [
            'en' => ['name' => '  Double room ', 'details' => '   ', 'acronym' => 'DR'],
            'fr' => ['name' => ''],
            '' => ['name' => 'x'],
        ]);

        self::assertSame(['en' => ['name' => 'Double room']], $category->getContentTranslations());
    }

    public function testApplySubmittedTakesOverOfferedLanguagesAndKeepsOthers(): void
    {
        $category = $this->category('Doppelzimmer', [
            'en' => ['name' => 'Old', 'details' => 'Old details'],
            'it' => ['name' => 'Camera doppia'],
        ]);

        $this->translator('de', ['en', 'fr'])->applySubmitted($category, [
            'en' => ['name' => 'Double room', 'details' => '', 'acronym' => 'DR'],
            'fr' => ['name' => str_repeat('a', 20)],
            'xx' => ['name' => 'ignored'],
        ], ['name' => 10, 'details' => null]);

        self::assertSame([
            'en' => ['name' => 'Double roo'],
            'fr' => ['name' => 'aaaaaaaaaa'],
            'it' => ['name' => 'Camera doppia'],
        ], $category->getContentTranslations());
    }

    public function testApplySubmittedIgnoresMalformedInput(): void
    {
        $category = $this->category('Doppelzimmer', ['en' => ['name' => 'Double room']]);

        $this->translator('de', ['en'])->applySubmitted($category, 'not-an-array', ['name' => 255]);

        self::assertSame([], $category->getContentTranslations());
    }

    /** @param array<string, mixed> $translations */
    private function category(string $name, array $translations = []): RoomCategory
    {
        $category = new RoomCategory();
        $category->setName($name);
        $category->setContentTranslations($translations);

        return $category;
    }

    /** @param list<string> $extraLocales */
    private function translator(string $activeLocale, array $extraLocales = []): ContentTranslator
    {
        $guestLocales = $this->createStub(GuestLocaleService::class);
        $guestLocales->method('extraLocales')->willReturn($extraLocales);

        return new ContentTranslator(new LocaleSwitcher($activeLocale, []), PropertyAccess::createPropertyAccessor(), $guestLocales);
    }
}
