<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\Reservation;
use App\Entity\Template;
use App\Repository\ReservationRepository;
use App\Repository\TemplateRepository;
use App\Service\Reservation\ReservationConfirmationService;
use App\Workflow\Action\SendTemplateEmailAction;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ReservationConfirmationServiceTest extends TestCase
{
    public function testWithoutADefaultMailTemplateNothingCanBeSent(): void
    {
        $confirmation = $this->service([
            'TEMPLATE_RESERVATION_EMAIL' => [$this->template('Buchungsbestätigung')],
            'TEMPLATE_RESERVATION_PDF' => [],
        ])->prepare($this->reservationWithBookerEmail());

        self::assertNull($confirmation->emailTemplate);
        self::assertFalse($confirmation->canBeSent());
    }

    public function testTheDefaultTemplatesAreTakenWithTheBaseTemplateOverItsLanguageVariant(): void
    {
        $mail = $this->template('Buchungsbestätigung', default: true);
        $variant = $this->template('Booking confirmation')->setLocale('en')->setBaseTemplate($mail);
        $pdf = $this->template('Bestätigung PDF', default: true);

        $confirmation = $this->service([
            'TEMPLATE_RESERVATION_EMAIL' => [$variant, $mail],
            'TEMPLATE_RESERVATION_PDF' => [$this->template('Anderes PDF'), $pdf],
        ])->prepare($this->reservationWithBookerEmail());

        self::assertSame($mail, $confirmation->emailTemplate);
        self::assertSame($pdf, $confirmation->pdfTemplate);
        self::assertTrue($confirmation->canBeSent());
    }

    public function testHiddenTemplatesNeverCountEvenAsDefault(): void
    {
        $confirmation = $this->service([
            'TEMPLATE_RESERVATION_EMAIL' => [$this->template('Briefkopf', default: true)->setHidden(true)],
            'TEMPLATE_RESERVATION_PDF' => [$this->template('Briefkopf PDF', default: true)->setHidden(true)],
        ])->prepare($this->reservationWithBookerEmail());

        self::assertNull($confirmation->emailTemplate);
        self::assertNull($confirmation->pdfTemplate);
    }

    private function template(string $name, bool $default = false): Template
    {
        $template = (new Template())->setName($name);
        $template->setIsDefault($default);

        return $template;
    }

    private function reservationWithBookerEmail(): Reservation
    {
        $address = new CustomerAddresses();
        $address->setEmail('gast@example.com');
        $booker = new Customer();
        $booker->addCustomerAddress($address);
        $reservation = new Reservation();
        $reservation->setBooker($booker);

        return $reservation;
    }

    /**
     * @param array<string, list<Template>> $templatesByType
     */
    private function service(array $templatesByType): ReservationConfirmationService
    {
        $templateRepository = $this->createStub(TemplateRepository::class);
        $templateRepository->method('loadByTypeName')
            ->willReturnCallback(static fn (array $names): array => $templatesByType[$names[0]] ?? []);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($templateRepository);

        return new ReservationConfirmationService(
            $this->createStub(ReservationRepository::class),
            $em,
            $this->createStub(SendTemplateEmailAction::class),
        );
    }
}
