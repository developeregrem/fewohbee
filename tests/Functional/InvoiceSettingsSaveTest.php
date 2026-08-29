<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\InvoiceSettingsData;
use App\Entity\Role;
use App\Entity\Subsidiary;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class InvoiceSettingsSaveTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testEditingInactiveSettingSavesProfileChange(): void
    {
        $client = static::createClient();
        $user = $this->createInvoiceUser();
        $client->loginUser($user);

        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();

        // wipe existing settings so the accordion has a deterministic content
        foreach ($em->getRepository(InvoiceSettingsData::class)->findAll() as $existing) {
            $em->remove($existing);
        }
        $em->flush();

        $setting = $this->buildSetting('en16931', false);
        $em->persist($setting);
        $em->flush();
        $settingId = $setting->getId();

        $crawler = $client->request('GET', '/invoices/settings');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form#sttings-form-1')->form();
        $form['invoice_settings[einvoiceProfile]'] = 'xrechnung';
        // xrechnung needs a full contact, otherwise validation would reject it
        $form['invoice_settings[contactName]'] = 'Max Mustermann';
        $form['invoice_settings[contactPhone]'] = '+49 30 123456';
        $form['invoice_settings[contactMail]'] = 'kontakt@example.com';
        $client->submit($form);

        self::assertResponseIsSuccessful();

        $em->clear();
        $reloaded = $em->getRepository(InvoiceSettingsData::class)->find($settingId);
        self::assertSame('xrechnung', $reloaded->getEinvoiceProfile(), 'Profile change on an inactive setting must persist');
    }

    public function testSavingWithoutVatIdIsRejected(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());

        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        foreach ($em->getRepository(InvoiceSettingsData::class)->findAll() as $existing) {
            $em->remove($existing);
        }
        $em->flush();

        $setting = $this->buildSetting('en16931', false);
        $em->persist($setting);
        $em->flush();
        $settingId = $setting->getId();

        $crawler = $client->request('GET', '/invoices/settings');
        self::assertResponseIsSuccessful();

        // VAT id is required for e-invoices (BR-CO-26) — clearing it must block the save
        $form = $crawler->filter('form#sttings-form-1')->form();
        $form['invoice_settings[vatID]'] = '';
        $form['invoice_settings[companyName]'] = 'Changed Name GmbH';
        $client->submit($form);
        self::assertResponseIsSuccessful();

        $em->clear();
        $reloaded = $em->getRepository(InvoiceSettingsData::class)->find($settingId);
        self::assertSame('DE123456789', $reloaded->getVatID(), 'Setting must keep its VAT id');
        self::assertSame('Hotel Test', $reloaded->getCompanyName(), 'Nothing must persist when validation fails');
    }

    public function testRegistrationNumberSatisfiesVatRequirement(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());

        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        foreach ($em->getRepository(InvoiceSettingsData::class)->findAll() as $existing) {
            $em->remove($existing);
        }
        $em->flush();

        $setting = $this->buildSetting('en16931', false);
        $em->persist($setting);
        $em->flush();
        $settingId = $setting->getId();

        $crawler = $client->request('GET', '/invoices/settings');
        self::assertResponseIsSuccessful();

        // Clearing the VAT id but providing a registration number (BT-30) must be accepted (BR-CO-26)
        $form = $crawler->filter('form#sttings-form-1')->form();
        $form['invoice_settings[vatID]'] = '';
        $form['invoice_settings[registrationNumber]'] = 'HRB 12345';
        $form['invoice_settings[companyName]'] = 'Changed Name GmbH';
        $client->submit($form);
        self::assertResponseIsSuccessful();

        $em->clear();
        $reloaded = $em->getRepository(InvoiceSettingsData::class)->find($settingId);
        self::assertSame('HRB 12345', $reloaded->getRegistrationNumber());
        self::assertSame('Changed Name GmbH', $reloaded->getCompanyName(), 'Save must persist when a registration number is provided');
    }

    public function testMakingASettingTheDefaultDemotesTheOthers(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());

        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        foreach ($em->getRepository(InvoiceSettingsData::class)->findAll() as $existing) {
            $em->remove($existing);
        }
        $em->flush();

        $active = $this->buildSetting('en16931', true);
        $inactive = $this->buildSetting('en16931', false);
        $em->persist($active);
        $em->persist($inactive);
        $em->flush();
        $activeId = $active->getId();
        $inactiveId = $inactive->getId();

        $crawler = $client->request('GET', '/invoices/settings');
        self::assertResponseIsSuccessful();

        // the current default is rendered first (ordered by isActive DESC) → form 1 is the
        // default, form 2 is the parked one we now promote
        $form = $crawler->filter('form#sttings-form-2')->form();
        $form['invoice_settings[scope]'] = 'default';
        $client->submit($form);
        self::assertResponseIsSuccessful();

        $em->clear();
        self::assertFalse($em->getRepository(InvoiceSettingsData::class)->find($activeId)->isActive(), 'Previous default must be demoted');
        self::assertTrue($em->getRepository(InvoiceSettingsData::class)->find($inactiveId)->isActive(), 'Newly chosen default must be active');
    }

    public function testAssigningABranchDoesNotMakeTheRecordTheDefault(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());

        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        foreach ($em->getRepository(InvoiceSettingsData::class)->findAll() as $existing) {
            $em->remove($existing);
        }
        $em->flush();

        $subsidiary = new Subsidiary();
        $subsidiary->setName('Nord-'.bin2hex(random_bytes(3)));
        $subsidiary->setDescription('Testniederlassung');
        $em->persist($subsidiary);

        $setting = $this->buildSetting('en16931', true);
        $em->persist($setting);
        $em->flush();
        $settingId = $setting->getId();

        $crawler = $client->request('GET', '/invoices/settings');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form#sttings-form-1')->form();
        $form['invoice_settings[scope]'] = 'subsidiary:'.$subsidiary->getId();
        $client->submit($form);
        self::assertResponseIsSuccessful();

        $em->clear();
        $reloaded = $em->getRepository(InvoiceSettingsData::class)->find($settingId);
        self::assertSame($subsidiary->getId(), $reloaded->getSubsidiary()?->getId(), 'Branch assignment must persist');
        self::assertFalse($reloaded->isActive(), 'A branch-specific record is never the global default');
    }

    public function testTwoRecordsCannotClaimTheSameBranch(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());

        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        foreach ($em->getRepository(InvoiceSettingsData::class)->findAll() as $existing) {
            $em->remove($existing);
        }
        $em->flush();

        $subsidiary = new Subsidiary();
        $subsidiary->setName('Sued-'.bin2hex(random_bytes(3)));
        $subsidiary->setDescription('Testniederlassung');
        $em->persist($subsidiary);

        $taken = $this->buildSetting('en16931', false);
        $taken->setSubsidiary($subsidiary);
        $other = $this->buildSetting('en16931', true);
        $em->persist($taken);
        $em->persist($other);
        $em->flush();
        $otherId = $other->getId();

        $crawler = $client->request('GET', '/invoices/settings');
        self::assertResponseIsSuccessful();

        // form 1 is the default record (ordered by isActive DESC); point it at the branch
        // that the other record already covers
        $form = $crawler->filter('form#sttings-form-1')->form();
        $form['invoice_settings[scope]'] = 'subsidiary:'.$subsidiary->getId();
        $client->submit($form);
        self::assertResponseIsSuccessful();

        $em->clear();
        self::assertNull(
            $em->getRepository(InvoiceSettingsData::class)->find($otherId)->getSubsidiary(),
            'A branch that is already covered must be rejected'
        );
    }

    /**
     * Zero days is a payment period, not a missing one: the invoice is due on the day
     * it is issued. The rule asking for terms or a period has to read it as filled,
     * or that setting cannot be saved without also inventing a terms text.
     */
    public function testAPaymentPeriodOfZeroDaysCanBeSaved(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());

        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        foreach ($em->getRepository(InvoiceSettingsData::class)->findAll() as $existing) {
            $em->remove($existing);
        }
        $em->flush();

        $setting = $this->buildSetting('en16931', true);
        $setting->setPaymentTerms(null);
        $em->persist($setting);
        $em->flush();
        $settingId = $setting->getId();

        $crawler = $client->request('GET', '/invoices/settings');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form#sttings-form-1')->form();
        $form['invoice_settings[paymentDueDays]'] = '0';
        $form['invoice_settings[paymentTerms]'] = '';
        $client->submit($form);

        self::assertResponseIsSuccessful();

        $em->clear();
        $reloaded = $em->getRepository(InvoiceSettingsData::class)->find($settingId);
        self::assertSame(0, $reloaded->getPaymentDueDays(), 'A payment period of zero days must survive the save');
    }

    private function buildSetting(string $profile, bool $active): InvoiceSettingsData
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
        $settings->setEinvoiceProfile($profile);
        $settings->setIsActive($active);

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

        $roles = [];
        foreach (['ROLE_INVOICES'] as $roleCode) {
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
