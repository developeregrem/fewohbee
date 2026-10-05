<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class CustomFontControllerTest extends WebTestCase
{
    /** @var list<string> */
    private array $fontDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->fontDirectories as $fontDirectory) {
            if (is_dir($fontDirectory)) {
                foreach (glob($fontDirectory.'/*') ?: [] as $path) {
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
            }
        }

        parent::tearDown();
    }

    public function testAdminCanUploadFontAndUseProtectedPreviewFile(): void
    {
        $client = self::createClient();
        $client->loginUser($this->getAdminUser(), 'main');
        $cacheDirectory = (string) self::getContainer()->getParameter('kernel.cache_dir');
        $this->fontDirectories = [
            $cacheDirectory.'/uploaded-font-storage',
            $cacheDirectory.'/uploaded-font-cache',
        ];
        foreach ($this->fontDirectories as $fontDirectory) {
            if (is_dir($fontDirectory)) {
                foreach (glob($fontDirectory.'/*') ?: [] as $path) {
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
            }
        }

        $crawler = $client->request('GET', '/settings/templates/');
        self::assertResponseIsSuccessful();
        $fontSettingsButton = $crawler->filter('[data-url="/settings/templates/fonts"]');
        self::assertCount(1, $fontSettingsButton);
        self::assertSame('#modalCenter', $fontSettingsButton->attr('data-bs-target'));

        $crawler = $client->request('GET', '/settings/templates/fonts');
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('#custom-font-upload-form input[name="_csrf_token"]')->attr('value');

        $uploadPath = tempnam(sys_get_temp_dir(), 'fewohbee-font-upload-');
        self::assertIsString($uploadPath);
        copy(dirname(__DIR__, 2).'/vendor/mpdf/mpdf/ttfonts/DejaVuSans.ttf', $uploadPath);

        $client->request('POST', '/settings/templates/fonts', [
            '_csrf_token' => $token,
        ], [
            'fonts' => [new UploadedFile($uploadPath, 'DejaVuSans.ttf', 'font/sfnt', null, true)],
        ]);

        self::assertResponseRedirects('/settings/templates/');
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $client->request('GET', '/settings/templates/fonts');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('DejaVu Sans', (string) $client->getResponse()->getContent());

        $crawler = $client->request('GET', '/settings/templates/new-page');
        self::assertResponseIsSuccessful();
        $workspace = (string) $client->getResponse()->getContent();
        $customFontOptions = $crawler->filter('#entry-form-new')->attr('data-templates-custom-fonts');
        self::assertIsString($customFontOptions);
        $decodedFontOptions = json_decode($customFontOptions, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('DejaVu Sans', $decodedFontOptions[0]['label'] ?? null);
        self::assertMatchesRegularExpression(
            '/^fhbcustom[0-9a-f]{16}, sans-serif$/',
            $decodedFontOptions[0]['value'] ?? '',
        );
        self::assertMatchesRegularExpression('/@font-face\s*\{[^}]*fhbcustom[0-9a-f]{16}/s', $workspace);

        self::assertMatchesRegularExpression('/\/settings\/templates\/fonts\/(fhbcustom[0-9a-f]{16})\/R\?v=/', $workspace);
        preg_match('/\/settings\/templates\/fonts\/(fhbcustom[0-9a-f]{16})\/R\?v=/', $workspace, $matches);
        $alias = $matches[1] ?? '';
        self::assertNotSame('', $alias);

        $client->request('GET', '/settings/templates/fonts/'.$alias.'/R');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'font/ttf');
        $response = $client->getResponse();
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertGreaterThan(1000, $response->getFile()->getSize());
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
