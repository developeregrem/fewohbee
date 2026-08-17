<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Invoice;
use App\Entity\Role;
use App\Entity\Subsidiary;
use App\Entity\User;
use App\Service\AppSettingsService;
use App\Service\InvoiceNumberGenerator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class InvoiceNumberRangeTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testBranchRangesRunIndependentlyOfEachOther(): void
    {
        static::createClient();
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $generator = static::getContainer()->get(InvoiceNumberGenerator::class);

        $this->setGlobalPattern('RE-<year>-<number:4>');
        $nord = $this->createSubsidiary('Nord', 'NORD-<year>-<number:4>');
        $sued = $this->createSubsidiary('Sued', 'SUED-<year>-<number:4>');
        $date = new \DateTimeImmutable('2026-08-17');

        self::assertSame('NORD-2026-0001', $generator->generateNext($nord, $date));
        self::assertSame('SUED-2026-0001', $generator->generateNext($sued, $date));

        // Issuing one number in the northern range must not move the southern one.
        $em->persist($this->buildInvoice('NORD-2026-0001', $date, $nord));
        $em->flush();

        self::assertSame('NORD-2026-0002', $generator->generateNext($nord, $date));
        self::assertSame('SUED-2026-0001', $generator->generateNext($sued, $date));
    }

    public function testInvoiceWithoutBranchUsesTheGlobalRange(): void
    {
        static::createClient();
        $generator = static::getContainer()->get(InvoiceNumberGenerator::class);

        $this->setGlobalPattern('GLOB-<year>-<number:4>');

        self::assertSame('GLOB-2026-0001', $generator->generateNext(null, new \DateTimeImmutable('2026-08-17')));
    }

    public function testBranchWithoutOwnPatternFallsBackToTheGlobalRange(): void
    {
        static::createClient();
        $generator = static::getContainer()->get(InvoiceNumberGenerator::class);

        $this->setGlobalPattern('FALL-<year>-<number:4>');
        $branch = $this->createSubsidiary('Ohne Muster', null);

        self::assertSame('FALL-2026-0001', $generator->generateNext($branch, new \DateTimeImmutable('2026-08-17')));
    }

    public function testYearChangeRestartsTheSequence(): void
    {
        static::createClient();
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $generator = static::getContainer()->get(InvoiceNumberGenerator::class);

        $this->setGlobalPattern('YEAR-<year>-<number:4>');
        $em->persist($this->buildInvoice('YEAR-2026-0042', new \DateTimeImmutable('2026-12-31'), null));
        $em->flush();

        self::assertSame('YEAR-2026-0043', $generator->generateNext(null, new \DateTimeImmutable('2026-12-31')));
        self::assertSame('YEAR-2027-0001', $generator->generateNext(null, new \DateTimeImmutable('2027-01-01')));
    }

    public function testNumberRangeIsNotConfiguredWithoutAPattern(): void
    {
        static::createClient();
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $generator = static::getContainer()->get(InvoiceNumberGenerator::class);

        // "Not configured" means neither globally nor on any branch — other tests in this
        // suite leave branch patterns behind, so clear them here.
        $this->setGlobalPattern(null);
        foreach ($em->getRepository(Subsidiary::class)->findAll() as $subsidiary) {
            $subsidiary->setInvoiceNumberPattern(null);
        }
        $em->flush();

        self::assertFalse($generator->hasConfiguredPattern());
        self::assertNull($generator->generateNext(null, new \DateTimeImmutable('2026-08-17')));
    }

    public function testRenumberingToAnExistingNumberIsRejected(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();

        $taken = $this->buildInvoice('DUP-2026-0001', new \DateTimeImmutable('2026-08-17'), null);
        $target = $this->buildInvoice('DUP-2026-0002', new \DateTimeImmutable('2026-08-17'), null);
        $em->persist($taken);
        $em->persist($target);
        $em->flush();
        $targetId = $target->getId();

        $crawler = $client->request('GET', '/invoices/'.$targetId.'/edit/number/show');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/invoices/edit/number/save', [
            'invoice-id' => $targetId,
            'number' => 'DUP-2026-0001',
            'date' => '2026-08-17',
            '_csrf_token' => $token,
        ]);
        self::assertResponseIsSuccessful();

        $em->clear();
        self::assertSame(
            'DUP-2026-0002',
            $em->getRepository(Invoice::class)->find($targetId)->getNumber(),
            'A duplicate number must be rejected and nothing written'
        );
    }

    public function testChangeNumberFormIsRedisplayedWhenTheNumberIsEmpty(): void
    {
        // Regression: this path used to call showChangeNumberInvoiceEditAction() with a
        // single argument and died with an ArgumentCountError.
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();

        $invoice = $this->buildInvoice('EMPTY-2026-0001', new \DateTimeImmutable('2026-08-17'), null);
        $em->persist($invoice);
        $em->flush();
        $invoiceId = $invoice->getId();

        $crawler = $client->request('GET', '/invoices/'.$invoiceId.'/edit/number/show');
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/invoices/edit/number/save', [
            'invoice-id' => $invoiceId,
            'number' => '',
            'date' => '2026-08-17',
            '_csrf_token' => $token,
        ]);

        self::assertResponseIsSuccessful();
        $em->clear();
        self::assertSame('EMPTY-2026-0001', $em->getRepository(Invoice::class)->find($invoiceId)->getNumber());
    }

    public function testBankImportSettingsListsTheConfiguredRanges(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser(['ROLE_INVOICES', 'ROLE_CASHJOURNAL']));

        $this->setGlobalPattern('RE-<year>-<number:4>');
        $this->createSubsidiary('Nordfiliale', 'NORD-<year>-<number:4>');

        $crawler = $client->request('GET', '/journal/bank-import/settings?tab=tab-invoice-matching');
        self::assertResponseIsSuccessful();

        $text = $crawler->filter('#tab-invoice-matching')->text();
        self::assertStringContainsString('RE-<year>-<number:4>', $text);
        self::assertStringContainsString('NORD-<year>-<number:4>', $text);
        self::assertStringContainsString('Nordfiliale', $text);
    }

    public function testSettingsOfferRecentInvoiceNumbersWhenNoRangeIsConfigured(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser(['ROLE_INVOICES', 'ROLE_CASHJOURNAL']));
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();

        $this->setGlobalPattern(null);
        foreach ($em->getRepository(Subsidiary::class)->findAll() as $subsidiary) {
            $subsidiary->setInvoiceNumberPattern(null);
        }
        $em->persist($this->buildInvoice('HINT-2026-0007', new \DateTimeImmutable('2026-08-17'), null));
        $em->flush();

        $crawler = $client->request('GET', '/journal/bank-import/settings?tab=tab-invoice-matching');
        self::assertResponseIsSuccessful();

        // Migration output is invisible on unattended updates, so the screen has to offer
        // the orientation itself.
        $text = $crawler->filter('#tab-invoice-matching')->text();
        self::assertStringContainsString('HINT-2026-0007', $text);
    }

    // ── Test helpers ─────────────────────────────────────────────────

    private function setGlobalPattern(?string $pattern): void
    {
        $service = static::getContainer()->get(AppSettingsService::class);
        $settings = $service->getSettings();
        $settings->setInvoiceNumberPattern($pattern);
        $service->saveSettings($settings);
    }

    private function createSubsidiary(string $name, ?string $pattern): Subsidiary
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();

        $subsidiary = new Subsidiary();
        $subsidiary->setName($name.'-'.bin2hex(random_bytes(3)));
        $subsidiary->setDescription('Testniederlassung');
        $subsidiary->setInvoiceNumberPattern($pattern);
        $em->persist($subsidiary);
        $em->flush();

        return $subsidiary;
    }

    private function buildInvoice(string $number, \DateTimeInterface $date, ?Subsidiary $subsidiary): Invoice
    {
        $invoice = new Invoice();
        $invoice->setNumber($number);
        $invoice->setDate(\DateTime::createFromInterface($date));
        $invoice->setStatus(1);
        $invoice->setSubsidiary($subsidiary);

        return $invoice;
    }

    /**
     * @param list<string> $roleCodes
     */
    private function createInvoiceUser(array $roleCodes = ['ROLE_INVOICES']): User
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
