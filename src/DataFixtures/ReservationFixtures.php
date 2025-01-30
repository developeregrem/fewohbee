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
use App\Entity\Invoice;
use App\Entity\InvoiceAppartment;
use App\Entity\InvoicePosition;
use App\Entity\Price;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\ReservationStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

class ReservationFixtures extends Fixture implements FixtureGroupInterface, DependentFixtureInterface
{
    private $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    public static function getGroups(): array
    {
        return ['reservation', 'invoices'];
    }

    public function getDependencies(): array
    {
        return [
            SettingsFixtures::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        $apps = $manager->getRepository(Appartment::class)->findAll();
        $origins = $manager->getRepository(ReservationOrigin::class)->findAll();
        $customer = $manager->getRepository(Customer::class)->findOneBy(['lastname' => 'Mustermann']);
        $status = $manager->getRepository(ReservationStatus::class)->find(1);

        if (!($customer instanceof Customer)) {
            return;
        }
        /* @var $app Appartment */
        foreach ($apps as $app) {
            $start = new \DateTime();

            $intvall1 = new \DateInterval('P'.random_int(1, 10).'D');
            $intvall2 = new \DateInterval('P'.random_int(0, 5).'D');
            $start->add($intvall2);
            $end = clone $start;
            $end->add($intvall1);

            $res = new Reservation();
            $res->setReservationOrigin($origins[0]);
            $res->setBooker($customer);
            $res->addCustomer($customer);
            $res->setPersons($app->getBedsMax());
            $res->setReservationStatus($status);
            $res->setStartDate($start);
            $res->setEndDate($end);
            $res->setAppartment($app);
            $res->setReservationDate(new \DateTime());
            $res->setUuid(Uuid::v4());

            $manager->persist($res);
        }

        $manager->flush();

        $this->createInvoices($manager);

        $manager->flush();
    }

    private function createInvoices(ObjectManager $manager): void
    {
        $res = $manager->getRepository(Reservation::class)->findAll();
        /* @var $bPrice Price */
        $bPrice = $manager->getRepository(Price::class)->findOneBy(['type' => 1]);
        $invoiceId = 100;
        /* @var $re Reservation */
        foreach ($res as $re) {
            /* @var $aPrice Price */
            $aPrice = $manager->getRepository(Price::class)->findOneBy(['type' => 2, 'numberOfPersons' => $re->getPersons()]);

            $interval = $re->getStartDate()->diff($re->getEndDate());
            $days = $interval->format('%a');

            $miscPos = new InvoicePosition();
            $appPos = new InvoiceAppartment();
            $invoice = new Invoice();

            $miscPos->setVat($bPrice->getVat());
            $miscPos->setPrice($bPrice->getPrice());
            $miscPos->setAmount($days * $re->getPersons());
            $miscPos->setDescription($bPrice->getDescription());

            $manager->persist($miscPos);

            $appPos->setBeds($re->getPersons());
            $appPos->setDescription($aPrice->getDescription());
            $appPos->setStartDate($re->getStartDate());
            $appPos->setEndDate($re->getEndDate());
            $appPos->setVat($aPrice->getVat());
            $appPos->setPrice($aPrice->getPrice());
            $appPos->setPersons($re->getPersons());
            $appPos->setNumber($re->getAppartment()->getNumber());

            $manager->persist($appPos);

            /* @var $address CustomerAddresses */
            $address = $re->getBooker()->getCustomerAddresses()[0];
            $invoice->setAddress($address->getAddress());
            $invoice->setCity($address->getCity());
            $invoice->setDate(new \DateTime());
            $invoice->setFirstname($re->getBooker()->getFirstname());
            $invoice->setLastname($re->getBooker()->getLastname());
            $invoice->setNumber($invoiceId++);
            $invoice->setSalutation($re->getBooker()->getSalutation());
            $invoice->setZip($address->getZip());
            $invoice->setStatus(1);

            $manager->persist($invoice);

            $miscPos->setInvoice($invoice);
            $appPos->setInvoice($invoice);
            $re->addInvoice($invoice);

            $manager->persist($re);
            $manager->persist($appPos);
            $manager->persist($miscPos);
        }
    }
}
