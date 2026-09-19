<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Invoice;
use App\Entity\InvoicePosition;
use App\Entity\Role;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A flat price is billed once, so the misc position form locks its quantity to 1. Entering the
 * number of nights there instead made line total, invoice sum and e-invoice disagree.
 */
final class InvoiceMiscPositionFormTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testFlatPriceLocksQuantityToOne(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $position = $this->createPositionOnNewInvoice(true, 7);

        $crawler = $client->request('GET', $this->editUrl($position));

        self::assertResponseIsSuccessful();
        $amount = $crawler->filter('#invoice_misc_position_amount');
        self::assertSame('1', $amount->attr('value'));
        self::assertNotNull($amount->attr('readonly'));
        self::assertStringNotContainsString('d-none', (string) $crawler->filter('#invoice_misc_position_amount_help')->attr('class'));
        self::assertSame('#invoice_misc_position_amount', $crawler->filter('#invoice_misc_position_isFlatPrice')->attr('data-amount-selector'));
        self::assertNotNull($crawler->filter('#invoice_misc_position_isPerRoom')->attr('disabled'));
    }

    public function testRegularPriceKeepsQuantityEditable(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $position = $this->createPositionOnNewInvoice(false, 3);

        $crawler = $client->request('GET', $this->editUrl($position));

        self::assertResponseIsSuccessful();
        $amount = $crawler->filter('#invoice_misc_position_amount');
        self::assertSame('3', $amount->attr('value'));
        self::assertNull($amount->attr('readonly'));
        self::assertStringContainsString('d-none', (string) $crawler->filter('#invoice_misc_position_amount_help')->attr('class'));
        self::assertNull($crawler->filter('#invoice_misc_position_isPerRoom')->attr('disabled'));
    }

    public function testSubmittedQuantityIsIgnoredForFlatPrice(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $position = $this->createPositionOnNewInvoice(false, 1);

        // bypasses the locked field, as a client without JavaScript would
        $crawler = $client->request('GET', $this->editUrl($position));
        $form = $crawler->filter('form[name="invoice_misc_position"]')->form();
        $form['invoice_misc_position[amount]'] = '7';
        $flatPrice = $form['invoice_misc_position[isFlatPrice]'];
        self::assertInstanceOf(ChoiceFormField::class, $flatPrice);
        $flatPrice->tick();
        $client->submit($form);

        self::assertResponseIsSuccessful();
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $stored = $em->getRepository(InvoicePosition::class)->find($position->getId());
        self::assertTrue($stored->getIsFlatPrice());
        self::assertSame(1, $stored->getAmount());
        self::assertSame(20.0, $stored->getTotalPriceRaw());
    }

    private function editUrl(InvoicePosition $position): string
    {
        return sprintf('/invoices/%d/edit/miscellaneous/%d/edit', $position->getInvoice()->getId(), $position->getId());
    }

    private function createPositionOnNewInvoice(bool $isFlatPrice, int $amount): InvoicePosition
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();

        $invoice = new Invoice();
        $invoice->setNumber('FLAT-'.bin2hex(random_bytes(4)));
        $invoice->setDate(new \DateTime('2026-09-01'));
        $invoice->setStatus(1);
        $invoice->setLastname('Flat');

        $position = new InvoicePosition();
        $position->setDescription('Getränkepauschale');
        $position->setAmount($amount);
        $position->setPrice('20.00');
        $position->setVat(19);
        $position->setIsFlatPrice($isFlatPrice);
        $position->setInvoice($invoice);
        $invoice->addPosition($position);

        $em->persist($invoice);
        $em->persist($position);
        $em->flush();

        return $position;
    }

    private function createInvoiceUser(): User
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();

        $user = new User();
        $user->setUsername('flat_'.bin2hex(random_bytes(6)));
        $user->setFirstname('Test');
        $user->setLastname('Invoices');
        $user->setEmail(sprintf('flat+%s@example.com', bin2hex(random_bytes(4))));
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
