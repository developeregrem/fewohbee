<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AccountingAccount;
use App\Entity\ApiToken;
use App\Entity\BookingEntry;
use App\Entity\Enum\ApiScope;
use App\Entity\Enum\ReceiptProposalStatus;
use App\Entity\ReceiptProposal;
use App\Entity\Role;
use App\Entity\TaxRate;
use App\Entity\User;
use App\Service\ApiTokenService;
use App\Service\AppSettingsService;
use App\Service\BookingJournal\AccountingPresetSeeder;
use App\Service\BookingJournal\AccountingSettingsService;
use App\Service\BookingJournal\BookingJournalService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Receipts handed in by an AI assistant: the assistant only submits what it read and never sees the
 * journal; a person picks the payment and books in the booking journal.
 *
 * All receipts and entries live in 2031, far from the fixtures, and are removed after each test.
 */
final class ReceiptProposalTest extends WebTestCase
{
    private const MODERN_VERSION = '2026-07-28';
    private const YEAR = 2031;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        // A fresh installation has no chart of accounts; the seeder skips what already exists.
        $container = static::getContainer();
        $seeder = $container->get(AccountingPresetSeeder::class);
        $seeder->seedAccounts('skr03');
        $seeder->seedTaxRates('skr03');
        $settingsService = $container->get(AccountingSettingsService::class);
        $settingsService->saveSettings($settingsService->getSettings()->setChartPreset('skr03'));

        $appSettingsService = $container->get(AppSettingsService::class);
        $settings = $appSettingsService->getSettings();
        $settings->setMcpEnabled(true);
        // Handing in receipts must not depend on the write switch: it books nothing.
        $settings->setMcpWriteEnabled(false);
        $settings->setMcpAllowedHosts([]);
        $appSettingsService->saveSettings($settings);
    }

    protected function tearDown(): void
    {
        $connection = $this->em()->getConnection();
        $connection->executeStatement('DELETE FROM receipt_proposals WHERE YEAR(receipt_date) = ?', [self::YEAR]);
        $connection->executeStatement('DELETE e FROM booking_entries e JOIN booking_batches b ON b.id = e.booking_batch_id WHERE b.year = ?', [self::YEAR]);
        $connection->executeStatement('DELETE FROM booking_batches WHERE year = ?', [self::YEAR]);
        parent::tearDown();
    }

    public function testTheAssistantHandsInAReceiptWithoutSeeingTheJournal(): void
    {
        [, $token] = $this->createUserWithToken(['ROLE_CASHJOURNAL'], [ApiScope::MCP_ACCESS, ApiScope::RECEIPTS_SUBMIT]);
        [, $withoutScope] = $this->createUserWithToken(['ROLE_CASHJOURNAL'], [ApiScope::MCP_ACCESS, ApiScope::RESERVATIONS_READ]);
        [, $bankImportOnly] = $this->createUserWithToken(['ROLE_CASHJOURNAL'], [ApiScope::MCP_ACCESS, ApiScope::BANK_IMPORT_WRITE]);
        [, $withoutRole] = $this->createUserWithToken(['ROLE_RESERVATIONS'], [ApiScope::MCP_ACCESS, ApiScope::RECEIPTS_SUBMIT]);
        $entriesBefore = $this->countEntries();

        $receipt = ['supplier' => 'Bauhaus', 'date' => '2031-08-03', 'total' => 303.92, 'receiptNumber' => '2064', 'payment' => 'bank', 'lines' => [
            ['amount' => 250.00, 'taxRate' => 19, 'accountNumber' => '4200', 'text' => 'Putz'],
            ['amount' => 53.92, 'taxRate' => 19, 'accountNumber' => null, 'text' => 'Walzen'],
        ]];
        self::assertTrue($this->callTool($withoutScope, 'submit_receipt', $receipt)['isError'] ?? false);
        self::assertTrue($this->callTool($bankImportOnly, 'submit_receipt', $receipt)['isError'] ?? false);
        self::assertTrue($this->callTool($withoutRole, 'submit_receipt', $receipt)['isError'] ?? false);
        self::assertTrue($this->callTool($token, 'list_bank_import_drafts')['isError'] ?? false);

        // The chart of accounts is all it may read: no cash or bank accounts, no balances.
        $accounts = $this->callTool($token, 'list_accounting_accounts');
        $numbers = array_column($accounts['structuredContent']['accounts'], 'number');
        self::assertContains('4200', $numbers);
        self::assertNotContains('1200', $numbers);
        self::assertNotContains('1000', $numbers);
        self::assertArrayHasKey('untrusted_text', $accounts['structuredContent']['accounts'][0]['name']);

        $taxRates = $this->callTool($token, 'list_tax_rates');
        self::assertArrayHasKey('untrusted_text', $taxRates['structuredContent']['taxRates'][0]['name']);

        $wrongSum = $this->callTool($token, 'submit_receipt', ['total' => 300.00] + $receipt);
        self::assertStringContainsString('add up to 303.92', $wrongSum['content'][0]['text'] ?? '');

        $submitted = $this->callTool($token, 'submit_receipt', $receipt);
        self::assertFalse($submitted['isError'] ?? false, (string) json_encode($submitted));
        self::assertFalse($submitted['structuredContent']['alreadySubmitted']);
        $proposalId = $submitted['structuredContent']['proposalId'];

        $again = $this->callTool($token, 'submit_receipt', $receipt);
        self::assertTrue($again['structuredContent']['alreadySubmitted']);
        self::assertSame($proposalId, $again['structuredContent']['proposalId']);

        // Nothing was booked, and the answer tells nothing about the journal.
        self::assertSame($entriesBefore, $this->countEntries());
        self::assertSame(['proposalId', 'alreadySubmitted', 'status', 'nextStep'], array_keys($submitted['structuredContent']));

        $proposal = $this->em()->find(ReceiptProposal::class, $proposalId);
        self::assertInstanceOf(ReceiptProposal::class, $proposal);
        self::assertSame('303.92', $proposal->getTotal());
        self::assertSame([null, '4200'], [$proposal->getLines()[1]['accountNumber'], $proposal->getLines()[0]['accountNumber']]);
    }

    public function testTwoUnnumberedReceiptsWithTheSameSupplierDateAndAmountStaySeparate(): void
    {
        [, $token] = $this->createUserWithToken(['ROLE_CASHJOURNAL'], [ApiScope::MCP_ACCESS, ApiScope::RECEIPTS_SUBMIT]);
        $receipt = ['supplier' => 'Bäckerei', 'date' => '2031-08-03', 'total' => 5.00, 'lines' => [
            ['amount' => 5.00, 'taxRate' => 7, 'accountNumber' => '4200'],
        ]];

        $first = $this->callTool($token, 'submit_receipt', $receipt);
        $second = $this->callTool($token, 'submit_receipt', $receipt);

        self::assertFalse($first['isError'] ?? false, (string) json_encode($first));
        self::assertFalse($second['isError'] ?? false, (string) json_encode($second));
        self::assertNotSame($first['structuredContent']['proposalId'], $second['structuredContent']['proposalId']);
    }

    /** Clients drop a tool whose schema they cannot read, e.g. a draft-04 style boolean exclusiveMinimum. */
    public function testEveryToolSchemaIsReadableForCurrentClients(): void
    {
        [, $token] = $this->createUserWithToken(['ROLE_CASHJOURNAL'], [ApiScope::MCP_ACCESS, ApiScope::RECEIPTS_SUBMIT]);
        $this->client->request('POST', '/mcp', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_MCP_PROTOCOL_VERSION' => self::MODERN_VERSION,
            'HTTP_MCP_METHOD' => 'tools/list',
        ], (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['_meta' => [
            'io.modelcontextprotocol/protocolVersion' => self::MODERN_VERSION,
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            'io.modelcontextprotocol/clientInfo' => ['name' => 'functional-test', 'version' => '1.0'],
        ]]]));

        $tools = json_decode((string) $this->client->getResponse()->getContent(), true)['result']['tools'] ?? [];
        self::assertContains('submit_receipt', array_column($tools, 'name'));
        self::assertNotContains('list_bank_import_drafts', array_column($tools, 'name'));

        $booleanBounds = [];
        array_walk_recursive($tools, static function (mixed $value, string|int $key) use (&$booleanBounds): void {
            if (\in_array($key, ['exclusiveMinimum', 'exclusiveMaximum'], true) && \is_bool($value)) {
                $booleanBounds[] = $key;
            }
        });
        self::assertSame([], $booleanBounds);
    }

    public function testTheImportedPaymentIsSplitOnceAPersonConfirms(): void
    {
        // The bank import already named the supplier; that is no sign of a booking done twice.
        $payment = $this->bookEntry('2031-03-16', '58.17', debit: '4980', credit: '1200', remark: 'Kaufland');
        $proposal = $this->propose('Kaufland', '2031-03-14', '58.17', 'bank', [['4930', '19.99', '19'], ['4200', '38.18', '7']]);
        $this->client->loginUser($this->createUserWithToken(['ROLE_CASHJOURNAL'], [])[0]);

        $crawler = $this->client->request('GET', '/journal/receipts/'.$proposal);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.alert-warning'));
        // The payment found in the journal is preselected.
        self::assertSame('entry:'.$payment, $crawler->filter('input[name="payment"]:checked')->attr('value'));

        $this->client->submit($crawler->filter('form[action$="/book"]')->form());
        self::assertResponseRedirects('/journal/receipts');

        $entries = $this->entriesOf($proposal);
        self::assertSame($payment, $entries[0]->getId(), 'The payment itself becomes the first part and keeps its import link.');
        self::assertSame(['4930', '4200'], array_map(static fn (BookingEntry $e): string => (string) $e->getDebitAccount()?->getAccountNumber(), $entries));
        self::assertSame(['1200', '1200'], array_map(static fn (BookingEntry $e): string => (string) $e->getCreditAccount()?->getAccountNumber(), $entries));
        self::assertSame(['19.99', '38.18'], array_map(static fn (BookingEntry $e): string => $e->getAmount(), $entries));
        self::assertSame(['19.00', '7.00'], array_map(static fn (BookingEntry $e): string => (string) $e->getTaxRate()?->getRate(), $entries));
        self::assertSame(['Kaufland', 'Kaufland'], array_map(static fn (BookingEntry $e): ?string => $e->getRemark(), $entries));
        self::assertSame(['2031-03-16', '2031-03-16'], array_map(static fn (BookingEntry $e): string => $e->getDate()->format('Y-m-d'), $entries));
        self::assertNotNull($entries[0]->getSplitGroupUuid());
        self::assertSame($entries[0]->getSplitGroupUuid(), $entries[1]->getSplitGroupUuid());
    }

    /** The receipt was split by hand when the statement came in: no single payment is left to split. */
    public function testAReceiptBookedByHandAlreadyIsFlaggedAndNotPreselectedForBookingAnew(): void
    {
        $first = $this->bookEntry('2031-02-19', '89.81', debit: '4980', credit: '1200', remark: 'Philipps');
        $second = $this->bookEntry('2031-02-19', '3.48', debit: '4200', credit: '1200', remark: 'Philipps');
        $unrelated = $this->bookEntry('2031-02-20', '12.00', debit: '4980', credit: '1200', remark: 'Post');
        $proposal = $this->propose('Thomas Philipps', '2031-02-17', '93.29', 'bank', [['4200', '89.81', '19'], ['4200', '3.48', '7']]);
        $this->client->loginUser($this->createUserWithToken(['ROLE_CASHJOURNAL'], [])[0]);

        $crawler = $this->client->request('GET', '/journal/receipts/'.$proposal);
        $warning = $crawler->filter('.alert-warning');
        self::assertCount(1, $warning);
        self::assertCount(2, $warning->filter('tbody tr'));
        self::assertStringContainsString('89,81', $warning->text());
        self::assertStringContainsString('3,48', $warning->text());
        self::assertStringNotContainsString('12,00', $warning->text());
        // Booking anew has to be picked on purpose.
        self::assertCount(0, $crawler->filter('input[name="payment"]:checked'));

        $this->client->submit($crawler->filter('form[action$="/book"]')->form());
        self::assertResponseStatusCodeSame(422);
        self::assertSame(ReceiptProposalStatus::OPEN, $this->proposal($proposal)->getStatus());
        self::assertSame([$first, $second, $unrelated], array_map(
            fn (int $id): int => (int) $this->em()->find(BookingEntry::class, $id)?->getId(),
            [$first, $second, $unrelated],
        ));
    }

    /** Entries that add up to the receipt are flagged even when their text does not name the supplier. */
    public function testEntriesAddingUpToTheReceiptAreFlaggedWithoutTheSupplierName(): void
    {
        $this->bookEntry('2031-04-02', '10.00', debit: '4980', credit: '1000', remark: 'Einkauf');
        $this->bookEntry('2031-04-02', '5.50', debit: '4200', credit: '1000', remark: 'Einkauf');
        $proposal = $this->propose('Lidl', '2031-04-02', '15.50', 'cash', [['4980', '15.50', '7']]);
        $this->client->loginUser($this->createUserWithToken(['ROLE_CASHJOURNAL'], [])[0]);

        $crawler = $this->client->request('GET', '/journal/receipts/'.$proposal);
        self::assertCount(2, $crawler->filter('.alert-warning tbody tr'));
    }

    public function testACashReceiptWithoutPaymentIsBookedAnewAgainstTheCash(): void
    {
        $proposal = $this->propose('OBI', '2031-05-02', '15.70', 'cash', [['4200', '12.50', '19'], ['4980', '3.20', '0']]);
        $this->client->loginUser($this->createUserWithToken(['ROLE_CASHJOURNAL'], [])[0]);

        $crawler = $this->client->request('GET', '/journal/receipts/'.$proposal);
        self::assertSame('new:1000', $crawler->filter('input[name="payment"]:checked')->attr('value'));
        $this->client->submit($crawler->filter('form[action$="/book"]')->form());
        self::assertResponseRedirects('/journal/receipts');

        $entries = $this->entriesOf($proposal);
        self::assertSame(['1000', '1000'], array_map(static fn (BookingEntry $e): string => (string) $e->getCreditAccount()?->getAccountNumber(), $entries));
        self::assertSame(['2031-05-02', '2031-05-02'], array_map(static fn (BookingEntry $e): string => $e->getDate()->format('Y-m-d'), $entries));
    }

    public function testCorrectionsAreCheckedAndNothingIsBookedOnAMismatch(): void
    {
        $payment = $this->bookEntry('2031-03-16', '58.17', debit: '4980', credit: '1200');
        $proposal = $this->propose('Kaufland', '2031-03-14', '58.17', 'bank', [['4930', '19.99', '19'], ['4200', '38.18', '7']]);
        $this->client->loginUser($this->createUserWithToken(['ROLE_CASHJOURNAL'], [])[0]);

        $form = $this->client->request('GET', '/journal/receipts/'.$proposal)->filter('form[action$="/book"]')->form();
        $form['lines[1][amount]'] = '38,00';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('57.99', $this->client->getCrawler()->filter('.alert-danger')->text());
        self::assertSame(ReceiptProposalStatus::OPEN, $this->proposal($proposal)->getStatus());
        $entry = $this->em()->find(BookingEntry::class, $payment);
        self::assertInstanceOf(BookingEntry::class, $entry);
        self::assertSame('4980', $entry->getDebitAccount()?->getAccountNumber());
        self::assertNull($entry->getSplitGroupUuid());

        // A corrected account and a German amount are taken as entered.
        $form = $this->client->getCrawler()->filter('form[action$="/book"]')->form();
        $form['lines[1][amount]'] = '38,18';
        $form['lines[1][accountNumber]'] = '4980';
        $this->client->submit($form);
        self::assertResponseRedirects('/journal/receipts');
        self::assertSame(['4930', '4980'], array_map(static fn (BookingEntry $e): string => (string) $e->getDebitAccount()?->getAccountNumber(), $this->entriesOf($proposal)));
    }

    public function testADiscardedProposalLeavesTheJournalAlone(): void
    {
        $proposal = $this->propose('Lidl', '2031-06-01', '9.99', 'unknown', [['4980', '9.99', '7']]);
        $this->client->loginUser($this->createUserWithToken(['ROLE_CASHJOURNAL'], [])[0]);
        $entriesBefore = $this->countEntries();

        $crawler = $this->client->request('GET', '/journal/receipts/'.$proposal);
        preg_match('/name="_token" value="([^"]+)"/', (string) $crawler->filter('[data-popover="delete"]')->attr('data-bs-content'), $match);
        $this->client->request('DELETE', '/journal/receipts/'.$proposal.'/discard', ['_token' => $match[1] ?? '']);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(ReceiptProposalStatus::DISCARDED, $this->proposal($proposal)->getStatus());
        self::assertSame($entriesBefore, $this->countEntries());
    }

    public function testOpenProposalsShowInTheJournalAndNeedTheCashJournalRole(): void
    {
        $this->propose('Lidl', '2031-06-01', '9.99', 'unknown', [['4980', '9.99', '7']]);

        $this->client->loginUser($this->createUserWithToken(['ROLE_RESERVATIONS'], [])[0]);
        $this->client->request('GET', '/journal/receipts');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->createUserWithToken(['ROLE_CASHJOURNAL'], [])[0]);
        $crawler = $this->client->request('GET', '/journal');
        self::assertGreaterThanOrEqual(1, (int) $crawler->filter('a[href="/journal/receipts"] .badge')->text());
        self::assertStringContainsString('Lidl', $this->client->request('GET', '/journal/receipts')->filter('table')->text());
    }

    public function testReceiptLinkIsHiddenWithoutMcpButPastProposalsRemainAccessible(): void
    {
        $this->client->loginUser($this->createUserWithToken(['ROLE_CASHJOURNAL'], [])[0]);

        $crawler = $this->client->request('GET', '/journal');
        self::assertCount(1, $crawler->filter('a[href="/journal/receipts"]'));

        $settingsService = static::getContainer()->get(AppSettingsService::class);
        $settings = $settingsService->getSettings();
        $settings->setMcpEnabled(false);
        $settingsService->saveSettings($settings);

        $crawler = $this->client->request('GET', '/journal');
        self::assertCount(0, $crawler->filter('a[href="/journal/receipts"]'));

        $proposalId = $this->propose('Lidl', '2031-06-01', '9.99', 'unknown', [['4980', '9.99', '7']]);
        $crawler = $this->client->request('GET', '/journal');
        self::assertCount(1, $crawler->filter('a[href="/journal/receipts"]'));

        $this->proposal($proposalId)->discard(null, new \DateTimeImmutable());
        $this->em()->flush();
        $crawler = $this->client->request('GET', '/journal');
        self::assertCount(1, $crawler->filter('a[href="/journal/receipts"]'));
        self::assertCount(0, $crawler->filter('a[href="/journal/receipts"] .badge'));
    }

    public function testAnAiTokenThatOnlyHandsInReceiptsCanBeCreated(): void
    {
        [$user] = $this->createUserWithToken(['ROLE_CASHJOURNAL'], [ApiScope::MCP_ACCESS]);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/profile/');
        // Offered although writing is switched off: handing in books nothing.
        self::assertCount(1, $crawler->filter(\sprintf('input[name="api_token[scopes][]"][value="%s"]', ApiScope::RECEIPTS_SUBMIT->value)));

        $form = $crawler->filter('#apiTokenOffcanvas form')->form();
        $values = $form->getPhpValues();
        $values['api_token'] = [
            'name' => 'Belege',
            'kind' => 'mcp',
            'expiresIn' => '+30 days',
            'scopes' => [ApiScope::RECEIPTS_SUBMIT->value],
            '_token' => $values['api_token']['_token'] ?? '',
        ];
        $this->client->request('POST', (string) $form->getUri(), $values);

        $token = $this->em()->getRepository(ApiToken::class)->findOneBy(['user' => $user, 'name' => 'Belege']);
        self::assertNotNull($token);
        self::assertEqualsCanonicalizing([ApiScope::MCP_ACCESS->value, ApiScope::RECEIPTS_SUBMIT->value], $token->getScopes());
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $lines account, amount, VAT rate
     */
    private function propose(string $supplier, string $date, string $total, string $payment, array $lines): int
    {
        $proposal = new ReceiptProposal(
            $supplier,
            new \DateTimeImmutable($date),
            null,
            $total,
            $payment,
            array_map(static fn (array $line): array => ['text' => null, 'amount' => $line[1], 'taxRate' => $line[2], 'accountNumber' => $line[0]], $lines),
            null,
            null,
            null,
        );
        $em = $this->em();
        $em->persist($proposal);
        $em->flush();

        return (int) $proposal->getId();
    }

    /** Books one entry the way the bank import or the cash journal form would, and returns its id. */
    private function bookEntry(string $date, string $amount, string $debit, string $credit, string $remark = 'Kartenzahlung'): int
    {
        $em = $this->em();
        $accounts = $em->getRepository(AccountingAccount::class);
        $entry = static::getContainer()->get(BookingJournalService::class)->createEntryFromStatement(
            new \DateTimeImmutable($date),
            $amount,
            $accounts->findOneBy(['accountNumber' => $debit, 'chartPreset' => 'skr03']),
            $accounts->findOneBy(['accountNumber' => $credit, 'chartPreset' => 'skr03']),
            $remark,
            null,
            null,
            null,
            $em->getRepository(TaxRate::class)->findOneBy(['rate' => '0.00', 'chartPreset' => 'skr03']),
        );
        $em->flush();

        return (int) $entry->getId();
    }

    /** @return list<BookingEntry> */
    private function entriesOf(int $proposalId): array
    {
        $proposal = $this->proposal($proposalId);
        self::assertSame(ReceiptProposalStatus::BOOKED, $proposal->getStatus());

        return array_map(
            fn (int $id): BookingEntry => $this->em()->find(BookingEntry::class, $id) ?? throw new \RuntimeException('Entry missing.'),
            $proposal->getBookedEntryIds(),
        );
    }

    private function proposal(int $id): ReceiptProposal
    {
        $em = $this->em();
        $em->clear();

        return $em->find(ReceiptProposal::class, $id) ?? throw new \RuntimeException('Proposal missing.');
    }

    private function countEntries(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM booking_entries');
    }

    /**
     * Calls a tool through the stateless 2026-07-28 revision.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> the tool result
     */
    private function callTool(string $token, string $name, array $arguments = []): array
    {
        $this->client->request('POST', '/mcp', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_MCP_PROTOCOL_VERSION' => self::MODERN_VERSION,
            'HTTP_MCP_METHOD' => 'tools/call',
            'HTTP_MCP_NAME' => $name,
        ], (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => [] === $arguments ? new \stdClass() : $arguments,
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => self::MODERN_VERSION,
                    'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                    'io.modelcontextprotocol/clientInfo' => ['name' => 'functional-test', 'version' => '1.0'],
                ],
            ],
        ]));

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $response = (array) json_decode((string) $this->client->getResponse()->getContent(), true);

        return $response['result'] ?? ['isError' => true, 'content' => [['text' => (string) json_encode($response)]]];
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /**
     * @param list<string>   $roleCodes
     * @param list<ApiScope> $scopes
     *
     * @return array{0: User, 1: string}
     */
    private function createUserWithToken(array $roleCodes, array $scopes): array
    {
        $container = static::getContainer();
        $em = $this->em();

        $user = new User();
        $user->setUsername('receipt_'.bin2hex(random_bytes(6)));
        $user->setFirstname('Receipt');
        $user->setLastname('Tester');
        $user->setEmail(\sprintf('receipt+%s@example.com', bin2hex(random_bytes(4))));
        $user->setActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'ChangeMe123!'));
        $user->setRoleEntities(array_map(
            fn (string $code): Role => $em->getRepository(Role::class)->findOneBy(['role' => $code]) ?? throw new \RuntimeException('Role '.$code.' missing.'),
            $roleCodes,
        ));
        $em->persist($user);
        $em->flush();

        if ([] === $scopes) {
            return [$user, ''];
        }

        $result = $container->get(ApiTokenService::class)->createToken(
            $user,
            'functional-test',
            array_map(static fn (ApiScope $scope): string => $scope->value, $scopes),
            new \DateTimeImmutable('+30 days'),
        );

        return [$user, $result->plainToken];
    }
}
