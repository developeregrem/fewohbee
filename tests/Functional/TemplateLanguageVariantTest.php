<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Template;
use App\Entity\TemplateType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Language variants of templates: created from the list, edited like any template, deleted first. */
final class TemplateLanguageVariantTest extends WebTestCase
{
    /** @var list<int> */
    private array $templateIds = [];

    public function testVariantIsCreatedFromTheListAndOpensInTheEditor(): void
    {
        $client = $this->authenticatedClient();
        $base = $this->createTemplate('Buchungsbestätigung');

        $this->createVariant($client, $base, 'en');

        $variant = $this->reload($base)->getVariant('en');
        self::assertInstanceOf(Template::class, $variant);
        $this->templateIds[] = (int) $variant->getId();
        self::assertResponseRedirects('/settings/templates/'.$variant->getId().'/edit-page');
        self::assertSame('Buchungsbestätigung (Englisch)', $variant->getName());
        self::assertSame($base->getText(), $variant->getText());

        $crawler = $client->request('GET', '/settings/templates/'.$variant->getId().'/edit-page');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[name="default-'.$variant->getId().'"]'), 'A variant is never the default.');
        self::assertStringContainsString('locale=en', (string) $crawler->filter('form[data-templates-snippets-url]')->attr('data-templates-snippets-url'));

        $client->request('GET', '/settings/templates/');
        self::assertStringContainsString('Buchungsbestätigung (Englisch)', (string) $client->getResponse()->getContent());
    }

    public function testASecondVariantInTheSameLanguageIsRefused(): void
    {
        $client = $this->authenticatedClient();
        $base = $this->createTemplate('Buchungsbestätigung');
        $this->createVariant($client, $base, 'en');
        $this->templateIds[] = (int) $this->reload($base)->getVariant('en')?->getId();

        $this->createVariant($client, $base, 'en');

        self::assertResponseRedirects('/settings/templates/');
        self::assertCount(1, $this->reload($base)->getVariants());
    }

    public function testALanguageNotOfferedToGuestsIsRefused(): void
    {
        $client = $this->authenticatedClient();
        $base = $this->createTemplate('Buchungsbestätigung');

        $this->createVariant($client, $base, 'xx');

        self::assertResponseRedirects('/settings/templates/');
        self::assertCount(0, $this->reload($base)->getVariants());
    }

    public function testATemplateWithVariantsIsNotDeleted(): void
    {
        $client = $this->authenticatedClient();
        $base = $this->createTemplate('Buchungsbestätigung');
        $this->createVariant($client, $base, 'en');
        $this->templateIds[] = (int) $this->reload($base)->getVariant('en')?->getId();

        $crawler = $client->request('GET', '/settings/templates/');
        $popover = (string) $crawler->filter('i[data-popover="delete"]')->reduce(
            static fn ($node): bool => str_contains((string) $node->attr('data-bs-content'), '/settings/templates/'.$base->getId().'/delete'),
        )->attr('data-bs-content');
        preg_match('/name="_token" value="([^"]+)"/', $popover, $token);
        $client->request('DELETE', '/settings/templates/'.$base->getId().'/delete', ['_token' => $token[1] ?? '']);

        self::assertNotNull($this->reload($base)->getId());
    }

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $connection = $this->em()->getConnection();
            // Variants first: they point at the template they translate.
            foreach (array_reverse(array_filter($this->templateIds)) as $id) {
                $connection->executeStatement('DELETE FROM templates WHERE id = ?', [$id]);
            }
        }
        parent::tearDown();
    }

    private function createVariant(KernelBrowser $client, Template $base, string $locale): void
    {
        $crawler = $client->request('GET', '/settings/templates/');
        $form = $crawler->filter('form[action="/settings/templates/'.$base->getId().'/variant"]');
        $token = $form->count() > 0 ? (string) $form->filter('input[name="_token"]')->attr('value') : '';
        $client->request('POST', '/settings/templates/'.$base->getId().'/variant', ['_token' => $token, 'locale' => $locale]);
    }

    private function createTemplate(string $name): Template
    {
        $em = $this->em();
        $type = $em->getRepository(TemplateType::class)->findOneBy(['name' => 'TEMPLATE_RESERVATION_EMAIL']) ?? throw new \RuntimeException('Template type missing.');

        $template = new Template();
        $template->setName($name)->setTemplateType($type)->setText('<p>Hallo [[ reservation1.booker.lastname ]]</p>')->setIsDefault(false);
        $em->persist($template);
        $em->flush();
        $this->templateIds[] = (int) $template->getId();

        return $template;
    }

    private function reload(Template $template): Template
    {
        $this->em()->clear();

        return $this->em()->getRepository(Template::class)->find($template->getId()) ?? throw new \RuntimeException('Template gone.');
    }

    private function authenticatedClient(): KernelBrowser
    {
        $client = self::createClient();
        $admin = $this->em()->getRepository(User::class)->findOneBy(['username' => 'test-admin']) ?? throw new \RuntimeException('Prepared test admin missing.');
        $client->loginUser($admin, 'main');

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
