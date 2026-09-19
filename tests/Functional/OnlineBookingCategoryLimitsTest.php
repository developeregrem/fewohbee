<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\OnlineBookingRoomCategoryLimit;
use App\Entity\RoomCategory;
use App\Entity\User;
use App\Repository\OnlineBookingRoomCategoryLimitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * How many rooms of a category go online and from how many guests they are offered. These
 * limits are not day-dependent, so they stayed out of the booking rules — and had no
 * coverage until now.
 */
final class OnlineBookingCategoryLimitsTest extends WebTestCase
{
    public function testTheSettingsPageOffersBothLimitsForEveryCategory(): void
    {
        $client = $this->authenticatedClient();
        $categories = $this->em()->getRepository(RoomCategory::class)->findAll();
        self::assertNotEmpty($categories);

        $client->request('GET', '/settings/online-booking');

        self::assertResponseIsSuccessful();
        foreach ($categories as $category) {
            // The limits live in the booking rules tab, next to the other booking restrictions.
            self::assertSelectorExists(sprintf('#tab-booking-rules input[name="max_rooms_%d"]', $category->getId()));
            self::assertSelectorExists(sprintf('#tab-booking-rules input[name="min_occupancy_%d"]', $category->getId()));
        }
    }

    public function testLimitsAreStoredUpdatedAndClearedAgain(): void
    {
        $client = $this->authenticatedClient();
        $category = $this->em()->getRepository(RoomCategory::class)->findOneBy([]);
        $id = (int) $category->getId();

        $this->submitLimits($client, $id, '2', '3');
        self::assertResponseRedirects('/settings/online-booking?tab=tab-booking-rules#booking-rule-limits');
        $limit = $this->limits()->findOneBy(['roomCategory' => $id]);
        self::assertSame(2, $limit?->getMaxRooms());
        self::assertSame(3, $limit->getMinOccupancy());

        // Only one of the two set is valid: the other stays empty rather than defaulting.
        $this->submitLimits($client, $id, '5', '');
        $limit = $this->limits()->findOneBy(['roomCategory' => $id]);
        self::assertSame(5, $limit?->getMaxRooms());
        self::assertNull($limit->getMinOccupancy());

        // Clearing both fields removes the record, which is how "no limit" is expressed.
        $this->submitLimits($client, $id, '', '');
        self::assertNull($this->limits()->findOneBy(['roomCategory' => $id]));
    }

    public function testInvalidCsrfTokenChangesNothing(): void
    {
        $client = $this->authenticatedClient();
        $category = $this->em()->getRepository(RoomCategory::class)->findOneBy([]);
        $id = (int) $category->getId();

        $client->request('POST', '/settings/online-booking/restrictions/categories', [
            '_token' => 'invalid',
            'max_rooms_'.$id => '4',
        ]);

        self::assertResponseRedirects('/settings/online-booking?tab=tab-booking-rules');
        self::assertNull($this->limits()->findOneBy(['roomCategory' => $id]));
    }

    public function testAnonymousUserCannotChangeLimits(): void
    {
        $client = self::createClient();
        $client->request('POST', '/settings/online-booking/restrictions/categories');

        self::assertResponseRedirects();
    }

    /** Posts the category form the way the settings page renders it. */
    private function submitLimits(KernelBrowser $client, int $categoryId, string $maxRooms, string $minOccupancy): void
    {
        $crawler = $client->request('GET', '/settings/online-booking');
        $form = $crawler->filter('form[action="/settings/online-booking/restrictions/categories"]')->form();
        $form['max_rooms_'.$categoryId] = $maxRooms;
        $form['min_occupancy_'.$categoryId] = $minOccupancy;
        $client->submit($form);
    }

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $em = $this->em();
            if ($em->isOpen()) {
                foreach ($this->limits()->findAll() as $limit) {
                    $em->remove($limit);
                }
                $em->flush();
            }
        }
        parent::tearDown();
    }

    private function authenticatedClient(): KernelBrowser
    {
        $client = self::createClient();
        $client->loginUser($this->admin(), 'main');

        return $client;
    }

    private function admin(): User
    {
        return $this->em()->getRepository(User::class)->findOneBy(['username' => 'test-admin']) ?? throw new \RuntimeException('Prepared test admin missing.');
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function limits(): OnlineBookingRoomCategoryLimitRepository
    {
        return self::getContainer()->get(OnlineBookingRoomCategoryLimitRepository::class);
    }
}
