<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Role;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The two endpoints the workflow form talks to: the schema that builds its fields, and
 * the dry run that lists the records a rule currently covers.
 */
final class WorkflowFormEndpointsTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testTimeBasedTriggerOffersWeekdayAndHourOptions(): void
    {
        $schema = $this->fetchOptions('invoice.days_after_date')['triggerConfigSchema'] ?? [];
        $fields = array_column($schema, null, 'key');

        self::assertArrayHasKey('runOnDays', $fields);
        self::assertSame('select', $fields['runOnDays']['type']);
        self::assertSame(
            ['daily', 'mon_fri', 'mon_sat'],
            array_column($fields['runOnDays']['options'], 'value')
        );
        // Labels are resolved, not raw translation keys.
        self::assertStringNotContainsString('workflow.trigger', $fields['runOnDays']['label']);

        self::assertArrayHasKey('runAtHour', $fields);
        self::assertSame('select', $fields['runAtHour']['type'],
            'The hour_select pseudo type must be expanded before it reaches the form.');
        self::assertCount(24, $fields['runAtHour']['options']);
        self::assertSame(['value' => 0, 'label' => '00:00'], $fields['runAtHour']['options'][0]);
        self::assertSame(['value' => 23, 'label' => '23:00'], $fields['runAtHour']['options'][23]);
    }

    public function testEventDrivenTriggerHasNoScheduleFields(): void
    {
        $schema = $this->fetchOptions('invoice.created')['triggerConfigSchema'] ?? [];

        self::assertNotContains('runOnDays', array_column($schema, 'key'));
    }

    public function testInvoiceEmailActionOffersInvoicePdfTemplates(): void
    {
        $actions = $this->fetchOptions('invoice.days_after_date')['actions'] ?? [];

        $action = $this->findAction($actions, 'send_invoice_email');

        $fields = array_column($action['configSchema'], null, 'key');
        self::assertArrayHasKey('invoicePdfTemplateId', $fields);
        self::assertSame('select', $fields['invoicePdfTemplateId']['type']);

        // The entity of this action maps to the invoice *email* template type. The PDF
        // field must not be narrowed down by that mapping, or it ends up empty.
        self::assertGreaterThan(
            1,
            count($fields['invoicePdfTemplateId']['options']),
            'Besides the "no choice" entry at least the default invoice PDF template must be selectable.'
        );
        // The first entry stays the placeholder that means "use the default template".
        self::assertSame(0, $fields['invoicePdfTemplateId']['options'][0]['value']);

        // The email template field keeps its own, unrelated list.
        self::assertArrayHasKey('templateId', $fields);
    }

    public function testTemplateEmailActionOffersATemplateForAttachedInvoices(): void
    {
        $action = $this->findAction($this->fetchOptions('invoice.days_after_date')['actions'] ?? [], 'send_template_email');
        $fields = array_column($action['configSchema'], null, 'key');

        self::assertArrayHasKey('invoicePdfTemplateId', $fields,
            'Invoices can be attached here, so their layout must be selectable too.');
        self::assertSame('select', $fields['invoicePdfTemplateId']['type']);
        self::assertGreaterThan(1, count($fields['invoicePdfTemplateId']['options']));
        self::assertSame('attachments', $fields['invoicePdfTemplateId']['showIfAny'],
            'The field only makes sense once something is attached.');
        self::assertSame(
            ['invoice_pdf', 'invoice_pdf_open'],
            $fields['invoicePdfTemplateId']['showIfAnyItemTypes'],
            'Every other attachment is picked as a template already, so only invoices may reveal the field.'
        );

        // The mail template of this action is unrelated and keeps its own list.
        self::assertNotContains(
            'TEMPLATE_INVOICE_PDF',
            array_column($fields['templateId']['options'], 'label')
        );
    }

    public function testDryRunIgnoresTheScheduleAndListsTodaysMatchesOnly(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($this->createAdmin());

        $due = $this->insertInvoice('WF-PREVIEW-DUE', new \DateTimeImmutable('-1 day'));
        $notDue = $this->insertInvoice('WF-PREVIEW-NOT-DUE', new \DateTimeImmutable('today'));

        try {
            // Whatever the weekday window says, the dry run answers the same question:
            // which records does "1 day after the invoice date" cover today?
            foreach (['daily', 'mon_fri', 'mon_sat'] as $preset) {
                $ids = $this->previewIds($client, ['days' => 1, 'runOnDays' => $preset, 'runAtHour' => 9]);

                self::assertContains($due, $ids, sprintf('[%s] Yesterday\'s invoice is due today.', $preset));
                self::assertNotContains($notDue, $ids,
                    sprintf('[%s] Today\'s invoice is only due tomorrow and must not be listed.', $preset));
            }
        } finally {
            $this->removeInvoices([$due, $notDue]);
        }
    }

    /**
     * @param array<string, mixed> $triggerConfig
     *
     * @return int[]
     */
    private function previewIds(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, array $triggerConfig): array
    {
        $client->request('POST', '/settings/workflows/preview', [
            'triggerType' => 'invoice.days_after_date',
            'triggerConfig' => json_encode($triggerConfig, JSON_THROW_ON_ERROR),
            'conditions' => '[]',
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return array_column($payload['entities'] ?? [], 'id');
    }

    private function insertInvoice(string $number, \DateTimeImmutable $date): int
    {
        $conn = static::getContainer()->get(ManagerRegistry::class)->getManager()->getConnection();
        $conn->executeStatement(
            'INSERT INTO invoices (number, date, status) VALUES (:number, :date, 1)',
            ['number' => $number, 'date' => $date->format('Y-m-d')]
        );

        return (int) $conn->lastInsertId();
    }

    /** @param int[] $ids */
    private function removeInvoices(array $ids): void
    {
        $conn = static::getContainer()->get(ManagerRegistry::class)->getManager()->getConnection();
        foreach ($ids as $id) {
            $conn->executeStatement('DELETE FROM invoices WHERE id = :id', ['id' => $id]);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $actions
     *
     * @return array<string, mixed>
     */
    private function findAction(array $actions, string $type): array
    {
        foreach ($actions as $candidate) {
            if ($type === ($candidate['type'] ?? '')) {
                return $candidate;
            }
        }

        self::fail(sprintf('Action "%s" is not offered for this trigger.', $type));
    }

    /** @return array<string, mixed> */
    private function fetchOptions(string $triggerType): array
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($this->createAdmin());

        $client->request('POST', '/settings/workflows/compatible-options', ['triggerType' => $triggerType]);

        self::assertResponseIsSuccessful();

        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function createAdmin(): User
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();
        $role = $em->getRepository(Role::class)->findOneBy(['role' => 'ROLE_ADMIN']);
        self::assertNotNull($role, 'Role ROLE_ADMIN must exist in database.');

        $user = new User();
        $user->setUsername('test_'.bin2hex(random_bytes(6)));
        $user->setFirstname('Test');
        $user->setLastname('Admin');
        $user->setEmail(sprintf('test+%s@example.com', bin2hex(random_bytes(4))));
        $user->setActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'ChangeMe123!'));
        $user->setRoleEntities([$role]);

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
