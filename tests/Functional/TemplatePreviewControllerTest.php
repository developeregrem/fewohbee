<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Template;
use App\Entity\TemplateType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TemplatePreviewControllerTest extends WebTestCase
{
    public function testInvoicePreviewPageLoads(): void
    {
        $client = self::createClient();
        $client->loginUser($this->getAdminUser(), 'main');

        $template = $this->createTemplate('TEMPLATE_INVOICE_PDF', 'App\\Service\\InvoiceService', '[[ invoice.number ]]');

        $client->request('GET', '/settings/templates/'.$template->getId().'/preview');

        self::assertResponseStatusCodeSame(200);
    }

    public function testReservationEmailPreviewPageLoads(): void
    {
        $client = self::createClient();
        $client->loginUser($this->getAdminUser(), 'main');

        $template = $this->createTemplate('TEMPLATE_RESERVATION_EMAIL', 'App\\Service\\ReservationService', '[[ reservation1.booker.lastname ]]');

        $client->request('GET', '/settings/templates/'.$template->getId().'/preview');

        self::assertResponseStatusCodeSame(200);
    }

    private function createTemplate(string $typeName, string $service, string $text): Template
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine')->getManager();

        $type = new TemplateType();
        $type->setName($typeName);
        $type->setIcon('fa-file');
        $type->setService($service);
        $em->persist($type);

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
