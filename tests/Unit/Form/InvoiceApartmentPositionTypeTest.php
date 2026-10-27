<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Entity\Appartment;
use App\Entity\InvoiceAppartment;
use App\Entity\Reservation;
use App\Form\InvoiceApartmentPositionType;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

final class InvoiceApartmentPositionTypeTest extends TestCase
{
    #[DataProvider('positionInputs')]
    public function testSubmitValidatesRequiredValues(string $field, string $value, bool $valid, bool $existing = false): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension($validator))
            ->getFormFactory();
        $room = new Appartment();
        $room->setNumber('001');
        $room->setDescription('Room');
        $room->setBedsMax(2);
        $reservation = new Reservation();
        $reservation->setAppartment($room);
        $reservation->setPersons(2);
        $position = new InvoiceAppartment();
        if ($existing) {
            $position->setVat(19);
        }
        $form = $factory->create(InvoiceApartmentPositionType::class, $position, ['reservations' => [$reservation]]);
        $data = [
            'number' => '001',
            'description' => 'Room',
            'beds' => '2',
            'persons' => '2',
            'startDate' => '2031-06-01',
            'endDate' => '2031-06-03',
            'price' => '12.34',
            'vat' => '7.5',
        ];
        $data[$field] = $value;

        $form->submit($data);

        self::assertSame($valid, $form->isValid(), (string) $form->getErrors(true));
        if ($valid) {
            self::assertSame((float) $data['vat'], $position->getVat());
        } else {
            self::assertGreaterThan(0, $form->get($field)->getErrors()->count());
        }
    }

    /** @return iterable<string, array{string, string, bool, 3?: bool}> */
    public static function positionInputs(): iterable
    {
        yield 'missing bed count' => ['beds', '', false];
        yield 'missing person count' => ['persons', '', false];
        yield 'missing price' => ['price', '', false];
        yield 'negative room price' => ['price', '-12.34', false];
        yield 'zero price' => ['price', '0', true];
        yield 'positive price' => ['price', '12.34', true];
        foreach ([false, true] as $existing) {
            $context = $existing ? 'existing' : 'new';
            yield $context.' missing VAT rate' => ['vat', '', false, $existing];
            yield $context.' zero VAT rate' => ['vat', '0', true, $existing];
            yield $context.' fractional VAT rate' => ['vat', '7.5', true, $existing];
        }
    }

    #[DataProvider('reservationContainers')]
    public function testRoomChoicesKeepTheirKeysAndMaximumOccupancy(bool $collection, bool $empty): void
    {
        $reservations = [];
        if (!$empty) {
            foreach (['001' => 2, '2' => 4] as $number => $beds) {
                $room = new Appartment();
                $room->setNumber((string) $number);
                $room->setDescription('Room '.$number);
                $room->setBedsMax($beds);
                $reservation = new Reservation();
                $reservation->setAppartment($room);
                $reservation->setPersons($beds - 1);
                // Selection keys may be sparse after a reservation was removed.
                $reservations[$beds] = $reservation;
            }
        }
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension($validator))
            ->getFormFactory();

        $form = $factory->create(InvoiceApartmentPositionType::class, new InvoiceAppartment(), [
            'reservations' => $collection ? new ArrayCollection($reservations) : $reservations,
        ]);

        self::assertSame($empty ? [] : ['001' => '001', 2 => '2'], $form->get('number')->getConfig()->getOption('choices'));
        self::assertSame($empty ? [] : [1 => 1, 2 => 2, 3 => 3], $form->get('persons')->getConfig()->getOption('choices'));
        self::assertSame($empty ? [] : [1 => 1, 2 => 2, 3 => 3, 4 => 4], $form->get('beds')->getConfig()->getOption('choices'));
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function reservationContainers(): iterable
    {
        yield 'sparse array' => [false, false];
        yield 'sparse collection' => [true, false];
        yield 'empty array' => [false, true];
        yield 'empty collection' => [true, true];
    }
}
