<?php

declare(strict_types=1);

namespace App\Tests\Unit\Localization;

use App\Entity\AppSettings;
use App\Service\AppSettingsService;
use App\Service\Localization\GuestLocaleService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\LocaleSwitcher;

final class GuestLocaleServiceTest extends TestCase
{
    private string $intlDefault;

    protected function setUp(): void
    {
        $this->intlDefault = \Locale::getDefault();
    }

    protected function tearDown(): void
    {
        // LocaleSwitcher::setLocale() changes the process-wide Intl default.
        \Locale::setDefault($this->intlDefault);
    }

    public function testUnsetSelectionOffersEveryEnabledLocaleWithBaseFirst(): void
    {
        $service = $this->createService(null, 'de', ['en', 'fr', 'de']);

        self::assertSame(['de', 'en', 'fr'], $service->guestLocales());
        self::assertSame(['en', 'fr'], $service->extraLocales());
    }

    public function testSelectionIsLimitedToEnabledLocalesAndAlwaysContainsBase(): void
    {
        $service = $this->createService(['fr', 'it'], 'de', ['de', 'en', 'fr']);

        self::assertSame(['de', 'fr'], $service->guestLocales());
        self::assertFalse($service->isGuestLocale('en'));
        self::assertFalse($service->isGuestLocale('it'));
    }

    public function testNormalizeSelectionStoresNullWhenEverythingIsSelected(): void
    {
        $service = $this->createService(null, 'de', ['de', 'en', 'fr']);

        self::assertNull($service->normalizeSelection(['en', 'fr']));
        self::assertSame(['de', 'fr'], $service->normalizeSelection(['fr', 'xx']));
        self::assertSame(['de'], $service->normalizeSelection([]));
    }

    public function testExplicitLanguageWinsOverBrowser(): void
    {
        $service = $this->createService(null, 'de', ['de', 'en']);
        $request = Request::create('/book', 'GET', ['lang' => 'en']);
        $request->headers->set('Accept-Language', 'de-DE');

        self::assertSame('en', $service->applyToRequest($request));
        self::assertSame('en', $request->getLocale());
    }

    public function testBrowserLanguageIsUsedWithoutExplicitLanguage(): void
    {
        $service = $this->createService(null, 'de', ['de', 'en']);
        $request = Request::create('/book');
        $request->headers->set('Accept-Language', 'en-GB,en;q=0.9');

        self::assertSame('en', $service->applyToRequest($request));
    }

    public function testEmbeddedPagesIgnoreTheBrowserLanguage(): void
    {
        $service = $this->createService(null, 'de', ['de', 'en']);
        $request = Request::create('/book', 'GET', ['embed' => '1']);
        $request->headers->set('Accept-Language', 'en-GB');

        self::assertSame('de', $service->applyToRequest($request, false));
    }

    public function testLanguageNotOfferedFallsBackToBase(): void
    {
        $service = $this->createService(['de'], 'de', ['de', 'en']);
        $request = Request::create('/book', 'GET', ['lang' => 'en']);
        $request->headers->set('Accept-Language', 'en-GB');

        self::assertSame('de', $service->applyToRequest($request));
        self::assertNull($service->requestedLocale($request));
    }

    public function testLanguageNamesAreNativeForGuests(): void
    {
        $service = $this->createService(null, 'de', ['de', 'en', 'fr']);

        self::assertSame('English', $service->languageName('en'));
        self::assertSame('Français', $service->languageName('fr'));
        self::assertSame('Englisch', $service->languageName('en', false));
    }

    /**
     * @param list<string>|null $selected
     * @param list<string>      $enabled
     */
    private function createService(?array $selected, string $base, array $enabled): GuestLocaleService
    {
        $settings = (new AppSettings())->setGuestLocales($selected);
        $settingsService = $this->createStub(AppSettingsService::class);
        $settingsService->method('getSettings')->willReturn($settings);

        return new GuestLocaleService($settingsService, new LocaleSwitcher($base, []), $base, $enabled);
    }
}
