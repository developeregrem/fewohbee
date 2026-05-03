<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Dto\BookingJournal\BankImport\ImportState;
use App\Entity\AccountingAccount;
use App\Entity\BankCsvProfile;
use App\Entity\Invoice;
use App\Entity\InvoiceAppartment;
use App\Entity\InvoicePosition;
use App\Entity\Role;
use App\Entity\User;
use App\Service\BookingJournal\AccountingSettingsService;
use App\Service\BookingJournal\BankImport\BankImportDraftSession;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class BankImportControllerTest extends WebTestCase
{
    private const FIXTURE_DIR = __DIR__.'/../Fixtures/BankImport';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('bankExportCases')]
    public function testUploadBuildsPreviewFromRealisticCsvExport(string $profileType, string $fixtureName, array $expected): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCashJournalUser());

        $this->configureInvoiceNumberMatching();
        $invoice = $this->createInvoice('2026-0101', 585.00);
        $bankAccount = $this->createBankAccount((string) $expected['sourceIban']);
        $profile = $this->createCsvProfile($profileType);

        $crawler = $client->request('GET', '/journal/bank-import');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['bank_statement_upload[bankAccount]']->select((string) $bankAccount->getId());
        $form['bank_statement_upload[csvProfile]']->select((string) $profile->getId());
        $form['bank_statement_upload[file]']->upload(self::FIXTURE_DIR.'/'.$fixtureName);

        $client->submit($form);

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/journal/bank-import/([0-9a-f-]{36})$#', $location);
        preg_match('#/journal/bank-import/([0-9a-f-]{36})$#', $location, $matches);
        $sessionImportId = $matches[1];

        $drafts = $client->getRequest()->getSession()->get(BankImportDraftSession::SESSION_KEY, []);
        self::assertArrayHasKey($sessionImportId, $drafts);
        $state = ImportState::fromArray($drafts[$sessionImportId]);

        self::assertSame((int) $bankAccount->getId(), $state->bankAccountId);
        self::assertSame((int) $profile->getId(), $state->bankCsvProfileId);
        self::assertSame($fixtureName, $state->originalFilename);
        self::assertSame($expected['sourceIban'], $state->sourceIban);
        self::assertSame($expected['periodFrom'], $state->periodFrom);
        self::assertSame($expected['periodTo'], $state->periodTo);
        self::assertSame([], $state->warnings);
        self::assertCount($expected['lineCount'], $state->lines);

        $firstLine = $state->lines[0];
        self::assertSame($expected['firstLine']['bookDate'], $firstLine['bookDate']);
        self::assertSame($expected['firstLine']['amount'], $firstLine['amount']);
        self::assertSame($expected['firstLine']['counterpartyName'], $firstLine['counterpartyName']);
        self::assertSame($expected['firstLine']['counterpartyIban'], $firstLine['counterpartyIban']);
        self::assertStringContainsString($expected['firstLine']['purposeContains'], $firstLine['purpose']);

        $invoiceLine = $this->findLineByPurpose($state, '2026-0101');
        self::assertNotNull($invoiceLine);
        self::assertSame($invoice->getId(), $invoiceLine['matchedInvoiceId']);
        self::assertSame('2026-0101', $invoiceLine['matchedInvoiceNumber']);
        self::assertTrue($invoiceLine['matchedInvoiceAmountMatches']);
        self::assertSame(ImportState::LINE_STATUS_READY, $invoiceLine['status']);
        self::assertSame((int) $bankAccount->getId(), $invoiceLine['userDebitAccountId']);

        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h4', $fixtureName);
        self::assertStringContainsString('2026-0101', (string) $client->getResponse()->getContent());
    }

    public function testSavedSplitRuleUsesPurposeMarkersForRecurringLines(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCashJournalUser());

        $bankAccount = $this->createBankAccount('DE00POSTBANKTEST0001');
        $profile = $this->createCsvProfile('postbank');
        $interestAccount = $this->createExpenseAccount('Split Zinsen');
        $commissionAccount = $this->createExpenseAccount('Split Provision');
        $feeAccount = $this->createExpenseAccount('Split Entgelte');

        $crawler = $client->request('GET', '/journal/bank-import');
        $form = $crawler->filter('form')->form();
        $form['bank_statement_upload[bankAccount]']->select((string) $bankAccount->getId());
        $form['bank_statement_upload[csvProfile]']->select((string) $profile->getId());
        $form['bank_statement_upload[file]']->upload(self::FIXTURE_DIR.'/postbank-girokonto-anonymized.csv');
        $client->submit($form);

        preg_match('#/journal/bank-import/([0-9a-f-]{36})$#', (string) $client->getResponse()->headers->get('Location'), $matches);
        $sessionImportId = $matches[1];
        $preview = $client->followRedirect();
        $token = (string) $preview->filter('[data-bank-import-preview-csrf-value]')->attr('data-bank-import-preview-csrf-value');
        $state = $this->loadDraftFromSession($client->getRequest()->getSession(), $sessionImportId);
        $sourceIdx = $this->findLineIndexByPurpose($state, 'Zinsen fuer Kredit 12,30-');
        self::assertNotNull($sourceIdx);

        $client->request('POST', sprintf('/journal/bank-import/%s/line/%d/split', $sessionImportId, $sourceIdx), [
            '_token' => $token,
            'splits' => [
                ['amount' => '12.30', 'debitAccountId' => $interestAccount->getId(), 'creditAccountId' => $bankAccount->getId(), 'remark' => 'Zinsen'],
                ['amount' => '4.20', 'debitAccountId' => $commissionAccount->getId(), 'creditAccountId' => $bankAccount->getId(), 'remark' => 'Provision'],
                ['amount' => '25.00', 'debitAccountId' => $feeAccount->getId(), 'creditAccountId' => $bankAccount->getId(), 'remark' => 'Entgelte'],
            ],
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/journal/bank-import/%s/line/%d/rule', $sessionImportId, $sourceIdx), [
            '_token' => $token,
            'name' => 'Postbank Entgeltabrechnung dynamisch',
            'conditionFields' => ['purpose'],
            'purposeContains' => 'Information zur Abrechnung',
            'actionMode' => 'split',
            'priority' => '80',
            'scopeToBankAccount' => '1',
            'splits' => [
                ['amountSource' => 'purpose_marker', 'marker' => 'Zinsen', 'debitAccountId' => $interestAccount->getId(), 'creditAccountId' => $bankAccount->getId(), 'remark' => 'Zinsen'],
                ['amountSource' => 'purpose_marker', 'marker' => 'Kreditprovision', 'debitAccountId' => $commissionAccount->getId(), 'creditAccountId' => $bankAccount->getId(), 'remark' => 'Provision'],
                ['remainder' => '1', 'debitAccountId' => $feeAccount->getId(), 'creditAccountId' => $bankAccount->getId(), 'remark' => 'Entgelte'],
            ],
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $state = $this->loadDraftFromSession($client->getRequest()->getSession(), $sessionImportId);
        $recurringLine = $state->lines[$this->findLineIndexByPurpose($state, 'Zinsen fuer Kredit 15,00-') ?? -1] ?? null;
        self::assertNotNull($recurringLine);
        self::assertSame(ImportState::LINE_STATUS_READY, $recurringLine['status']);
        self::assertNotNull($recurringLine['appliedRuleId']);
        self::assertSame('-15.00', $recurringLine['splits'][0]['amount']);
        self::assertSame('-5.00', $recurringLine['splits'][1]['amount']);
        self::assertSame('-30.00', $recurringLine['splits'][2]['amount']);
    }

    /**
     * @return iterable<string, array{profileType: string, fixtureName: string, expected: array<string, mixed>}>
     */
    public static function bankExportCases(): iterable
    {
        yield 'DKB giro export' => [
            'profileType' => 'dkb',
            'fixtureName' => 'dkb-girokonto-anonymized.csv',
            'expected' => [
                'sourceIban' => 'DE00DKBTESTKONTO0001',
                'periodFrom' => '2026-03-01',
                'periodTo' => '2026-03-31',
                'lineCount' => 7,
                'firstLine' => [
                    'bookDate' => '2026-03-31',
                    'amount' => '-41.98',
                    'counterpartyName' => 'MUSTERHANDEL XYZ',
                    'counterpartyIban' => 'DKB-CP-001',
                    'purposeContains' => 'VISA Debitkartenumsatz',
                ],
            ],
        ];

        yield 'Postbank giro export' => [
            'profileType' => 'postbank',
            'fixtureName' => 'postbank-girokonto-anonymized.csv',
            'expected' => [
                'sourceIban' => 'DE00POSTBANKTEST0001',
                'periodFrom' => '2025-04-01',
                'periodTo' => '2026-04-26',
                'lineCount' => 8,
                'firstLine' => [
                    'bookDate' => '2026-04-19',
                    'amount' => '-5.50',
                    'counterpartyName' => 'MUSTERHANDEL XYZ',
                    'counterpartyIban' => 'PB-CP-001',
                    'purposeContains' => 'MUSTERHANDEL XYZ KARTENZAHLUNG',
                ],
            ],
        ];
    }

    private function configureInvoiceNumberMatching(): void
    {
        $this->removeInvoices(['2026-0101']);

        $settingsService = static::getContainer()->get(AccountingSettingsService::class);
        $settings = $settingsService->getSettings();
        $settings->setInvoiceNumberSamples(['2026-0101']);
        $settingsService->saveSettings($settings);
    }

    private function createBankAccount(string $iban): AccountingAccount
    {
        $account = new AccountingAccount();
        $account->setAccountNumber((string) random_int(900000, 999999));
        $account->setName('Bankimport Testkonto');
        $account->setType(AccountingAccount::TYPE_ASSET);
        $account->setIsBankAccount(true);
        $account->setIban($iban);

        $em = $this->getEntityManager();
        $em->persist($account);
        $em->flush();

        return $account;
    }

    private function createExpenseAccount(string $name): AccountingAccount
    {
        $account = new AccountingAccount();
        $account->setAccountNumber((string) random_int(700000, 799999));
        $account->setName($name);
        $account->setType(AccountingAccount::TYPE_EXPENSE);

        $em = $this->getEntityManager();
        $em->persist($account);
        $em->flush();

        return $account;
    }

    private function createCsvProfile(string $profileType): BankCsvProfile
    {
        $profile = match ($profileType) {
            'dkb' => (new BankCsvProfile())
                ->setName('DKB Girokonto Functional Test')
                ->setDelimiter(';')
                ->setEnclosure('"')
                ->setEncoding('UTF-8')
                ->setHeaderSkip(4)
                ->setHasHeaderRow(true)
                ->setColumnMap([
                    'bookDate' => 0,
                    'valueDate' => 1,
                    'counterpartyName' => 4,
                    'purpose' => 5,
                    'counterpartyIban' => 7,
                    'amount' => 8,
                    'creditorId' => 9,
                    'mandateReference' => 10,
                    'endToEndId' => 11,
                ])
                ->setDateFormat('d.m.y')
                ->setAmountDecimalSeparator(',')
                ->setAmountThousandsSeparator('.')
                ->setDirectionMode(BankCsvProfile::DIRECTION_SIGNED)
                ->setIbanSourceLine(0)
                ->setPeriodSourceLine(1),
            'postbank' => (new BankCsvProfile())
                ->setName('Postbank Giro plus Functional Test')
                ->setDelimiter(';')
                ->setEnclosure('"')
                ->setEncoding('UTF-8')
                ->setHeaderSkip(7)
                ->setHasHeaderRow(true)
                ->setColumnMap([
                    'bookDate' => 0,
                    'valueDate' => 1,
                    'counterpartyName' => 3,
                    'purpose' => 4,
                    'counterpartyIban' => 5,
                    'endToEndId' => 7,
                    'mandateReference' => 8,
                    'creditorId' => 9,
                    'amount' => 11,
                ])
                ->setDateFormat('d.m.Y')
                ->setAmountDecimalSeparator(',')
                ->setAmountThousandsSeparator('.')
                ->setDirectionMode(BankCsvProfile::DIRECTION_SIGNED)
                ->setIbanSourceLine(2)
                ->setPeriodSourceLine(4),
            default => throw new \InvalidArgumentException(sprintf('Unknown profile type "%s".', $profileType)),
        };

        $em = $this->getEntityManager();
        $em->persist($profile);
        $em->flush();

        return $profile;
    }

    private function createInvoice(string $number, float $amount): Invoice
    {
        $invoice = new Invoice();
        $invoice->setNumber($number);
        $invoice->setDate(new \DateTime('2026-04-15'));
        $invoice->setStatus(1);
        $invoice->setFirstname('Max');
        $invoice->setLastname('Mustermann');

        $appartment = new InvoiceAppartment();
        $appartment->setNumber('A1');
        $appartment->setDescription('Aufenthalt');
        $appartment->setBeds(2);
        $appartment->setPersons(2);
        $appartment->setStartDate(new \DateTime('2026-04-01'));
        $appartment->setEndDate(new \DateTime('2026-04-03'));
        $appartment->setIsFlatPrice(true);
        $appartment->setIncludesVat(true);
        $appartment->setPrice($amount);
        $appartment->setVat(0.0);
        $appartment->setInvoice($invoice);
        $invoice->addAppartment($appartment);

        $em = $this->getEntityManager();
        $em->persist($invoice);
        $em->persist($appartment);
        $em->flush();

        return $invoice;
    }

    /**
     * @param list<string> $numbers
     */
    private function removeInvoices(array $numbers): void
    {
        $em = $this->getEntityManager();
        $invoices = $em->getRepository(Invoice::class)->findBy(['number' => $numbers]);

        foreach ($invoices as $invoice) {
            foreach ($em->getRepository(InvoiceAppartment::class)->findBy(['invoice' => $invoice]) as $appartment) {
                $em->remove($appartment);
            }
            foreach ($em->getRepository(InvoicePosition::class)->findBy(['invoice' => $invoice]) as $position) {
                $em->remove($position);
            }
            $em->remove($invoice);
        }

        $em->flush();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLineByPurpose(ImportState $state, string $needle): ?array
    {
        foreach ($state->lines as $line) {
            if (str_contains((string) ($line['purpose'] ?? ''), $needle)) {
                return $line;
            }
        }

        return null;
    }

    private function findLineIndexByPurpose(ImportState $state, string $needle): ?int
    {
        foreach ($state->lines as $idx => $line) {
            if (str_contains((string) ($line['purpose'] ?? ''), $needle)) {
                return $idx;
            }
        }

        return null;
    }

    private function loadDraftFromSession(\Symfony\Component\HttpFoundation\Session\SessionInterface $session, string $sessionImportId): ImportState
    {
        $drafts = $session->get(BankImportDraftSession::SESSION_KEY, []);
        self::assertArrayHasKey($sessionImportId, $drafts);

        return ImportState::fromArray($drafts[$sessionImportId]);
    }

    private function getEntityManager(): EntityManagerInterface
    {
        if (!isset($this->em)) {
            $this->em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        }

        return $this->em;
    }

    private function createCashJournalUser(): User
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();
        $roleRepository = $em->getRepository(Role::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $role = $roleRepository->findOneBy(['role' => 'ROLE_CASHJOURNAL']);
        self::assertNotNull($role, 'Role ROLE_CASHJOURNAL must exist in database.');

        $user = new User();
        $user->setUsername('test_'.bin2hex(random_bytes(6)));
        $user->setFirstname('Test');
        $user->setLastname('User');
        $user->setEmail(sprintf('test+%s@example.com', bin2hex(random_bytes(4))));
        $user->setActive(true);
        $user->setPassword($passwordHasher->hashPassword($user, 'ChangeMe123!'));
        $user->setRoleEntities([$role]);

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
