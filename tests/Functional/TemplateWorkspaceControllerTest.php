<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enum\PaymentMeansCode;
use App\Entity\Template;
use App\Entity\TemplateType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TemplateWorkspaceControllerTest extends WebTestCase
{
    public function testInvoiceTemplateWorkspacePageLoads(): void
    {
        $client = self::createClient();
        $client->loginUser($this->getAdminUser(), 'main');

        $template = $this->createTemplate('TEMPLATE_INVOICE_PDF', '[[ invoice.number ]]');

        $client->request('GET', '/settings/templates/'.$template->getId().'/edit-page');

        self::assertResponseStatusCodeSame(200);
    }

    public function testReservationEmailTemplateWorkspacePageLoads(): void
    {
        $client = self::createClient();
        $client->loginUser($this->getAdminUser(), 'main');

        $template = $this->createTemplate('TEMPLATE_RESERVATION_EMAIL', '[[ reservation1.booker.lastname ]]');

        $client->request('GET', '/settings/templates/'.$template->getId().'/edit-page');

        self::assertResponseStatusCodeSame(200);
    }

    public function testSecondInvoiceTemplateForTheSamePaymentMeansIsRejected(): void
    {
        $client = self::createClient();
        $client->loginUser($this->getAdminUser(), 'main');

        $cash = $this->createTemplate('TEMPLATE_INVOICE_PDF', '[[ invoice.number ]]');
        $cash->setPaymentMeans(PaymentMeansCode::CASH);
        $template = $this->createTemplate('TEMPLATE_INVOICE_PDF', '[[ invoice.number ]]');
        $template->setName('Second cash template');
        self::getContainer()->get('doctrine')->getManager()->flush();

        $id = $template->getId();
        $crawler = $client->request('GET', '/settings/templates/'.$id.'/edit-page');
        $client->request('POST', '/settings/templates/'.$id.'/edit', [
            '_csrf_token' => $crawler->filter('input[name="_csrf_token"]')->last()->attr('value'),
            'type-'.$id => (string) $template->getTemplateType()->getId(),
            'name-'.$id => 'Second cash template',
            'text-'.$id => '[[ invoice.number ]]',
            'paymentMeans-'.$id => (string) PaymentMeansCode::CASH->value,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-warning');
        self::assertStringContainsString($cash->getName(), (string) $client->getResponse()->getContent());

        $em = self::getContainer()->get('doctrine')->getManager();
        $em->clear();
        self::assertNull($em->getRepository(Template::class)->find($id)?->getPaymentMeans());
    }

    public function testEmbeddedInvoiceTemplateIsSavedWithoutPaymentMeans(): void
    {
        $client = self::createClient();
        $client->loginUser($this->getAdminUser(), 'main');

        $template = $this->createTemplate('TEMPLATE_INVOICE_PDF', '[[ invoice.number ]]');
        $id = $template->getId();

        $crawler = $client->request('GET', '/settings/templates/'.$id.'/edit-page');
        $client->request('POST', '/settings/templates/'.$id.'/edit', [
            '_csrf_token' => $crawler->filter('input[name="_csrf_token"]')->last()->attr('value'),
            'type-'.$id => (string) $template->getTemplateType()->getId(),
            'name-'.$id => 'Embedded block',
            'text-'.$id => '[[ invoice.number ]]',
            'hidden-'.$id => 'on',
            'paymentMeans-'.$id => (string) PaymentMeansCode::CASH->value,
        ]);

        self::assertResponseIsSuccessful();

        $em = self::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $saved = $em->getRepository(Template::class)->find($id);
        self::assertTrue($saved->isHidden());
        self::assertNull($saved->getPaymentMeans());
    }

    public function testAnEmptyNameLeavesTheStoredTemplateUnchanged(): void
    {
        $client = self::createClient();
        $client->loginUser($this->getAdminUser(), 'main');
        $template = $this->createTemplate('TEMPLATE_RESERVATION_EMAIL', 'Original template text');
        $id = $template->getId();
        $name = $template->getName();
        $crawler = $client->request('GET', '/settings/templates/'.$id.'/edit-page');

        $client->request('POST', '/settings/templates/'.$id.'/edit', [
            '_csrf_token' => $crawler->filter('input[name="_csrf_token"]')->last()->attr('value'),
            'type-'.$id => (string) $template->getTemplateType()->getId(),
            'name-'.$id => '',
            'text-'.$id => 'Rejected template text',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-warning');
        $em = self::getContainer()->get('doctrine')->getManager();
        // The rejected template must remain unchanged even if the manager is flushed later.
        $em->flush();
        $em->clear();
        $saved = $em->getRepository(Template::class)->find($id);
        self::assertInstanceOf(Template::class, $saved);
        self::assertSame($name, $saved->getName());
        self::assertSame('Original template text', $saved->getText());
    }

    private function createTemplate(string $typeName, string $text): Template
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine')->getManager();

        $type = $em->getRepository(TemplateType::class)->findOneBy(['name' => $typeName]);
        if (!$type instanceof TemplateType) {
            self::fail(sprintf('TemplateType "%s" not found in test database.', $typeName));
        }

        $template = new Template();
        $template->setName('Preview '.$typeName);
        $template->setTemplateType($type);
        $template->setText($text);
        $template->setIsDefault(false);
        $em->persist($template);
        $em->flush();

        return $template;
    }

    private function getAdminUser(): User
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine')->getManager();
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'test-admin']);

        if (!$user instanceof User) {
            self::fail('Admin user not found in test database.');
        }

        return $user;
    }
}
