<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\OnlineBookingConfig;
use App\Exception\PublicBookingException;
use App\Service\OnlineBooking\OnlineBookingConfigService;
use App\Service\OnlineBooking\PublicBookingAbuseProtectionService;
use App\Service\OnlineBooking\PublicBookingService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Language of the public booking page: ?lang=, the browser language and the embedded case.
 * The test installation runs in German and offers every enabled language to guests.
 */
final class PublicBookingLanguageTest extends WebTestCase
{
    public function testExplicitLanguageRendersPageAndOperatorTextsInThatLanguage(): void
    {
        $client = self::createClient();
        $config = $this->createEnabledConfig();
        $config->setPaymentTerms('<p>Zahlung bei Anreise</p>');
        $config->setContentTranslations(['en' => ['paymentTerms' => '<p>Payment on arrival</p>']]);
        $this->overrideBookingServices($config);

        $crawler = $client->request('POST', '/book?lang=en', $this->submitData());

        self::assertResponseIsSuccessful();
        self::assertSame('en', $crawler->filter('html')->attr('lang'));
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Payment on arrival', $content);
        self::assertStringNotContainsString('Zahlung bei Anreise', $content);
        self::assertStringContainsString('lang=en', (string) $crawler->filter('form#fhb-booking-form')->attr('action'));
    }

    public function testSalutationsAreOfferedInTheGuestLanguageButSendTheStoredWording(): void
    {
        $client = self::createClient();
        $this->overrideBookingServices($this->createEnabledConfig());

        $crawler = $client->request('POST', '/book?lang=en', $this->submitData());

        self::assertResponseIsSuccessful();
        self::assertSame('Ms', trim($crawler->filter('select#salutation option[value="Frau"]')->text()));
        self::assertSame('Mr', trim($crawler->filter('select#salutation option[value="Herr"]')->text()));
        self::assertSame('Family', trim($crawler->filter('select#salutation option[value="Familie"]')->text()));
    }

    public function testMissingTranslationFallsBackToTheStoredText(): void
    {
        $client = self::createClient();
        $config = $this->createEnabledConfig();
        $config->setPaymentTerms('<p>Zahlung bei Anreise</p>');
        $this->overrideBookingServices($config);

        $client->request('POST', '/book?lang=en', $this->submitData());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Zahlung bei Anreise', (string) $client->getResponse()->getContent());
    }

    public function testEmbeddedPageIgnoresTheBrowserLanguageAndOffersNoSwitcher(): void
    {
        $client = self::createClient();
        $this->overrideBookingServices($this->createEnabledConfig());

        $crawler = $client->request('GET', '/book?embed=1', server: ['HTTP_ACCEPT_LANGUAGE' => 'en-GB,en;q=0.9']);

        self::assertResponseIsSuccessful();
        self::assertSame('de', $crawler->filter('html')->attr('lang'));
        self::assertCount(0, $crawler->filter('nav.fhb-lang'));
    }

    public function testEmbeddedPageTakesTheLanguageFromTheParameter(): void
    {
        $client = self::createClient();
        $this->overrideBookingServices($this->createEnabledConfig());

        $crawler = $client->request('GET', '/book?embed=1&lang=en');

        self::assertResponseIsSuccessful();
        self::assertSame('en', $crawler->filter('html')->attr('lang'));
        self::assertCount(0, $crawler->filter('nav.fhb-lang'));
        $action = (string) $crawler->filter('form#fhb-booking-form')->attr('action');
        self::assertStringContainsString('embed=1', $action);
        self::assertStringContainsString('lang=en', $action);
    }

    public function testStandAlonePageFollowsTheBrowserAndOffersASwitcher(): void
    {
        $client = self::createClient();
        $this->overrideBookingServices($this->createEnabledConfig());

        $crawler = $client->request('GET', '/book', server: ['HTTP_ACCEPT_LANGUAGE' => 'en-GB,en;q=0.9']);

        self::assertResponseIsSuccessful();
        self::assertSame('en', $crawler->filter('html')->attr('lang'));
        self::assertCount(1, $crawler->filter('nav.fhb-lang a[hreflang="de"]'));
        self::assertCount(1, $crawler->filter('nav.fhb-lang a[hreflang="en"][aria-current="true"]'));
        // Detected, not chosen: the form does not pin the language.
        self::assertStringNotContainsString('lang=', (string) $crawler->filter('form#fhb-booking-form')->attr('action'));
    }

    public function testSwitcherRepostsTheWizardOnceAStepIsReached(): void
    {
        $client = self::createClient();
        $this->overrideBookingServices($this->createEnabledConfig());

        $crawler = $client->request('POST', '/book', $this->submitData());

        self::assertResponseIsSuccessful();
        $button = $crawler->filter('nav.fhb-lang button[lang="en"]');
        self::assertCount(1, $button);
        self::assertSame('fhb-booking-form', $button->attr('form'));
        self::assertSame('preview', $button->attr('value'));
        self::assertStringContainsString('lang=en', (string) $button->attr('formaction'));
    }

    public function testUnknownLanguageFallsBackToTheInstallationLanguage(): void
    {
        $client = self::createClient();
        $this->overrideBookingServices($this->createEnabledConfig());

        $crawler = $client->request('GET', '/book?lang=xx', server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE']);

        self::assertResponseIsSuccessful();
        self::assertSame('de', $crawler->filter('html')->attr('lang'));
    }

    public function testRedirectAfterSubmitKeepsTheChosenLanguage(): void
    {
        $client = self::createClient();
        $client->disableReboot();

        $publicBookingService = $this->createStub(PublicBookingService::class);
        $publicBookingService->method('validateEnabledConfig')->willReturn(null);
        $publicBookingService->method('createBooking')->willReturn([
            'reservations' => [],
            'bookingGroupUuid' => Uuid::v4(),
            'roomTotal' => 80.0,
            'roomTotalFormatted' => '80.00',
            'roomPriceBreakdown' => [],
            'modifierTotal' => 0.0,
            'modifierBreakdown' => [],
        ]);
        $this->overrideBookingServices($this->createEnabledConfig(), $publicBookingService);

        $client->followRedirects(false);
        $client->request('POST', '/book?embed=1&lang=en', $this->submitData() + ['embed' => 1]);

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('lang=en', $location);
        self::assertStringContainsString('embed=1', $location);
    }

    /** @return array<string, int|string> */
    private function submitData(): array
    {
        return [
            'intent' => 'submit',
            'dateFrom' => '2099-05-10',
            'dateTo' => '2099-05-12',
            'persons' => 1,
            'roomsCount' => 1,
            'occ_category:1_p1' => 1,
        ];
    }

    private function createEnabledConfig(): OnlineBookingConfig
    {
        $config = new OnlineBookingConfig();
        $config->setEnabled(true);
        $config->setBookingMode(OnlineBookingConfig::BOOKING_MODE_INQUIRY);

        return $config;
    }

    /**
     * Stubs the booking flow: a submit fails on the booker details, so step three with its
     * summary is shown again.
     */
    private function overrideBookingServices(OnlineBookingConfig $config, ?PublicBookingService $publicBookingService = null): void
    {
        if (null === $publicBookingService) {
            $publicBookingService = $this->createStub(PublicBookingService::class);
            $publicBookingService->method('validateEnabledConfig')->willReturn(null);
            $publicBookingService->method('buildSelectionPreview')->willReturn($this->singleRoomPreview());
            $publicBookingService->method('createBooking')
                ->willThrowException(new PublicBookingException('online_booking.error.booker_required'));
        }

        $configService = $this->createStub(OnlineBookingConfigService::class);
        $configService->method('getConfig')->willReturn($config);

        $abuseProtectionService = $this->createStub(PublicBookingAbuseProtectionService::class);
        $abuseProtectionService->method('createFormState')
            ->willReturnCallback(static fn (bool $includeSubmitToken): array => [
                'formStartedAt' => time() - 5,
                'submitToken' => $includeSubmitToken ? 'test-token' : null,
            ]);

        self::getContainer()->set(PublicBookingService::class, $publicBookingService);
        self::getContainer()->set(OnlineBookingConfigService::class, $configService);
        self::getContainer()->set(PublicBookingAbuseProtectionService::class, $abuseProtectionService);
    }

    /** @return array<string, mixed> */
    private function singleRoomPreview(): array
    {
        return [
            'availability' => [[
                'typeKey' => 'category:1',
                'typeLabel' => 'Single room',
                'typeDescription' => null,
                'maxGuests' => 1,
                'availableCount' => 1,
                'roomIds' => [11],
                'subsidiaryIds' => [1],
                'occupancyOptions' => [['persons' => 1, 'totalPrice' => 80.0, 'totalPriceFormatted' => '80.00']],
            ]],
            'selected' => ['category:1' => [1 => 1]],
            'roomTotal' => 80.0,
            'roomTotalFormatted' => '80.00',
            'roomPriceBreakdown' => [['label' => 'Single room', 'quantity' => 1, 'total' => 80.0, 'totalFormatted' => '80.00']],
            'modifierTotal' => 0.0,
            'modifierBreakdown' => [],
            'roomReservations' => [],
            'touristTaxTotal' => 0.0,
            'touristTaxTotalFormatted' => '0.00',
            'touristTaxLines' => [],
            'extras' => [],
            'selectedExtras' => [],
            'extrasTotal' => 0.0,
            'extrasTotalFormatted' => '0.00',
            'extrasBreakdown' => [],
            'grandTotal' => 80.0,
            'grandTotalFormatted' => '80.00',
        ];
    }
}
