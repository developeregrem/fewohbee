<?php
/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\Persistence\ObjectManager;
use Symfony\Contracts\Translation\TranslatorInterface;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;

use App\Entity\RoomCategory;
use App\Entity\ReservationOrigin;
use App\Entity\Appartment;
use App\Entity\Subsidiary;
use App\Entity\Price;
use App\Entity\Customer;
use App\Entity\CustomerAddresses;

class SettingsFixtures extends Fixture implements FixtureGroupInterface
{
    private $translator;
    public function __construct(TranslatorInterface $translator) {
        $this->translator = $translator;
    }
    
    public static function getGroups(): array
    {
        return ['settings', 'customer'];
    }
    
    public function load(ObjectManager $manager)
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
        
        $manager->flush();
    }
    
    private function createRoomCategories(ObjectManager $manager) {
        $roomCats = [
                $this->translator->trans('category.single'), 
                $this->translator->trans('category.double')
            ];
        foreach($roomCats as $roomCat) {
            $cat = new RoomCategory();
            $cat->setName( $roomCat );
            $manager->persist($cat);
        }
    }
    
    private function createOrigins(ObjectManager $manager) {
        $origins = [
                $this->translator->trans('reservationorigin.private'), 
                $this->translator->trans('reservationorigin.business')
            ];
        foreach($origins as $origin) {
            $o = new ReservationOrigin();
            $o->setName( $origin );
            $manager->persist($o);
        }
    }
    
    private function createRooms(ObjectManager $manager, $roomCats, Subsidiary $subsidiary) {
        // create 10 room, 5 single, 5 double
        for($i = 1; $i <= 10; $i++) {
            $app = new Appartment();
            $app->setNumber($i);
            $app->setObject($subsidiary);
            if($i > 5) {
                $app->setBedsMax(2);
                $app->setRoomCategory($roomCats[1]);
                $app->setDescription($this->translator->trans('category.double'));
            } else {
                $app->setBedsMax(1);
                $app->setRoomCategory($roomCats[0]);
                $app->setDescription($this->translator->trans('category.single'));
            }
            $manager->persist($app);
        }
    }
    
    private function createPrices(ObjectManager $manager, $roomCats, $origins) {
        $persons = 1;
        $amount = 30;
        foreach($roomCats as $roomCat) {
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
            if($persons === 1) {
                $price->setDescription( $this->translator->trans('price.single') );
            } else {
                $price->setDescription( $this->translator->trans('price.double') );
            }
            foreach($origins as $origin) {
                $price->addReservationOrigin($origin);
            }
            
            $manager->persist($price);
            $persons++;
        }
        
        // breakfast
        $price = new Price();
        $price->setActive(true);
        $price->setType(1);
        $price->setAllDays(true);
        $price->setAllPeriods(true);
        $price->setVat(19);
        $price->setPrice(10);
        $price->setDescription( $this->translator->trans('price.breakfast') );
        foreach($origins as $origin) {
            $price->addReservationOrigin($origin);
        }
        
        $manager->persist($price);
    }
    
    private function createCustomer(ObjectManager $manager) {
        $address = new CustomerAddresses();
        $address->setAddress("Musterstr. 1");
        $address->setCity("Musterhausen");
        $address->setCountry("DE");
        $address->setEmail("max.mustermann@muster.de");
        $address->setType('CUSTOMER_ADDRESS_TYPE_PRIVATE');
        $address->setFax("123456789");
        $address->setMobilePhone("0176123456");
        $address->setPhone("987654321");
        $address->setZip("12345");
        
        $manager->persist($address);
        
        $cus = new Customer();
        $cus->addCustomerAddress($address);
        $cus->setFirstname("Max");
        $cus->setLastname("Mustermann");
        $cus->setBirthday(new \DateTime("1987-12-01"));
        $cus->setSalutation("Herr");
        
        $manager->persist($cus);        
    }
}
