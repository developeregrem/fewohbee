<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Template;
use App\Entity\TemplateType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TemplatesEditorApiTest extends WebTestCase
{
    public function testSchemaEndpointReturnsInvoiceSchema(): void
    {
        $client = self::createClient();
        $client->loginUser($this->getAdminUser(), 'main');

        $type = $this->requireTemplateType('TEMPLATE_INVOICE_PDF');

        $client->request('GET', '/settings/templates/schema/'.$type->getId());

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayHasKey('invoice', $payload);
        self::assertArrayHasKey('vats', $payload);
        self::assertSame('entity', $payload['invoice']['type'] ?? null);
    }

    public function testPreviewRenderReturnsFriendlyErrorPayload(): void
    {
        $client = self::createClient();
        $client->loginUser($this->getAdminUser(), 'main');

        $type = $this->requireTemplateType('TEMPLATE_INVOICE_PDF');
        $template = $this->createTemplate(
            $type,
            'Invoice Error Template',
            '[[ invoice.__missing_property_for_test__ ]]'
        );

        $client->request('POST', '/settings/templates/'.$template->getId().'/preview/render', [
            'previewText' => '[[ invoice.__missing_property_for_test__ ]]',
        ], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);

        self::assertResponseStatusCodeSame(422);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('templates.preview.render.error.generic', $payload['warning'] ?? null);
        self::assertArrayHasKey('warningText', $payload);
        self::assertNotSame('', trim((string) ($payload['warningText'] ?? '')));
    }

    private function requireTemplateType(string $name): TemplateType
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine')->getManager();

        $type = $em->getRepository(TemplateType::class)->findOneBy(['name' => $name]);
        if (!$type instanceof TemplateType) {
            self::fail(sprintf('TemplateType "%s" not found in test database.', $name));
        }

        return $type;
    }

    private function createTemplate(TemplateType $type, string $name, string $text): Template
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine')->getManager();

        $template = new Template();
        $template->setName($name);
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
