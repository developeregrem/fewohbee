<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Invoice;
use App\Entity\InvoiceSettingsData;
use App\Entity\Role;
use App\Entity\Subsidiary;
use App\Entity\User;
use App\Service\EInvoice\EInvoiceReadinessService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Covers what the scope selector shows and what the issuer resolution does with it.
 */
final class InvoiceSettingsScopeTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testParkedRecordPreselectsNotInUse(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $em = $this->clearSettings();

        // No branch, not the default -> parked. The dropdown must say so.
        $parked = $this->buildSetting(false);
        $em->persist($parked);
        $em->flush();

        $crawler = $client->request('GET', '/invoices/settings');
        self::assertResponseIsSuccessful();

        $selected = $crawler->filter('form#sttings-form-1 select[name="invoice_settings[scope]"] option[selected]');
        self::assertCount(1, $selected, 'Exactly one option must be preselected');
        self::assertSame('unused', $selected->attr('value'));
    }

    public function testDefaultRecordPreselectsDefault(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $em = $this->clearSettings();

        $em->persist($this->buildSetting(true));
        $em->flush();

        $crawler = $client->request('GET', '/invoices/settings');
        $selected = $crawler->filter('form#sttings-form-1 select[name="invoice_settings[scope]"] option[selected]');

        self::assertSame('default', $selected->attr('value'));
    }

    public function testBranchRecordPreselectsItsBranch(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $em = $this->clearSettings();

        $branch = $this->createSubsidiary('Nord');
        $setting = $this->buildSetting(false);
        $setting->setSubsidiary($branch);
        $em->persist($setting);
        $em->flush();

        $crawler = $client->request('GET', '/invoices/settings');
        $selected = $crawler->filter('form#sttings-form-1 select[name="invoice_settings[scope]"] option[selected]');

        self::assertSame('subsidiary:'.$branch->getId(), $selected->attr('value'));
    }

    public function testBranchWithoutOwnRecordDoesNotBorrowAnotherBranchsRecord(): void
    {
        static::createClient();
        $em = $this->clearSettings();
        $readiness = static::getContainer()->get(EInvoiceReadinessService::class);

        $nord = $this->createSubsidiary('Nord');
        $sued = $this->createSubsidiary('Sued');

        // Only Nord has issuer data, and it is not the default.
        $nordSettings = $this->buildSetting(false);
        $nordSettings->setSubsidiary($nord);
        $em->persist($nordSettings);
        $em->flush();

        $nordInvoice = $this->buildInvoice($nord);
        $suedInvoice = $this->buildInvoice($sued);

        self::assertSame(
            $nordSettings->getId(),
            $readiness->resolveSettingsFor($nordInvoice)?->getId(),
            'Nord uses its own record'
        );
        self::assertNull(
            $readiness->resolveSettingsFor($suedInvoice),
            'Sued must not fall back to another branch\'s record'
        );
    }

    public function testBranchWithoutOwnRecordUsesTheDefaultRecord(): void
    {
        static::createClient();
        $em = $this->clearSettings();
        $readiness = static::getContainer()->get(EInvoiceReadinessService::class);

        $nord = $this->createSubsidiary('Nord');
        $sued = $this->createSubsidiary('Sued');

        $nordSettings = $this->buildSetting(false);
        $nordSettings->setSubsidiary($nord);
        $default = $this->buildSetting(true);
        $em->persist($nordSettings);
        $em->persist($default);
        $em->flush();

        self::assertSame(
            $default->getId(),
            $readiness->resolveSettingsFor($this->buildInvoice($sued))?->getId(),
            'A branch without its own record uses the default, never a sibling branch'
        );
    }

    // ── Test helpers ─────────────────────────────────────────────────

    private function clearSettings(): \Doctrine\ORM\EntityManagerInterface
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        foreach ($em->getRepository(InvoiceSettingsData::class)->findAll() as $existing) {
            $em->remove($existing);
        }
        $em->flush();

        return $em;
    }

    private function createSubsidiary(string $name): Subsidiary
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();

        $subsidiary = new Subsidiary();
        $subsidiary->setName($name.'-'.bin2hex(random_bytes(3)));
        $subsidiary->setDescription('Testniederlassung');
        $em->persist($subsidiary);
        $em->flush();

        return $subsidiary;
    }

    private function buildInvoice(Subsidiary $subsidiary): Invoice
    {
        $invoice = new Invoice();
        $invoice->setNumber('T-'.bin2hex(random_bytes(3)));
        $invoice->setDate(new \DateTime('2026-08-17'));
        $invoice->setStatus(1);
        $invoice->setSubsidiary($subsidiary);

        return $invoice;
    }

    private function buildSetting(bool $isDefault): InvoiceSettingsData
    {
        $settings = new InvoiceSettingsData();
        $settings->setCompanyName('Hotel Test');
        $settings->setVatID('DE123456789');
        $settings->setContactName('Max Mustermann');
        $settings->setContactPhone('+49 30 123456');
        $settings->setContactMail('kontakt@example.com');
        $settings->setCompanyInvoiceMail('rechnung@example.com');
        $settings->setCompanyAddress('Musterweg 1');
        $settings->setCompanyPostCode('12345');
        $settings->setCompanyCity('Musterstadt');
        $settings->setCompanyCountry('DE');
        $settings->setAccountIBAN('DE44120300001089790461');
        $settings->setAccountName('Hotel Test');
        $settings->setAccountBIC('BYLADEM1001');
        $settings->setPaymentDueDays(14);
        $settings->setEinvoiceProfile('en16931');
        $settings->setIsActive($isDefault);

        return $settings;
    }

    private function createInvoiceUser(): User
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

        $role = $roleRepository->findOneBy(['role' => 'ROLE_INVOICES']);
        $user->setRoleEntities(null !== $role ? [$role] : []);

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
