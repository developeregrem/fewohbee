<?php

declare(strict_types=1);
/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\DataFixtures;

use App\Entity\Appartment;
use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\Price;
use App\Entity\ReservationOrigin;
use App\Entity\ReservationStatus;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Service\CalendarSyncService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Contracts\Translation\TranslatorInterface;

class SettingsFixtures extends Fixture implements FixtureGroupInterface
{
    private $translator;
    private $syncService;

    public function __construct(TranslatorInterface $translator, CalendarSyncService $css)
    {
        $this->translator = $translator;
        $this->syncService = $css;
    }

    public static function getGroups(): array
    {
        return ['settings', 'customer'];
    }

    public function load(ObjectManager $manager): void
    {
        $this->createRoomCategories($manager);
        $this->createOrigins($manager);

        $manager->flush();

        $categories = $manager->getRepository(RoomCategory::class)->findAll();
        $subsid = $manager->getRepository(Subsidiary::class)->find(1); // assuemes app:first-run was executed already

        $this->createRooms($manager, $categories, $subsid);

        $origins = $manager->getRepository(ReservationOrigin::class)->findAll();

        $this->createPrices($manager, $categories, $origins);

        $this->createCustomer($manager);

        $this->createReservationStatus($manager);

        $manager->flush();
    }

    private function createRoomCategories(ObjectManager $manager): void
    {
        $roomCats = [
                $this->translator->trans('category.single'),
                $this->translator->trans('category.double'),
            ];
        foreach ($roomCats as $roomCat) {
            $cat = new RoomCategory();
            $cat->setName($roomCat);
            $manager->persist($cat);
        }
    }

    private function createOrigins(ObjectManager $manager): void
    {
        $origins = [
                $this->translator->trans('reservationorigin.private'),
                $this->translator->trans('reservationorigin.business'),
            ];
        foreach ($origins as $origin) {
            $o = new ReservationOrigin();
            $o->setName($origin);
            $manager->persist($o);
        }
    }

    private function createRooms(ObjectManager $manager, $roomCats, Subsidiary $subsidiary): void
    {
        // create 10 room, 5 single, 5 double
        for ($i = 1; $i <= 10; ++$i) {
            $app = new Appartment();
            $app->setNumber((string)$i);
            $app->setObject($subsidiary);
            if ($i > 5) {
                $app->setBedsMax(2);
                $app->setRoomCategory($roomCats[1]);
                $app->setDescription($this->translator->trans('category.double'));
            } else {
                $app->setBedsMax(1);
                $app->setRoomCategory($roomCats[0]);
                $app->setDescription($this->translator->trans('category.single'));
            }
            $this->syncService->initSync($app);
            $manager->persist($app);
        }
    }

    private function createPrices(ObjectManager $manager, $roomCats, $origins): void
    {
        $persons = 1;
        $amount = 30;
        foreach ($roomCats as $roomCat) {
            $price = new Price();
            $price->setActive(true);
            $price->setRoomCategory($roomCat);
            $price->setType(2);
            $price->setMinStay(1);
            $price->setAllDays(true);
            $price->setAllPeriods(true);
            $price->setNumberOfPersons($persons);
            $price->setVat(7);
            $price->setPrice($amount);
            $amount += 20;
            if (1 === $persons) {
                $price->setDescription($this->translator->trans('price.single'));
            } else {
                $price->setDescription($this->translator->trans('price.double'));
            }
            foreach ($origins as $origin) {
                $price->addReservationOrigin($origin);
            }

            $manager->persist($price);
            ++$persons;
        }

        // breakfast
        $price = new Price();
        $price->setActive(true);
        $price->setType(1);
        $price->setAllDays(true);
        $price->setAllPeriods(true);
        $price->setVat(19);
        $price->setPrice(10);
        $price->setDescription($this->translator->trans('price.breakfast'));
        foreach ($origins as $origin) {
            $price->addReservationOrigin($origin);
        }

        $manager->persist($price);
    }

    private function createCustomer(ObjectManager $manager): void
    {
        $address = new CustomerAddresses();
        $address->setAddress('Musterstr. 1');
        $address->setCity('Musterhausen');
        $address->setCountry('DE');
        $address->setEmail('max.mustermann@muster.de');
        $address->setType('CUSTOMER_ADDRESS_TYPE_PRIVATE');
        $address->setFax('123456789');
        $address->setMobilePhone('0176123456');
        $address->setPhone('987654321');
        $address->setZip('12345');

        $manager->persist($address);

        $cus = new Customer();
        $cus->addCustomerAddress($address);
        $cus->setFirstname('Max');
        $cus->setLastname('Mustermann');
        $cus->setBirthday(new \DateTime('1987-12-01'));
        $cus->setSalutation('Herr');

        $manager->persist($cus);
    }

    private function createReservationStatus(ObjectManager $manager): void
    {
        $reservationStatus = [
                [
                    'name' => $this->translator->trans('status.confirmed'),
                    'color' => '#2D9434',
                    'contrast' => '#ffffff',
                ], [
                    'name' => $this->translator->trans('status.option'),
                    'color' => '#f6e95c',
                    'contrast' => '#000000',
                ],
            ];
        foreach ($reservationStatus as $status) {
            $rs = new ReservationStatus();
            $rs->setName($status['name']);
            $rs->setColor($status['color']);
            $rs->setContrastColor($status['contrast']);
            $manager->persist($rs);
        }
    }
}
