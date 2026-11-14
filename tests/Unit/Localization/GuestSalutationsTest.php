<?php

declare(strict_types=1);

namespace App\Tests\Unit\Localization;

use App\Entity\AppSettings;
use App\Service\AppSettingsService;
use App\Service\Localization\GuestLocaleService;
use App\Service\Localization\GuestSalutations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

final class GuestSalutationsTest extends TestCase
{
    private AppSettings $settings;

    protected function setUp(): void
    {
        $this->settings = new AppSettings();
    }

    public function testBuiltInKeysAreStoredInTheInstallationLanguageAndReadInTheGuestLanguage(): void
    {
        $salutations = $this->salutations('en');

        self::assertSame(['Frau', 'Herr', 'Familie'], $salutations->offered());
        self::assertSame(['Ms' => 'Frau', 'Mr' => 'Herr', 'Family' => 'Familie'], $salutations->choices());
        self::assertSame('Frau', $salutations->label('Frau', 'de'));
    }

    public function testOperatorTranslationsWinAndOwnEntriesStayAsTheyAre(): void
    {
        $this->settings->setCustomerSalutations(['Frau', 'Herr', 'Dr.']);
        $this->settings->setCustomerSalutationTranslations(['Frau' => ['en' => 'Mrs']]);
        $salutations = $this->salutations('en');

        self::assertSame('Mrs', $salutations->label('Frau'));
        self::assertSame('Herr', $salutations->label('Herr'), 'No translation: the stored wording.');
        self::assertSame('Dr.', $salutations->label('Dr.'));
    }

    public function testAnyKnownWordingNormalizesToTheStoredSalutation(): void
    {
        $this->settings->setCustomerSalutations(['Frau', 'Herr']);
        $this->settings->setCustomerSalutationTranslations(['Frau' => ['en' => 'Ms'], 'Herr' => ['en' => 'Mr']]);
        $salutations = $this->salutations('de');

        self::assertSame('Frau', $salutations->normalize('Frau'));
        self::assertSame('Herr', $salutations->normalize(' Mr '));
        self::assertNull($salutations->normalize('Lord'));
        self::assertNull($salutations->normalize(''));
    }

    public function testLegacyKeysAreAcceptedFromTheApi(): void
    {
        $salutations = $this->salutations('de');

        self::assertSame('Herr', $salutations->normalize('Mr'));
        self::assertSame('Familie', $salutations->normalize('Family'));
    }

    public function testSettingsRowsShowOnlyWordingsThatDiffer(): void
    {
        $this->settings->setCustomerSalutations(['Ms', 'Dr.']);

        self::assertSame([
            ['salutation' => 'Frau', 'en' => 'Ms'],
            ['salutation' => 'Dr.', 'en' => ''],
        ], $this->salutations('de')->settingsRows());
    }

    public function testSavingTheTableStoresWordingsAndDropsBlanksAndRepeats(): void
    {
        $this->settings->setCustomerSalutationTranslations(['Frau' => ['en' => 'Ms', 'fr' => 'Madame']]);

        $this->salutations('de')->applySettingsRows($this->settings, [
            ['salutation' => ' Frau ', 'en' => 'Mrs'],
            ['salutation' => 'Herr', 'en' => 'Herr'],
            ['salutation' => '', 'en' => 'Orphan'],
            ['salutation' => 'Frau', 'en' => 'Again'],
            'garbage',
        ]);

        self::assertSame(['Frau', 'Herr'], $this->settings->getCustomerSalutations());
        // French is not offered right now and keeps its wording; equal to the stored text is not kept.
        self::assertSame(['Frau' => ['en' => 'Mrs', 'fr' => 'Madame']], $this->settings->getCustomerSalutationTranslations());
    }

    private function salutations(string $activeLocale): GuestSalutations
    {
        $settingsService = $this->createStub(AppSettingsService::class);
        $settingsService->method('getSettings')->willReturn($this->settings);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string => 'de' === $locale
                ? (['Ms' => 'Frau', 'Mr' => 'Herr', 'Family' => 'Familie'][$id] ?? $id)
                : $id,
        );

        $guestLocales = $this->createStub(GuestLocaleService::class);
        $guestLocales->method('baseLocale')->willReturn('de');
        $guestLocales->method('enabledLocales')->willReturn(['de', 'en']);
        $guestLocales->method('extraLocales')->willReturn(['en']);

        return new GuestSalutations($settingsService, $translator, $guestLocales, new LocaleSwitcher($activeLocale, []));
    }
}
