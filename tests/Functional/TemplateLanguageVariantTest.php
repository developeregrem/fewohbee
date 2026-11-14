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

    public function testPreviewEmbedsTheVariantInTheLanguageOfTheHost(): void
    {
        $client = $this->authenticatedClient();
        $signature = $this->createGeneralMail('Signatur', '<p>Viele Grüße</p>');
        $this->createGeneralMail('Signatur (Englisch)', '<p>Kind regards</p>', $signature, 'en');
        $include = '<span data-template-id="'.$signature->getId().'" class="template-include" contenteditable="false">Signatur</span>';
        $mail = $this->createGeneralMail('Mail', '<p>Hallo</p>'.$include);
        $mailVariant = $this->createGeneralMail('Mail (Englisch)', '<p>Hello</p>'.$include, $mail, 'en');

        self::assertSame('<p>Hallo</p><p>Viele Grüße</p>', $this->previewHtml($client, $mail));
        self::assertSame('<p>Hello</p><p>Kind regards</p>', $this->previewHtml($client, $mailVariant));
    }

    public function testEmbeddableListOffersAGeneralMailInOtherMailTypesWithoutItsVariants(): void
    {
        $client = $this->authenticatedClient();
        $signature = $this->createGeneralMail('Signatur', '<p>Viele Grüße</p>');
        $this->createGeneralMail('Signatur (Englisch)', '<p>Kind regards</p>', $signature, 'en');

        $names = static function (KernelBrowser $client, int $typeId): array {
            $client->request('GET', '/settings/templates/embeddable/'.$typeId);
            self::assertResponseIsSuccessful();

            return array_column(json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR), 'name');
        };

        $inInvoiceMail = $names($client, $this->templateTypeId('TEMPLATE_INVOICE_EMAIL'));
        self::assertContains('Signatur', $inInvoiceMail);
        self::assertNotContains('Signatur (Englisch)', $inInvoiceMail);
        self::assertNotContains('Signatur', $names($client, $this->templateTypeId('TEMPLATE_INVOICE_PDF')));
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

    private function createGeneralMail(string $name, string $text, ?Template $base = null, ?string $locale = null): Template
    {
        $em = $this->em();
        $type = $em->getRepository(TemplateType::class)->find($this->templateTypeId('TEMPLATE_GENERAL_EMAIL'));

        $template = new Template();
        $template->setName($name)->setTemplateType($type)->setText($text)->setIsDefault(false)->setLocale($locale)->setBaseTemplate($base);
        $base?->getVariants()->add($template);
        $em->persist($template);
        $em->flush();
        $this->templateIds[] = (int) $template->getId();

        return $template;
    }

    private function templateTypeId(string $name): int
    {
        $type = $this->em()->getRepository(TemplateType::class)->findOneBy(['name' => $name]) ?? throw new \RuntimeException('Template type missing.');

        return (int) $type->getId();
    }

    private function previewHtml(KernelBrowser $client, Template $template): string
    {
        $client->request('POST', '/settings/templates/'.$template->getId().'/preview/render', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        return (string) (json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['html'] ?? '');
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
