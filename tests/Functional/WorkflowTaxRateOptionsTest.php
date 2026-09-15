<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AccountingSettings;
use App\Entity\Role;
use App\Entity\TaxRate;
use App\Entity\User;
use App\Entity\Workflow;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The tax rates a workflow action offers to book with.
 *
 * They come from the same endpoint the workflow form fills its selects from, so
 * what is checked here is what an administrator actually sees.
 */
final class WorkflowTaxRateOptionsTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testOnlyRatesOfTheActiveChartApply(): void
    {
        $client = static::createClient();
        $client->loginUser($this->adminUser());
        $this->activatePreset(AccountingSettings::PRESET_SKR03);

        $own = $this->createTaxRate('Eigener Satz', AccountingSettings::PRESET_SKR03);
        $foreign = $this->createTaxRate('Fremder Kontenrahmen', AccountingSettings::PRESET_SKR04);
        $expired = $this->createTaxRate('Abgelaufen', AccountingSettings::PRESET_SKR03, new \DateTime('-1 year'));
        $future = $this->createTaxRate('Noch nicht gültig', AccountingSettings::PRESET_SKR03, null, new \DateTime('+1 year'));

        $options = $this->taxRateOptions($client);

        self::assertContains((string) $own->getId(), $options);
        self::assertNotContains((string) $foreign->getId(), $options, 'a rate of another chart of accounts was offered');
        self::assertNotContains((string) $expired->getId(), $options, 'an expired rate was offered');
        self::assertNotContains((string) $future->getId(), $options, 'a rate that does not apply yet was offered');
    }

    public function testARateAWorkflowAlreadyBooksWithStaysOnTheList(): void
    {
        // Narrowing the list must not quietly empty a select: the form writes
        // back whatever the select holds, so a configured rate that has dropped
        // out would be lost the next time somebody opens that workflow.
        $client = static::createClient();
        $client->loginUser($this->adminUser());
        $this->activatePreset(AccountingSettings::PRESET_SKR03);

        $expired = $this->createTaxRate('Abgelaufen, aber konfiguriert', AccountingSettings::PRESET_SKR03, new \DateTime('-1 year'));

        self::assertNotContains((string) $expired->getId(), $this->taxRateOptions($client));

        $this->createWorkflowBookingWith($expired);

        self::assertContains((string) $expired->getId(), $this->taxRateOptions($client));
    }

    /**
     * The values the percentage action's tax rate select offers.
     *
     * @return string[]
     */
    private function taxRateOptions(KernelBrowser $client): array
    {
        $client->request('POST', '/settings/workflows/compatible-options', ['triggerType' => 'invoice.status_changed']);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);

        foreach ($payload['actions'] ?? [] as $action) {
            if ('create_percentage_entry' !== $action['type']) {
                continue;
            }

            foreach ($action['configSchema'] as $field) {
                if ('taxRateId' === $field['key']) {
                    self::assertSame('select', $field['type'], 'the tax rate field was not resolved into a select');

                    return array_column($field['options'], 'value');
                }
            }
        }

        self::fail('the percentage action offers no tax rate field');
    }

    private function activatePreset(string $preset): void
    {
        $em = $this->em();
        $settings = $em->getRepository(AccountingSettings::class)->findOneBy([]);

        if (!$settings instanceof AccountingSettings) {
            $settings = new AccountingSettings();
            $em->persist($settings);
        }

        $settings->setChartPreset($preset);
        $em->flush();
    }

    private function createTaxRate(
        string $name,
        ?string $preset,
        ?\DateTime $validTo = null,
        ?\DateTime $validFrom = null,
    ): TaxRate {
        $rate = new TaxRate();
        $rate->setName($name.' '.bin2hex(random_bytes(3)));
        $rate->setRate('19.00');
        $rate->setChartPreset($preset);
        $rate->setValidFrom($validFrom);
        $rate->setValidTo($validTo);

        $em = $this->em();
        $em->persist($rate);
        $em->flush();

        return $rate;
    }

    private function createWorkflowBookingWith(TaxRate $rate): Workflow
    {
        $workflow = new Workflow();
        $workflow->setName('Portalgebühr '.bin2hex(random_bytes(3)));
        $workflow->setTriggerType('invoice.status_changed');
        $workflow->setActionType('create_percentage_entry');
        $workflow->setActionConfig(['percent' => '12', 'taxRateId' => (string) $rate->getId()]);

        $em = $this->em();
        $em->persist($workflow);
        $em->flush();

        return $workflow;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(ManagerRegistry::class)->getManager();
    }

    private function adminUser(): User
    {
        $em = $this->em();
        $passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setUsername('test_'.bin2hex(random_bytes(6)));
        $user->setFirstname('Test');
        $user->setLastname('Admin');
        $user->setEmail(sprintf('test+%s@example.com', bin2hex(random_bytes(4))));
        $user->setActive(true);
        $user->setPassword($passwordHasher->hashPassword($user, 'ChangeMe123!'));

        $role = $em->getRepository(Role::class)->findOneBy(['role' => 'ROLE_ADMIN']);
        $user->setRoleEntities(null !== $role ? [$role] : []);

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
