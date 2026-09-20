<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AccountingAccount;
use App\Entity\BankImportRule;
use App\Entity\Role;
use App\Entity\TaxRate;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Editing a saved bank-import rule: its values can be corrected, the structure
 * the import preview recorded stays as it is.
 */
final class BankImportRuleControllerTest extends WebTestCase
{
    private const RULE_NAME = 'Functional Test Regel';

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testEditFormExposesValuesButNotStructure(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCashJournalUser());
        $rule = $this->createSplitRule();

        $crawler = $client->request('GET', '/journal/bank-import/rules/'.$rule->getId().'/edit');
        self::assertResponseIsSuccessful();

        self::assertSame(
            'Stadtwerk Münchn',
            $crawler->filter('input[name="bank_import_rule[conditions][0][value]"]')->attr('value'),
        );
        // The direction condition offers its two tokens instead of free text.
        self::assertCount(1, $crawler->filter('select[name="bank_import_rule[conditions][1][value]"]'));
        self::assertSame(
            'Zinsn',
            $crawler->filter('input[name="bank_import_rule[action][splits][0][marker]"]')->attr('value'),
        );
        self::assertCount(1, $crawler->filter('select[name="bank_import_rule[action][splits][0][debitAccountId]"]'));
        self::assertCount(1, $crawler->filter('input[name="bank_import_rule[action][invoiceNumberExtraction][marker]"]'));

        // The remainder row carries no text to fix, and nothing may edit which
        // field, operator or amount source the rule uses.
        self::assertCount(0, $crawler->filter('input[name="bank_import_rule[action][splits][1][marker]"]'));
        self::assertCount(0, $crawler->filter('[name*="[field]"], [name*="[operator]"], [name*="[amountSource]"], [name*="[mode]"]'));
    }

    public function testSettingsTableRendersTheRuleSummary(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCashJournalUser());
        $this->createSplitRule();

        $crawler = $client->request('GET', '/journal/bank-import/settings?tab=tab-rules');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::RULE_NAME, $crawler->filter('#tab-rules')->text());
        // The summary spells out how each split row finds its amount.
        self::assertStringContainsString('Zinsn', $crawler->filter('#tab-rules')->text());
    }

    public function testUpdateSavesNewValuesAndKeepsStructure(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCashJournalUser());
        $rule = $this->createSplitRule();
        $otherAccount = $this->createExpenseAccount('Regel Zielkonto neu');
        $ruleId = (int) $rule->getId();

        $crawler = $client->request('GET', '/journal/bank-import/rules/'.$ruleId.'/edit');
        $form = $crawler->filter('form')->form();
        $form['bank_import_rule[conditions][0][value]'] = 'Stadtwerke München';
        $form['bank_import_rule[action][splits][0][marker]'] = 'Zinsen';
        $form['bank_import_rule[action][splits][0][debitAccountId]']->select((string) $otherAccount->getId());
        $form['bank_import_rule[action][splits][1][remarkTemplate]'] = 'Restliche Entgelte';
        $form['bank_import_rule[action][invoiceNumberExtraction][marker]'] = 'Rechnung Nr.';
        $client->submit($form);

        self::assertResponseRedirects();

        $saved = $this->reloadRule($ruleId);
        $conditions = $saved->getConditions();
        self::assertSame('Stadtwerke München', $conditions[0]['value']);
        self::assertSame('counterpartyName', $conditions[0]['field'], 'the condition field must survive the save');
        self::assertSame('contains', $conditions[0]['operator'], 'the operator must survive the save');
        self::assertSame('out', $conditions[1]['value']);

        $action = $saved->getAction();
        self::assertSame(BankImportRule::ACTION_MODE_SPLIT, $action['mode']);
        self::assertCount(2, $action['splits'], 'no split row may appear or vanish');
        self::assertSame('purpose_marker', $action['splits'][0]['amountSource']);
        self::assertSame('Zinsen', $action['splits'][0]['marker']);
        self::assertSame((int) $otherAccount->getId(), $action['splits'][0]['debitAccountId']);
        self::assertTrue($action['splits'][1]['remainder'], 'the remainder row stays the remainder row');
        self::assertSame('Restliche Entgelte', $action['splits'][1]['remarkTemplate']);
        self::assertSame(['mode' => 'marker', 'marker' => 'Rechnung Nr.'], $action['invoiceNumberExtraction']);
    }

    public function testUpdateRejectsInvalidRegex(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCashJournalUser());
        $rule = $this->createSplitRule(withRegexSplit: true);
        $ruleId = (int) $rule->getId();

        $crawler = $client->request('GET', '/journal/bank-import/rules/'.$ruleId.'/edit');
        $form = $crawler->filter('form')->form();
        $form['bank_import_rule[action][splits][0][pattern]'] = 'Zinsen (';
        $crawler = $client->submit($form);

        // An invalid form re-renders the editor with 422 instead of saving.
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertGreaterThan(0, $crawler->filter('.invalid-feedback')->count());
        self::assertSame(
            'Zinsen\s+([\d,]+)',
            $this->reloadRule($ruleId)->getAction()['splits'][0]['pattern'],
            'an unusable pattern must not reach the database',
        );
    }

    public function testUpdateRejectsEmptyConditionValue(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCashJournalUser());
        $rule = $this->createSplitRule();
        $ruleId = (int) $rule->getId();

        $crawler = $client->request('GET', '/journal/bank-import/rules/'.$ruleId.'/edit');
        $form = $crawler->filter('form')->form();
        $form['bank_import_rule[conditions][0][value]'] = '';
        $client->submit($form);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('Stadtwerk Münchn', $this->reloadRule($ruleId)->getConditions()[0]['value']);
    }

    public function testUpdateKeepsTaxRateThatIsNoLongerValid(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCashJournalUser());
        $expiredTaxRate = $this->createExpiredTaxRate();
        $rule = $this->createAssignRule($expiredTaxRate);
        $ruleId = (int) $rule->getId();

        $crawler = $client->request('GET', '/journal/bank-import/rules/'.$ruleId.'/edit');
        $form = $crawler->filter('form')->form();
        $form['bank_import_rule[action][remarkTemplate]'] = 'Abschlag {counterparty}';
        $client->submit($form);

        self::assertResponseRedirects();

        $action = $this->reloadRule($ruleId)->getAction();
        self::assertSame('Abschlag {counterparty}', $action['remarkTemplate']);
        self::assertSame(
            (int) $expiredTaxRate->getId(),
            $action['taxRateId'],
            'a tax rate outside the current validity must not be dropped by an unrelated edit',
        );
    }

    private function createSplitRule(bool $withRegexSplit = false): BankImportRule
    {
        $bankAccount = $this->createBankAccount();
        $interest = $this->createExpenseAccount('Regel Zinsen');
        $fees = $this->createExpenseAccount('Regel Entgelte');

        $firstSplit = [
            'debitAccountId' => (int) $interest->getId(),
            'creditAccountId' => (int) $bankAccount->getId(),
            'taxRateId' => null,
            'remarkTemplate' => 'Zinsen',
        ];
        $firstSplit += $withRegexSplit
            ? ['amountSource' => 'purpose_regex', 'pattern' => 'Zinsen\s+([\d,]+)']
            : ['amountSource' => 'purpose_marker', 'marker' => 'Zinsn'];

        return $this->persistRule([
            ['field' => 'counterpartyName', 'operator' => 'contains', 'value' => 'Stadtwerk Münchn'],
            ['field' => 'direction', 'operator' => 'equals', 'value' => 'out'],
        ], [
            'mode' => BankImportRule::ACTION_MODE_SPLIT,
            'splits' => [
                $firstSplit,
                [
                    'debitAccountId' => (int) $fees->getId(),
                    'creditAccountId' => (int) $bankAccount->getId(),
                    'taxRateId' => null,
                    'remarkTemplate' => 'Entgelte',
                    'remainder' => true,
                ],
            ],
            'invoiceNumberExtraction' => ['mode' => 'marker', 'marker' => 'Rechnung'],
        ]);
    }

    private function createAssignRule(TaxRate $taxRate): BankImportRule
    {
        $bankAccount = $this->createBankAccount();
        $expense = $this->createExpenseAccount('Regel Aufwand');

        return $this->persistRule([
            ['field' => 'counterpartyName', 'operator' => 'contains', 'value' => 'Stadtwerk Münchn'],
        ], [
            'mode' => BankImportRule::ACTION_MODE_ASSIGN,
            'debitAccountId' => (int) $expense->getId(),
            'creditAccountId' => (int) $bankAccount->getId(),
            'taxRateId' => (int) $taxRate->getId(),
            'remarkTemplate' => 'Abschlag',
            'invoiceNumberExtraction' => ['mode' => 'none'],
        ]);
    }

    /**
     * @param list<array{field: string, operator: string, value: mixed}> $conditions
     * @param array<string, mixed>                                      $action
     */
    private function persistRule(array $conditions, array $action): BankImportRule
    {
        $em = $this->getEntityManager();
        foreach ($em->getRepository(BankImportRule::class)->findBy(['name' => self::RULE_NAME]) as $existing) {
            $em->remove($existing);
        }
        $em->flush();

        $rule = new BankImportRule();
        $rule->setName(self::RULE_NAME);
        $rule->setPriority(80);
        $rule->setIsEnabled(true);
        $rule->setConditions($conditions);
        $rule->setAction($action);

        $em->persist($rule);
        $em->flush();

        return $rule;
    }

    private function reloadRule(int $id): BankImportRule
    {
        $em = $this->getEntityManager();
        $em->clear();
        $rule = $em->getRepository(BankImportRule::class)->find($id);
        self::assertInstanceOf(BankImportRule::class, $rule);

        return $rule;
    }

    private function createBankAccount(): AccountingAccount
    {
        $account = new AccountingAccount();
        $account->setAccountNumber((string) random_int(900000, 999999));
        $account->setName('Regel Testbankkonto');
        $account->setType(AccountingAccount::TYPE_ASSET);
        $account->setIsBankAccount(true);
        $account->setIban('DE00REGELTEST00000001');

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

    private function createExpiredTaxRate(): TaxRate
    {
        $taxRate = new TaxRate();
        $taxRate->setName('Regel Alt-Steuersatz');
        $taxRate->setRate('16.00');
        $taxRate->setValidTo(new \DateTime('-1 day'));

        $em = $this->getEntityManager();
        $em->persist($taxRate);
        $em->flush();

        return $taxRate;
    }

    private function getEntityManager(): EntityManagerInterface
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function createCashJournalUser(): User
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();
        $role = $em->getRepository(Role::class)->findOneBy(['role' => 'ROLE_CASHJOURNAL']);
        self::assertNotNull($role, 'Role ROLE_CASHJOURNAL must exist in database.');

        $user = new User();
        $user->setUsername('test_'.bin2hex(random_bytes(6)));
        $user->setFirstname('Test');
        $user->setLastname('User');
        $user->setEmail(sprintf('test+%s@example.com', bin2hex(random_bytes(4))));
        $user->setActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'ChangeMe123!'));
        $user->setRoleEntities([$role]);

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
