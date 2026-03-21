<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\OnlineBookingConfig;
use App\Entity\Template;
use App\Entity\TemplateType;
use App\Entity\User;
use App\Exception\PublicBookingException;
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

    /** Ensure the settings form only lists reservation email templates in the confirmation template dropdown. */
    public function testSettingsTemplateDropdownIsFilteredToReservationEmailTemplates(): void
    {
        $client = self::createClient();
        $client->loginUser($this->getAdminUser(), 'main');

        $reservationEmailTemplate = $this->createTemplate('TEMPLATE_RESERVATION_EMAIL', 'Online Booking Email Template');
        $invoiceTemplate = $this->createTemplate('TEMPLATE_INVOICE_PDF', 'Should Not Be Listed');

        $client->request('GET', '/settings/online-booking');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($reservationEmailTemplate->getName(), $content);
        self::assertStringNotContainsString($invoiceTemplate->getName(), $content);
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
                'selected' => ['category:1' => 1],
                'roomTotal' => 80.0,
                'roomTotalFormatted' => '80,00',
                'roomPriceBreakdown' => [[
                    'label' => 'Einzelzimmer',
                    'quantity' => 1,
                    'total' => 80.0,
                    'totalFormatted' => '80,00',
                ]],
                'roomReservations' => [],
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
            'qty_category:1' => 1,
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
            ]);

        $this->overrideBookingServices($publicBookingService, $config, $this->createNoopAbuseProtectionService());

        $client->followRedirects(false);
        $client->request('POST', '/book', [
            'intent' => 'submit',
            'dateFrom' => '2099-05-10',
            'dateTo' => '2099-05-12',
            'persons' => 1,
            'roomsCount' => 1,
            'qty_category:1' => 1,
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

    /** Create a persisted template of the given type for settings form option assertions. */
    private function createTemplate(string $typeName, string $name): Template
    {
        $em = $this->getEntityManager();
        $type = $em->getRepository(TemplateType::class)->findOneBy(['name' => $typeName]);
        if (!$type instanceof TemplateType) {
            self::fail(sprintf('TemplateType "%s" not found in test database.', $typeName));
        }

        $template = new Template();
        $template->setName($name);
        $template->setText('[[ reservation1.booker.lastname ]]');
        $template->setTemplateType($type);
        $template->setIsDefault(false);
        $em->persist($template);
        $em->flush();

        return $template;
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
