<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Dto\CustomFontFace;
use App\Exception\CustomFontException;
use App\Service\CustomFontManager;
use App\Service\MpdfService;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\Visibility;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class CustomFontManagerTest extends TestCase
{
    private string $workDirectory;
    private string $fontDirectory;
    private FilesystemOperator $storage;
    private CustomFontManager $manager;

    protected function setUp(): void
    {
        $this->workDirectory = sys_get_temp_dir().'/fewohbee-font-test-'.bin2hex(random_bytes(6));
        $this->fontDirectory = $this->workDirectory.'/fonts';
        mkdir($this->workDirectory.'/uploads', 0775, true);
        $this->storage = new Filesystem(new InMemoryFilesystemAdapter());

        $this->manager = new CustomFontManager(
            $this->storage,
            $this->fontDirectory,
            $this->workDirectory.'/analysis-cache',
            $this->createStub(LoggerInterface::class),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workDirectory);
    }

    public function testUploadGroupsDetectedFacesAndBuildsMpdfConfiguration(): void
    {
        $regular = $this->manager->upload($this->uploadedFont('DejaVuSans.ttf'));
        $boldItalic = $this->manager->upload($this->uploadedFont('DejaVuSans-BoldOblique.ttf'));

        self::assertSame('DejaVu Sans', $regular->name);
        self::assertSame($regular->alias, $boldItalic->alias);

        $families = $this->manager->getFamilies();
        self::assertCount(1, $families);
        self::assertTrue($families[0]->isUsable());
        self::assertArrayHasKey(CustomFontFace::REGULAR, $families[0]->faces);
        self::assertArrayHasKey(CustomFontFace::BOLD_ITALIC, $families[0]->faces);

        $fontData = $this->manager->getMpdfFontData();
        self::assertSame(
            $families[0]->faces[CustomFontFace::REGULAR]->filename,
            $fontData[$families[0]->alias][CustomFontFace::REGULAR] ?? null,
        );
        self::assertSame(0xFF, $fontData[$families[0]->alias]['useOTL'] ?? null);
        self::assertSame(
            Visibility::PRIVATE,
            $this->storage->visibility($families[0]->faces[CustomFontFace::REGULAR]->filename),
        );
    }

    public function testFontWithoutRegularFaceIsListedButNotRegisteredWithMpdf(): void
    {
        $family = $this->manager->upload($this->uploadedFont('DejaVuSans-Bold.ttf'));

        self::assertFalse($family->isUsable());
        self::assertSame([], $this->manager->getMpdfFontData());
    }

    public function testCustomFontIsEmbeddedInGeneratedPdf(): void
    {
        $family = $this->manager->upload($this->uploadedFont('DejaVuSans.ttf'));
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/'));

        $service = new MpdfService(
            $requestStack,
            $this->manager,
            $this->workDirectory.'/mpdf-cache',
            'de',
        );
        $mpdf = $service->getMpdf();
        $mpdf->WriteHTML(sprintf('<p style="font-family: %s, sans-serif">FewohBee ÄÖÜ</p>', $family->alias));
        $pdf = $mpdf->Output('', 'S');

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('DejaVuSans', $pdf);
    }

    public function testDeleteRemovesEveryFaceOfFamily(): void
    {
        $family = $this->manager->upload($this->uploadedFont('DejaVuSans.ttf'));
        $this->manager->upload($this->uploadedFont('DejaVuSans-Oblique.ttf'));

        self::assertTrue($this->manager->deleteFamily($family->alias));
        self::assertSame([], $this->manager->getFamilies());
        self::assertSame([], glob($this->fontDirectory.'/*') ?: []);
        self::assertSame([], iterator_to_array($this->storage->listContents('', false)));
    }

    public function testSharedStorageMakesFontAvailableToAnotherReplica(): void
    {
        $uploaded = $this->manager->upload($this->uploadedFont('DejaVuSans.ttf'));
        $replicaCache = $this->workDirectory.'/replica-fonts';
        $otherReplica = new CustomFontManager(
            $this->storage,
            $replicaCache,
            $this->workDirectory.'/replica-analysis-cache',
            $this->createStub(LoggerInterface::class),
        );

        $discovered = $otherReplica->findFamily($uploaded->alias);

        self::assertNotNull($discovered);
        self::assertSame('DejaVu Sans', $discovered->name);
        self::assertFileExists($replicaCache.'/'.$discovered->faces[CustomFontFace::REGULAR]->filename);
    }

    public function testUploadRejectsUnsupportedExtensionBeforeStoringFile(): void
    {
        $source = $this->workDirectory.'/uploads/font.txt';
        copy($this->sourceFont('DejaVuSans.ttf'), $source);

        $this->expectException(CustomFontException::class);
        $this->expectExceptionMessage('templates.fonts.validation.extension');

        $this->manager->upload(new UploadedFile($source, 'font.txt', 'font/sfnt', null, true));
    }

    public function testUploadRejectsInvalidFontContent(): void
    {
        $source = $this->workDirectory.'/uploads/invalid.ttf';
        file_put_contents($source, str_repeat("\0", 256));

        $this->expectException(CustomFontException::class);
        $this->expectExceptionMessage('templates.fonts.validation.invalid');

        $this->manager->upload(new UploadedFile($source, 'invalid.ttf', 'application/octet-stream', null, true));
    }

    private function uploadedFont(string $filename): UploadedFile
    {
        $source = $this->workDirectory.'/uploads/'.bin2hex(random_bytes(4)).'-'.$filename;
        copy($this->sourceFont($filename), $source);

        return new UploadedFile($source, $filename, 'font/sfnt', null, true);
    }

    private function sourceFont(string $filename): string
    {
        return dirname(__DIR__, 2).'/vendor/mpdf/mpdf/ttfonts/'.$filename;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if (false === $entries) {
            return;
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $directory.'/'.$entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
