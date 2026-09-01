<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Appartment;
use App\Entity\Enum\PublicBookingMode;
use App\Entity\Enum\PublicBookingTheme;
use App\Entity\OnlineBookingConfig;
use App\Entity\User;
use App\Exception\PublicBookingException;
use App\Repository\WorkflowRepository;
use App\Service\OnlineBookingConfigService;
use App\Service\PublicBookingAbuseProtectionService;
use App\Service\PublicBookingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class PublicBookingControllerTest extends WebTestCase
{
    /** Ensure the public booking page is reachable without authentication. */
    public function testBookPageIsPubliclyReachable(): void
    {
        $client = self::createClient();
        $client->request('GET', '/book');

        self::assertResponseStatusCodeSame(200);
    }

    /** Ensure embed mode renders and keeps the public booking route accessible. */
    public function testBookPageEmbedModeLoads(): void
    {
        $client = self::createClient();
        $client->request('GET', '/book?embed=1');

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('--fhb-primary', (string) $client->getResponse()->getContent());
    }

    /** Ensure online-booking settings expose the canonical confirmation workflow via its compact edit button. */
    public function testSettingsLinkToBookingConfirmationWorkflow(): void
    {
        $client = self::createClient();
        $client->loginUser($this->getAdminUser(), 'main');

        $workflow = self::getContainer()->get(WorkflowRepository::class)->findBySystemCode('confirm_online_booking');
        self::assertNotNull($workflow);

        $crawler = $client->request('GET', '/settings/online-booking');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(sprintf(
            'a[href="/settings/workflows/%d/edit"] i.fa-pen',
            (int) $workflow->getId(),
        )));
        self::assertCount(0, $crawler->filter('[name="online_booking_config[confirmationEmailTemplateId]"]'));
    }

    /** Ensure submit validation errors keep the user on step three with availability and form input intact. */
    public function testSubmitValidationErrorKeepsPreviewState(): void
    {
        $client = self::createClient();
        $config = $this->createEnabledConfig();
        $availability = [[
            'typeKey' => 'category:1',
            'typeLabel' => 'Einzelzimmer',
            'typeDescription' => 'Ruhige Lage',
            'maxGuests' => 1,
            'availableCount' => 1,
            'roomIds' => [11],
            'subsidiaryIds' => [1],
            'occupancyOptions' => [['persons' => 1, 'totalPrice' => 80.0, 'totalPriceFormatted' => '80,00 €']],
        ]];

        $publicBookingService = $this->createMock(PublicBookingService::class);
        $publicBookingService->expects(self::once())
            ->method('validateEnabledConfig')
            ->willReturn(null);
        $publicBookingService->expects(self::once())
            ->method('buildSelectionPreview')
            ->willReturn([
                'availability' => $availability,
                'selected' => ['category:1' => [1 => 1]],
                'roomTotal' => 80.0,
                'roomTotalFormatted' => '80,00',
                'roomPriceBreakdown' => [[
                    'label' => 'Einzelzimmer',
                    'quantity' => 1,
                    'total' => 80.0,
                    'totalFormatted' => '80,00',
                ]],
                'modifierTotal' => 0.0,
                'modifierBreakdown' => [],
                'roomReservations' => [],
                'touristTaxTotal' => 0.0,
                'touristTaxTotalFormatted' => '0,00',
                'touristTaxLines' => [],
                'extras' => [],
                'selectedExtras' => [],
                'extrasTotal' => 0.0,
                'extrasTotalFormatted' => '0,00',
                'extrasBreakdown' => [],
                'grandTotal' => 80.0,
                'grandTotalFormatted' => '80,00',
            ]);
        $publicBookingService->expects(self::once())
            ->method('createBooking')
            ->willThrowException(new PublicBookingException('online_booking.error.booker_required'));

        $this->overrideBookingServices($publicBookingService, $config, $this->createNoopAbuseProtectionService());

        $client->request('POST', '/book', [
            'intent' => 'submit',
            'dateFrom' => '2099-05-10',
            'dateTo' => '2099-05-12',
            'persons' => 1,
            'roomsCount' => 1,
            'occ_category:1_p1' => 1,
            'firstname' => 'Max',
            'lastname' => 'Mustermann',
            'email' => 'max@example.com',
            'address' => 'Musterstrasse 1',
            'zip' => '12345',
            'city' => 'Berlin',
            'country' => 'Deutschland',
        ]);

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('alert alert-danger', $content);
        self::assertStringContainsString('value="Max"', $content);
        self::assertStringContainsString('value="Mustermann"', $content);
        self::assertStringContainsString('80,00', $content);
        self::assertStringContainsString('Einzelzimmer', $content);
        self::assertStringContainsString('name="comment"', $content);
        self::assertStringContainsString('name="intent" value="submit"', $content);
    }

    /** Ensure successful submit redirects and the GET success state no longer renders the form. */
    public function testSuccessfulSubmitUsesPrgAndDoesNotRenderFormAfterRedirect(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $config = $this->createEnabledConfig();
        $config->setSuccessMessageText('Danke fuer Ihre Anfrage.');

        $publicBookingService = $this->createMock(PublicBookingService::class);
        $publicBookingService->expects(self::exactly(2))
            ->method('validateEnabledConfig')
            ->willReturn(null);
        $publicBookingService->expects(self::once())
            ->method('createBooking')
            ->willReturn([
                'reservations' => [],
                'bookingGroupUuid' => Uuid::v4(),
                'roomTotal' => 80.0,
                'roomTotalFormatted' => '80,00',
                'roomPriceBreakdown' => [],
                'modifierTotal' => 0.0,
                'modifierBreakdown' => [],
            ]);

        $this->overrideBookingServices($publicBookingService, $config, $this->createNoopAbuseProtectionService());

        $client->followRedirects(false);
        $client->request('POST', '/book', [
            'intent' => 'submit',
            'dateFrom' => '2099-05-10',
            'dateTo' => '2099-05-12',
            'persons' => 1,
            'roomsCount' => 1,
            'occ_category:1_p1' => 1,
            'salutation' => 'Mr',
            'firstname' => 'Max',
            'lastname' => 'Mustermann',
            'email' => 'max@example.com',
            'address' => 'Musterstrasse 1',
            'zip' => '12345',
            'city' => 'Berlin',
            'country' => 'Deutschland',
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString('submitted=1', (string) $client->getResponse()->headers->get('Location'));

        $client->request('GET', '/book?submitted=1&mode=INQUIRY');
        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('alert alert-success', $content);
        self::assertStringContainsString('Danke fuer Ihre Anfrage.', $content);
        self::assertStringNotContainsString('<form method="post"', $content);
    }

    /** Ensure the classic theme keeps rendering its own frozen templates. */
    public function testClassicThemeRendersLegacyTemplates(): void
    {
        $client = self::createClient();
        $config = $this->createEnabledConfig();
        $config->setTheme(PublicBookingTheme::CLASSIC);
        $this->overrideBookingServices(
            $this->createEnabledBookingService(),
            $config,
            $this->createNoopAbuseProtectionService(),
        );

        $client->request('GET', '/book');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('--fhb-primary', $content);
        self::assertStringContainsString('class="fhb-booking-root"', $content);
        self::assertStringNotContainsString('public-booking-modern.css', $content);
    }

    /** Ensure the modern theme is used by default and loads its dedicated stylesheet. */
    public function testModernThemeIsDefaultAndLoadsItsStylesheet(): void
    {
        $client = self::createClient();
        $this->overrideBookingServices(
            $this->createEnabledBookingService(),
            $this->createEnabledConfig(),
            $this->createNoopAbuseProtectionService(),
        );

        $client->request('GET', '/book');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('public-booking-modern.css', $content);
        self::assertStringContainsString('--fhb-primary', $content);
        self::assertStringContainsString('class="fhb-steps"', $content);
    }

    public function testCalendarUsesABookabilityMessageForAnEmptyAvailabilityResult(): void
    {
        $client = self::createClient();
        $room = $this->getCalendarRoom();
        $config = $this->createEnabledConfig();
        $config->setMode(PublicBookingMode::CALENDAR);

        $publicBookingService = $this->createMock(PublicBookingService::class);
        $publicBookingService->expects(self::once())
            ->method('validateEnabledConfig')
            ->willReturn(null);
        $publicBookingService->expects(self::once())
            ->method('buildSelectionPreview')
            ->willReturn(['availability' => [], 'extras' => []]);

        $configService = $this->createStub(OnlineBookingConfigService::class);
        $configService->method('getConfig')->willReturn($config);
        $configService->method('getAllowedRoomIds')->willReturn([(int) $room->getId()]);
        $configService->method('getAllowedSubsidiaryIds')->willReturn([(int) $room->getObject()->getId()]);
        $configService->method('countBookableRooms')->willReturn(1);

        self::getContainer()->set(PublicBookingService::class, $publicBookingService);
        self::getContainer()->set(OnlineBookingConfigService::class, $configService);
        self::getContainer()->set(PublicBookingAbuseProtectionService::class, $this->createNoopAbuseProtectionService());

        $dateFrom = new \DateTimeImmutable('tomorrow');
        $client->request('POST', '/book', [
            'intent' => 'availability',
            'room' => (string) $room->getUuid(),
            'dateFrom' => $dateFrom->format('Y-m-d'),
            'dateTo' => $dateFrom->modify('+2 days')->format('Y-m-d'),
            'persons' => 1,
            'roomsCount' => 1,
        ]);

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(
            'Dieser Aufenthalt kann leider nicht online gebucht werden.',
            $content,
        );
        self::assertStringNotContainsString(
            'Für Ihre gewünschten Suchkriterien konnte leider kein passendes Zimmer gefunden werden.',
            $content,
        );
    }

    /** Ensure anonymous visitors cannot switch the theme through the preview parameter. */
    public function testPreviewThemeParameterIsIgnoredForAnonymousVisitors(): void
    {
        $client = self::createClient();
        $this->overrideBookingServices(
            $this->createEnabledBookingService(),
            $this->createEnabledConfig(),
            $this->createNoopAbuseProtectionService(),
        );

        $client->request('GET', '/book?previewTheme=classic');

        self::assertResponseIsSuccessful();
        // Configured theme is modern, so the preview request must not fall back to classic.
        self::assertStringContainsString('public-booking-modern.css', (string) $client->getResponse()->getContent());
    }

    /** Ensure administrators can preview the other theme without changing the configuration. */
    public function testPreviewThemeParameterAppliesForAdmins(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $client->loginUser($this->getAdminUser(), 'main');
        $this->overrideBookingServices(
            $this->createEnabledBookingService(),
            $this->createEnabledConfig(),
            $this->createNoopAbuseProtectionService(),
        );

        $client->request('GET', '/book?previewTheme=classic');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('public-booking-modern.css', (string) $client->getResponse()->getContent());
    }

    /**
     * A colour input can never submit an empty value, so a checkbox decides whether the
     * background colour applies at all. Both directions must survive a save.
     */
    public function testBackgroundColourFollowsItsCheckbox(): void
    {
        $client = self::createClient();
        $client->loginUser($this->getAdminUser(), 'main');

        // Switch it on with a colour …
        $crawler = $client->request('GET', '/settings/online-booking');
        $form = $crawler->filter('form[name="online_booking_config"]')->form();
        $form['online_booking_config[useBackgroundColor]']->tick();
        $form['online_booking_config[themeBackgroundColor]']->setValue('#123456');
        $client->submit($form);

        self::assertSame('#123456', $this->readConfig()->getThemeBackgroundColor());

        // Reloading must show the switch as on — otherwise the next save would clear
        // the colour again without the user touching anything.
        $crawler = $client->request('GET', '/settings/online-booking');
        self::assertNotEmpty(
            $crawler->filter('#online_booking_config_useBackgroundColor[checked]'),
            'The switch must be ticked while a colour is stored.'
        );

        // … and off again, which must clear it despite the colour still being submitted.
        $crawler = $client->request('GET', '/settings/online-booking');
        $form = $crawler->filter('form[name="online_booking_config"]')->form();
        $form['online_booking_config[useBackgroundColor]']->untick();
        $client->submit($form);

        self::assertNull($this->readConfig()->getThemeBackgroundColor());
    }

    /** Read the singleton config straight from the database. */
    private function readConfig(): OnlineBookingConfig
    {
        $em = $this->getEntityManager();
        $em->clear();

        $config = $em->getRepository(OnlineBookingConfig::class)->findOneBy([]);
        if (!$config instanceof OnlineBookingConfig) {
            self::fail('Online booking config not found.');
        }

        return $config;
    }

    /** Return the shared admin user from test fixtures. */
    private function getAdminUser(): User
    {
        $em = $this->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'test-admin']);

        if (!$user instanceof User) {
            self::fail('Admin user not found in test database.');
        }

        return $user;
    }

    /** Return the default entity manager for test data setup helpers. */
    private function getEntityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    /** Return one active room that the single-accommodation calendar can represent. */
    private function getCalendarRoom(): Appartment
    {
        $rooms = $this->getEntityManager()->getRepository(Appartment::class)->findBy(['active' => true]);
        foreach ($rooms as $room) {
            if (true !== $room->isMultipleOccupancy()) {
                return $room;
            }
        }

        self::fail('No usable room found in the test database.');
    }

    /** Replace the booking services in the test container for controller-level flow assertions. */
    private function overrideBookingServices(
        PublicBookingService $publicBookingService,
        OnlineBookingConfig $config,
        PublicBookingAbuseProtectionService $abuseProtectionService
    ): void
    {
        $configService = $this->createStub(OnlineBookingConfigService::class);
        $configService->method('getConfig')
            ->willReturn($config);

        self::getContainer()->set(PublicBookingService::class, $publicBookingService);
        self::getContainer()->set(OnlineBookingConfigService::class, $configService);
        self::getContainer()->set(PublicBookingAbuseProtectionService::class, $abuseProtectionService);
    }

    /** Build a minimal enabled config used in public controller tests. */
    private function createEnabledConfig(): OnlineBookingConfig
    {
        $config = new OnlineBookingConfig();
        $config->setEnabled(true);
        $config->setBookingMode(OnlineBookingConfig::BOOKING_MODE_INQUIRY);

        return $config;
    }

    /** Booking service stub that reports a valid, enabled configuration. */
    private function createEnabledBookingService(): PublicBookingService
    {
        $service = $this->createStub(PublicBookingService::class);
        $service->method('validateEnabledConfig')->willReturn(null);

        return $service;
    }

    /** Create a no-op abuse protection service mock so controller tests can focus on flow behavior. */
    private function createNoopAbuseProtectionService(): PublicBookingAbuseProtectionService
    {
        $service = $this->createStub(PublicBookingAbuseProtectionService::class);
        $service->method('createFormState')
            ->willReturnCallback(static fn (bool $includeSubmitToken): array => [
                'formStartedAt' => time() - 5,
                'submitToken' => $includeSubmitToken ? 'test-token' : null,
            ]);

        return $service;
    }
}
