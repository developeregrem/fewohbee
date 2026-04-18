<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AccountingAccount;
use App\Entity\AccountingSettings;
use App\Entity\BookingBatch;
use App\Entity\BookingEntry;
use App\Entity\Role;
use App\Entity\TaxRate;
use App\Entity\User;
use App\Entity\Workflow;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class BookingJournalControllerTest extends WebTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    // ── Settings page ───────────────────────────────────────────────

    public function testSettingsPageIsAccessible(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCashJournalUser());

        $client->request('GET', '/journal/settings');

        self::assertResponseIsSuccessful();
    }

    public function testSettingsPageIsForbiddenWithoutRole(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles([]));

        $client->request('GET', '/journal/settings');

        self::assertResponseStatusCodeSame(403);
    }

    // ── Preset loading ──────────────────────────────────────────────

    public function testLoadPresetCreatesAccountsAndTaxRates(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCashJournalUser());

        // Clear any existing accounts and tax rates from previous test runs
        $this->getEntityManager()->createQuery('DELETE FROM App\Entity\TaxRate')->execute();
        $this->getEntityManager()->createQuery('DELETE FROM App\Entity\AccountingAccount')->execute();

        $client->request('POST', '/journal/settings/preset/load', [
            'preset' => AccountingSettings::PRESET_SKR03,
        ]);

        self::assertResponseRedirects('/journal/settings');

        $accounts = $this->getEntityManager()->getRepository(AccountingAccount::class)->findAll();
        $taxRates = $this->getEntityManager()->getRepository(TaxRate::class)->findAll();

        self::assertNotEmpty($accounts, 'Preset should create accounts');
        self::assertNotEmpty($taxRates, 'Preset should create tax rates');

        // Verify cash account exists
        $cashAccounts = array_filter($accounts, fn (AccountingAccount $a) => $a->isCashAccount());
        self::assertNotEmpty($cashAccounts, 'SKR03 must have a cash account');
    }

    public function testLoadPresetWithWorkflowsCreatesWorkflows(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCashJournalUser());

        // Clear existing preset workflows
        $this->getEntityManager()
            ->createQuery("DELETE FROM App\Entity\Workflow w WHERE w.systemCode LIKE 'preset_skr03_%'")
            ->execute();

        $client->request('POST', '/journal/settings/preset/load', [
            'preset' => AccountingSettings::PRESET_SKR03,
            'seedWorkflows' => '1',
        ]);

        self::assertResponseRedirects('/journal/settings');

        $workflows = $this->getEntityManager()->getRepository(Workflow::class)
            ->findBy(['systemCode' => [
                'preset_skr03_booking_cash',
                'preset_skr03_booking_transfer',
                'preset_skr03_booking_card',
            ]]);

        self::assertCount(3, $workflows, 'Loading preset with seedWorkflows should create 3 workflows');
    }

    public function testLoadPresetIsIdempotent(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCashJournalUser());

        // Load twice
        $client->request('POST', '/journal/settings/preset/load', [
            'preset' => AccountingSettings::PRESET_SKR03,
            'seedWorkflows' => '1',
        ]);
        $client->request('POST', '/journal/settings/preset/load', [
            'preset' => AccountingSettings::PRESET_SKR03,
            'seedWorkflows' => '1',
        ]);

        self::assertResponseRedirects('/journal/settings');

        // Count should not double
        $workflows = $this->getEntityManager()->getRepository(Workflow::class)
            ->findBy(['systemCode' => [
                'preset_skr03_booking_cash',
                'preset_skr03_booking_transfer',
                'preset_skr03_booking_card',
            ]]);

        self::assertCount(3, $workflows, 'Loading preset twice should still result in 3 workflows');
    }

    public function testLoadInvalidPresetShowsWarning(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCashJournalUser());

        $client->request('POST', '/journal/settings/preset/load', [
            'preset' => 'invalid_preset',
        ]);

        self::assertResponseRedirects('/journal/settings');
    }

    // ── Journal overview ────────────────────────────────────────────

    public function testJournalOverviewIsAccessible(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCashJournalUser());

        $client->request('GET', '/journal');

        self::assertResponseIsSuccessful();
    }

    // ── Batch CRUD ──────────────────────────────────────────────────

    public function testCreateBatchRedirectsToNewBatch(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCashJournalUser());

        // Remove existing batch for this test month to avoid conflicts
        $this->getEntityManager()
            ->createQuery('DELETE FROM App\Entity\BookingEntry e WHERE e.bookingBatch IN (SELECT b.id FROM App\Entity\BookingBatch b WHERE b.year = 2099 AND b.month = 1)')
            ->execute();
        $this->getEntityManager()
            ->createQuery('DELETE FROM App\Entity\BookingBatch b WHERE b.year = 2099 AND b.month = 1')
            ->execute();

        $client->request('GET', '/journal/batch/new');
        self::assertResponseIsSuccessful();

        $client->submitForm('btn-save', [
            'booking_batch[year]' => 2099,
            'booking_batch[month]' => 1,
            'booking_batch[cashStart]' => '0',
        ]);

        // Should redirect to the new batch entries page
        self::assertResponseRedirects();
        $location = $client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/journal/batch/\d+#', $location);
    }

    // ── Closed batch protection ─────────────────────────────────────

    public function testClosedBatchRejectsNewEntries(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCashJournalUser());

        // Create a closed batch
        $batch = new BookingBatch();
        $batch->setYear(2098);
        $batch->setMonth(12);
        $batch->setIsClosed(true);
        $batch->setCashStart(0);
        $batch->setCashEnd(0);

        $em = $this->getEntityManager();
        $em->persist($batch);
        $em->flush();

        $client->request('POST', '/journal/entry/create', [
            'booking_entry' => [
                'date' => '2098-12-15',
                'documentNumber' => 1,
                'amount' => '100',
                'remark' => 'test',
            ],
            'batchId' => $batch->getId(),
        ]);

        // Should redirect with warning, not create entry
        self::assertResponseRedirects();
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function getEntityManager(): EntityManagerInterface
    {
        if (!isset($this->em)) {
            $this->em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        }

        return $this->em;
    }

    private function createCashJournalUser(): User
    {
        return $this->createUserWithRoles(['ROLE_CASHJOURNAL']);
    }

    private function createUserWithRoles(array $roleCodes): User
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();
        $roleRepository = $em->getRepository(Role::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setUsername('test_'.bin2hex(random_bytes(6)));
        $user->setFirstname('Test');
        $user->setLastname('User');
        $user->setEmail(sprintf('test+%s@example.com', bin2hex(random_bytes(4))));
        $user->setActive(true);
        $user->setPassword($passwordHasher->hashPassword($user, 'ChangeMe123!'));

        $roles = [];
        foreach ($roleCodes as $roleCode) {
            $role = $roleRepository->findOneBy(['role' => $roleCode]);
            if (null !== $role) {
                $roles[] = $role;
            }
        }

        $user->setRoleEntities($roles);

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
