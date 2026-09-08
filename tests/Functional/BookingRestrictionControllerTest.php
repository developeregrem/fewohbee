<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\BookingRestrictionRule;
use App\Entity\Enum\BookingRestrictionType as Type;
use App\Entity\Role;
use App\Entity\RoomCategory;
use App\Entity\User;
use App\Repository\BookingRestrictionRuleRepository;
use App\Service\BookingRestrictionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** HTTP, authorization and persistence coverage for the booking rule offcanvas and effect table. */
final class BookingRestrictionControllerTest extends WebTestCase
{
    private const XHR = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];

    public function testAnonymousUserCannotReachTheRuleEditor(): void
    {
        $client = self::createClient();
        $client->request('GET', '/settings/online-booking/rules/new');
        self::assertResponseRedirects();
        $client->request('GET', '/settings/online-booking/rules/matrix');
        self::assertResponseRedirects();
    }

    public function testNonAdminCannotManageRules(): void
    {
        $client = self::createClient();
        $user = new User();
        $user->setUsername('booking-rule-readonly')->setFirstname('Rule')->setLastname('Reader')
            ->setEmail('rule-reader@example.com')->setPassword('not-used-for-login')->setActive(true);
        $user->setRole($this->em()->getRepository(Role::class)->findOneBy(['role' => 'ROLE_RESERVATIONS_RO']));
        $this->em()->persist($user);
        $this->em()->flush();

        $client->loginUser($user, 'main');
        $client->request('GET', '/settings/online-booking/rules/new');
        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateNightRuleForSelectedCategoriesAndEnforceItInTheResolver(): void
    {
        $client = $this->authenticatedClient();
        $categoryIds = array_map(
            static fn (RoomCategory $category): int => (int) $category->getId(),
            $this->em()->getRepository(RoomCategory::class)->findBy([], limit: 2),
        );
        self::assertCount(2, $categoryIds);

        $crawler = $client->request('GET', '/settings/online-booking/rules/new', server: self::XHR);
        self::assertResponseIsSuccessful();
        // The offcanvas offers all four restriction types and seven relabelling day toggles.
        self::assertSelectorCount(4, 'input[name="booking_restriction_rule[type]"]');
        self::assertSelectorCount(7, 'label[data-booking-rules-target="dayLabel"]');

        $input = $crawler->filter('form[name="booking_restriction_rule"]')->form()->getPhpValues();
        $input['booking_restriction_rule']['type'] = Type::MIN_STAY_THROUGH->value;
        $input['booking_restriction_rule']['weekdays'] = ['1', '2', '3', '4'];
        $input['booking_restriction_rule']['minNights'] = '4';
        // An unchecked checkbox is absent from the request, not present with a false value.
        unset($input['booking_restriction_rule']['allCategories']);
        $input['booking_restriction_rule']['categories'] = array_map(strval(...), $categoryIds);
        $client->request('POST', '/settings/online-booking/rules/new', $input, server: self::XHR);
        self::assertResponseStatusCodeSame(204);

        $rule = $this->repository()->findOneBy(['type' => Type::MIN_STAY_THROUGH]);
        self::assertNotNull($rule);
        self::assertCount(2, $rule->getCategories());
        self::assertSame([1, 2, 3, 4], $rule->getWeekdays());
        self::assertFalse($rule->isPeriod());

        // Issue #286: Sunday to Tuesday occupies a Monday night, so two nights are too short.
        $category = $this->em()->getRepository(RoomCategory::class)->find($categoryIds[0]);
        $restrictions = self::getContainer()->get(BookingRestrictionService::class);
        self::assertFalse($restrictions->checkStay($category, new \DateTimeImmutable('2026-09-13'), new \DateTimeImmutable('2026-09-15'))->isAllowed());
        self::assertTrue($restrictions->checkStay($category, new \DateTimeImmutable('2026-09-13'), new \DateTimeImmutable('2026-09-17'))->isAllowed());
    }

    public function testSpecialPeriodStoresAnExclusiveEndDateFromTheInclusiveInput(): void
    {
        $client = $this->authenticatedClient();
        $crawler = $client->request('GET', '/settings/online-booking/periods/new', server: self::XHR);
        self::assertResponseIsSuccessful();

        $input = $crawler->filter('form[name="booking_restriction_rule"]')->form()->getPhpValues();
        $input['booking_restriction_rule']['minNights'] = '5';
        $input['booking_restriction_rule']['startDate'] = '2026-12-24';
        $input['booking_restriction_rule']['lastDate'] = '2026-12-26';
        $client->request('POST', '/settings/online-booking/periods/new', $input, server: self::XHR);
        self::assertResponseStatusCodeSame(204);

        $rule = $this->repository()->findOneBy([]);
        self::assertTrue($rule?->isPeriod());
        self::assertSame('2026-12-24', $rule->getStartDate()?->format('Y-m-d'));
        // The operator entered the 26th as the last covered day; storage is half-open.
        self::assertSame('2026-12-27', $rule->getEndDate()?->format('Y-m-d'));
    }

    public function testInvalidInputAndInvalidCsrfAreRejectedWithoutPersisting(): void
    {
        $client = $this->authenticatedClient();
        $crawler = $client->request('GET', '/settings/online-booking/rules/new', server: self::XHR);
        $input = $crawler->filter('form[name="booking_restriction_rule"]')->form()->getPhpValues();

        $withoutDays = $input;
        $withoutDays['booking_restriction_rule']['weekdays'] = [];
        $client->request('POST', '/settings/online-booking/rules/new', $withoutDays, server: self::XHR);
        self::assertResponseStatusCodeSame(422);

        $badToken = $input;
        $badToken['booking_restriction_rule']['_token'] = 'invalid';
        $client->request('POST', '/settings/online-booking/rules/new', $badToken, server: self::XHR);
        self::assertResponseStatusCodeSame(422);

        self::assertSame(0, $this->repository()->count([]));
    }

    public function testClosureRuleNeedsNoNightCount(): void
    {
        $client = $this->authenticatedClient();
        $crawler = $client->request('GET', '/settings/online-booking/rules/new', server: self::XHR);
        $input = $crawler->filter('form[name="booking_restriction_rule"]')->form()->getPhpValues();
        $input['booking_restriction_rule']['type'] = Type::CLOSED_TO_ARRIVAL->value;
        $input['booking_restriction_rule']['weekdays'] = ['7'];
        $input['booking_restriction_rule']['minNights'] = '';
        $client->request('POST', '/settings/online-booking/rules/new', $input, server: self::XHR);
        self::assertResponseStatusCodeSame(204);

        $rule = $this->repository()->findOneBy([]);
        self::assertSame(Type::CLOSED_TO_ARRIVAL, $rule?->getType());
        self::assertNull($rule->getMinNights());
    }

    public function testEffectTableRendersForACategoryAndRejectsAnUnknownOne(): void
    {
        $client = $this->authenticatedClient();
        $category = $this->em()->getRepository(RoomCategory::class)->findOneBy([]);

        $client->request('GET', '/settings/online-booking/rules/matrix', [
            'category' => (string) $category->getId(),
            'week' => '2026-09-14',
        ], server: self::XHR);
        self::assertResponseIsSuccessful();
        // Seven arrival days, each with one cell per stay length.
        self::assertSelectorCount(7, 'tbody tr');
        self::assertSelectorCount(7 * BookingRestrictionService::MATRIX_NIGHTS, 'tbody td');

        $client->request('GET', '/settings/online-booking/rules/matrix', ['category' => '2147483647'], server: self::XHR);
        self::assertResponseStatusCodeSame(404);
    }

    public function testExpiredSpecialPeriodsAreFlaggedOnTheSettingsPage(): void
    {
        $client = $this->authenticatedClient();

        // A period that ended yesterday no longer restricts anything, which is easy to miss.
        $expired = $this->periodRule('-1 month', 'yesterday');
        $this->em()->persist($expired);
        $this->em()->flush();

        $client->request('GET', '/settings/online-booking');
        self::assertSelectorExists('a[href="#booking-rule-periods"]');

        // One period still to come is enough: nothing has silently stopped applying.
        $upcoming = $this->periodRule('tomorrow', '+1 month');
        $this->em()->persist($upcoming);
        $this->em()->flush();

        $client->request('GET', '/settings/online-booking');
        self::assertSelectorNotExists('a[href="#booking-rule-periods"]');

        // Each request reboots the kernel, so re-fetch through the current entity manager.
        // A disabled period was parked deliberately and must not silence the warning.
        $reloaded = $this->repository()->find($upcoming->getId());
        $reloaded->setEnabled(false);
        $this->em()->flush();

        $client->request('GET', '/settings/online-booking');
        self::assertSelectorExists('a[href="#booking-rule-periods"]');
    }

    public function testUnlimitedRulesNeverTriggerTheExpiredPeriodWarning(): void
    {
        $client = $this->authenticatedClient();
        $this->em()->persist(new BookingRestrictionRule());
        $this->em()->flush();

        $client->request('GET', '/settings/online-booking');
        self::assertSelectorNotExists('a[href="#booking-rule-periods"]');
    }

    public function testToggleAndDeleteRequireCsrfAndUnknownRuleIsNotFound(): void
    {
        $client = $this->authenticatedClient();
        $rule = new BookingRestrictionRule();
        $this->em()->persist($rule);
        $this->em()->flush();
        $id = $rule->getId();

        $client->request('POST', '/settings/online-booking/rules/'.$id.'/toggle', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $client->request('DELETE', '/settings/online-booking/rules/'.$id, ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->repository()->find($id)?->isEnabled());

        $crawler = $client->request('GET', '/settings/online-booking');
        $toggle = $crawler->filter('form[action="/settings/online-booking/rules/'.$id.'/toggle"]')->form();
        $client->submit($toggle);
        self::assertResponseRedirects('/settings/online-booking');
        self::assertFalse($this->repository()->find($id)?->isEnabled());

        // The list toggles over ajax and updates the row itself, so no redirect is sent.
        $client->request('POST', '/settings/online-booking/rules/'.$id.'/toggle', $toggle->getPhpValues(), server: self::XHR);
        self::assertResponseStatusCodeSame(204);
        self::assertTrue($this->repository()->find($id)?->isEnabled());

        $client->request('GET', '/settings/online-booking/rules/2147483647/edit');
        self::assertResponseStatusCodeSame(404);
    }

    /** A dated rule whose inclusive last day is $lastDay; storage keeps the end exclusive. */
    private function periodRule(string $firstDay, string $lastDay): BookingRestrictionRule
    {
        $rule = new BookingRestrictionRule();
        $rule->setPeriod(
            new \DateTimeImmutable($firstDay),
            (new \DateTimeImmutable($lastDay))->modify('+1 day'),
        );

        return $rule;
    }

    /** Removes only rule fixtures from the prepared test database between examples. */
    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $em = $this->em();
            if ($em->isOpen()) {
                foreach ($this->repository()->findAll() as $rule) {
                    $em->remove($rule);
                }
                $reader = $em->getRepository(User::class)->findOneBy(['username' => 'booking-rule-readonly']);
                if (null !== $reader) {
                    $em->remove($reader);
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

    private function repository(): BookingRestrictionRuleRepository
    {
        return self::getContainer()->get(BookingRestrictionRuleRepository::class);
    }
}
