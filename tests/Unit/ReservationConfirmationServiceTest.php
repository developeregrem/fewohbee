<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\AppSettings;
use App\Entity\Customer;
use App\Entity\Reservation;
use App\Entity\Template;
use App\Repository\ReservationRepository;
use App\Service\AppSettingsService;
use App\Service\Reservation\ReservationConfirmationService;
use App\Workflow\Action\SendTemplateEmailAction;
use App\Workflow\Attachment\ResolvedAttachmentSet;
use App\Workflow\Attachment\WorkflowAttachmentResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ReservationConfirmationServiceTest extends TestCase
{
    public function testWithoutAMailTemplateInTheSettingsThereIsNothingToPrepare(): void
    {
        $service = $this->service(new AppSettings());

        self::assertNull($service->emailTemplate());
        self::assertNull($service->prepare($this->reservation(1)));
    }

    public function testTheMailTemplateIsTakenInTheBookersLanguage(): void
    {
        $mail = (new Template())->setName('Buchungsbestätigung');
        $variant = (new Template())->setName('Booking confirmation')->setLocale('en')->setBaseTemplate($mail);
        $mail->getVariants()->add($variant);
        $confirmation = $this->service((new AppSettings())->setConfirmationTemplate($mail))->prepare($this->reservation(1, locale: 'en'));

        self::assertSame($variant, $confirmation?->emailTemplate);
    }

    public function testTheClickedRoomComesFirstFollowedByTheRestOfTheBooking(): void
    {
        $group = Uuid::v4();
        $earlier = $this->reservation(1, $group);
        $clicked = $this->reservation(2, $group);
        $later = $this->reservation(3, $group);

        $confirmation = $this->service(
            (new AppSettings())->setConfirmationTemplate((new Template())->setName('Buchungsbestätigung')),
            [$earlier, $clicked, $later],
        )->prepare($clicked);

        self::assertNotNull($confirmation);
        self::assertSame([$clicked, $earlier, $later], $confirmation->reservations);
        self::assertSame([], $confirmation->attachmentIds);
    }

    private function reservation(int $id, ?Uuid $group = null, ?string $locale = null): Reservation
    {
        $booker = new Customer();
        $booker->setLocale($locale);
        $reservation = new Reservation();
        (new \ReflectionProperty(Reservation::class, 'id'))->setValue($reservation, $id);
        $reservation->setBooker($booker);
        $reservation->setBookingGroupUuid($group);

        return $reservation;
    }

    /** @param Reservation[] $bookingGroup */
    private function service(AppSettings $settings, array $bookingGroup = []): ReservationConfirmationService
    {
        $appSettings = $this->createStub(AppSettingsService::class);
        $appSettings->method('getSettings')->willReturn($settings);
        $reservations = $this->createStub(ReservationRepository::class);
        $reservations->method('findByBookingGroupUuid')->willReturn($bookingGroup);
        $resolver = $this->createStub(WorkflowAttachmentResolver::class);
        $resolver->method('resolve')->willReturn(new ResolvedAttachmentSet());

        return new ReservationConfirmationService($appSettings, $reservations, $resolver, $this->createStub(EntityManagerInterface::class), $this->createStub(SendTemplateEmailAction::class));
    }
}
