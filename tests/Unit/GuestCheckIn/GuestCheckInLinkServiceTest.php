<?php

declare(strict_types=1);

namespace App\Tests\Unit\GuestCheckIn;

use App\Entity\Appartment;
use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Repository\GuestCheckInRepository;
use App\Service\GuestCheckIn\GuestCheckInConfigService;
use App\Service\GuestCheckIn\GuestCheckInLinkService;
use App\Service\GuestCheckIn\GuestCheckInPolicy;
use App\Service\GuestCheckIn\GuestCheckInTokenSigner;
use App\Service\PublicUrlService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class GuestCheckInLinkServiceTest extends TestCase
{
    public function testLinkIsCreatedOnceAndReusedWithinTheRequest(): void
    {
        $repository = $this->createMock(GuestCheckInRepository::class);
        $repository->expects($this->once())->method('ensureSelector')->willReturn('AAAAAAAAAAAAAAAAAAAAAA');
        $signer = new GuestCheckInTokenSigner('secret');
        $service = $this->service($repository, true, 'https://example.com', $signer);

        $url = $service->url($this->reservation());

        self::assertSame('https://example.com/checkin/'.$signer->linkToken('AAAAAAAAAAAAAAAAAAAAAA'), $url);
        self::assertSame($url, $service->url($this->reservation()));
    }

    public function testNoLinkIsCreatedWhileTheFeatureIsOff(): void
    {
        $repository = $this->createMock(GuestCheckInRepository::class);
        $repository->expects($this->never())->method('ensureSelector');

        self::assertNull($this->service($repository, false, 'https://example.com')->url($this->reservation()));
    }

    public function testNoLinkIsCreatedWithoutPublicAddress(): void
    {
        $repository = $this->createMock(GuestCheckInRepository::class);
        $repository->expects($this->never())->method('ensureSelector');

        self::assertNull($this->service($repository, true, null)->url($this->reservation()));
    }

    public function testPreviewLinkNeverTouchesTheDatabase(): void
    {
        $repository = $this->createMock(GuestCheckInRepository::class);
        $repository->expects($this->never())->method($this->anything());

        $url = $this->service($repository, true, 'https://example.com')->previewUrl();

        self::assertSame('https://example.com/checkin/'.str_repeat('x', GuestCheckInTokenSigner::TOKEN_LENGTH), $url);
        self::assertNull($this->service($repository, false, 'https://example.com')->previewUrl());
    }

    public function testForgedTokenIsRejectedWithoutQuery(): void
    {
        $repository = $this->createMock(GuestCheckInRepository::class);
        $repository->expects($this->never())->method('findOneBySelector');

        self::assertNull($this->service($repository, true, 'https://example.com')->resolve(str_repeat('A', GuestCheckInTokenSigner::TOKEN_LENGTH)));
    }

    public function testQrCodeSizeIsClamped(): void
    {
        $service = $this->service($this->createStub(GuestCheckInRepository::class), true, 'https://example.com');

        $dataUri = $service->qrDataUri('https://example.com/checkin/abc', 5000);

        self::assertStringStartsWith('data:image/png;base64,', $dataUri);
        $size = getimagesizefromstring((string) base64_decode(substr($dataUri, \strlen('data:image/png;base64,')), true));
        self::assertIsArray($size);
        self::assertLessThanOrEqual(1000, $size[0]);
    }

    private function service(GuestCheckInRepository $repository, bool $enabled, ?string $baseUrl, ?GuestCheckInTokenSigner $signer = null): GuestCheckInLinkService
    {
        $config = $this->createStub(GuestCheckInConfigService::class);
        $config->method('isEnabled')->willReturn($enabled);

        $publicUrl = $this->createStub(PublicUrlService::class);
        $publicUrl->method('getBaseUrl')->willReturn($baseUrl);
        $publicUrl->method('generate')->willReturnCallback(
            static fn (string $route, array $parameters): ?string => null === $baseUrl ? null : $baseUrl.'/checkin/'.$parameters['token'],
        );

        $clock = new MockClock('2026-09-20 12:00', date_default_timezone_get());

        return new GuestCheckInLinkService(
            $repository,
            $config,
            new GuestCheckInPolicy($clock),
            $signer ?? new GuestCheckInTokenSigner('secret'),
            $publicUrl,
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(EntityManagerInterface::class),
            $clock,
        );
    }

    private function reservation(): Reservation
    {
        $reservation = new Reservation();
        (new \ReflectionProperty(Reservation::class, 'id'))->setValue($reservation, 42);
        $reservation->setStartDate(new \DateTime('2026-10-10'));
        $reservation->setEndDate(new \DateTime('2026-10-12'));
        $reservation->setAppartment(new Appartment());
        $reservation->setReservationStatus(new ReservationStatus());

        return $reservation;
    }
}
