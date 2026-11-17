<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Invoice;
use App\Entity\Role;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The payment status in the invoice dialog saves itself once picked; the dialog shows a tick
 * only for a successful answer, so every refusal has to come back as an error.
 */
final class InvoiceStatusUpdateTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testTheDialogSavesThePickedStatusWithoutAButton(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $invoice = $this->createInvoice();

        $crawler = $client->request('GET', '/invoices/get/'.$invoice->getId().'/');

        self::assertResponseIsSuccessful();
        $select = $crawler->filter('#invoice-form-status select[name="invoice-status"]');
        self::assertSame('/invoices/update/'.$invoice->getId().'/status', $select->attr('data-url'));
        self::assertSame('1', $select->attr('data-saved-value'));
        self::assertCount(1, $crawler->filter('#invoice-form-status .save-state[role="status"]'));
        self::assertCount(0, $crawler->filter('#save-status'));
    }

    public function testAPickedStatusIsStored(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $invoice = $this->createInvoice();

        $this->postStatus($client, $invoice, '2', $this->tokenFromDialog($client, $invoice));

        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->storedStatus($invoice));
    }

    public function testWithoutAValidTokenTheStatusStaysAndTheAnswerIsAnError(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $invoice = $this->createInvoice();
        $this->tokenFromDialog($client, $invoice);

        $this->postStatus($client, $invoice, '2', 'not-the-token');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->storedStatus($invoice));
    }

    public function testAnUnknownStatusIsRefused(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $invoice = $this->createInvoice();

        $this->postStatus($client, $invoice, '9', $this->tokenFromDialog($client, $invoice));

        self::assertResponseStatusCodeSame(400);
        self::assertSame(1, $this->storedStatus($invoice));
    }

    private function tokenFromDialog(KernelBrowser $client, Invoice $invoice): string
    {
        $crawler = $client->request('GET', '/invoices/get/'.$invoice->getId().'/');

        return (string) $crawler->filter('#invoice-form-status input[name="_csrf_token"]')->attr('value');
    }

    private function postStatus(KernelBrowser $client, Invoice $invoice, string $status, string $token): void
    {
        $client->request('POST', '/invoices/update/'.$invoice->getId().'/status', [
            'invoice-status' => $status,
            '_csrf_token' => $token,
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    }

    private function storedStatus(Invoice $invoice): int
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $em->clear();

        return (int) $em->getRepository(Invoice::class)->find($invoice->getId())?->getStatus();
    }

    private function createInvoice(): Invoice
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();

        $invoice = new Invoice();
        $invoice->setNumber('STATUS-'.bin2hex(random_bytes(4)));
        $invoice->setDate(new \DateTime('2026-09-01'));
        $invoice->setStatus(1);
        $invoice->setLastname('Status');
        $em->persist($invoice);
        $em->flush();

        return $invoice;
    }

    private function createInvoiceUser(): User
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();

        $user = new User();
        $user->setUsername('status_'.bin2hex(random_bytes(6)));
        $user->setFirstname('Test');
        $user->setLastname('Invoices');
        $user->setEmail(sprintf('status+%s@example.com', bin2hex(random_bytes(4))));
        $user->setActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'ChangeMe123!'));
        $user->setLastSeenVersion('99.99.99');

        $role = $em->getRepository(Role::class)->findOneBy(['role' => 'ROLE_INVOICES']);
        $user->setRoleEntities(null !== $role ? [$role] : []);

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
