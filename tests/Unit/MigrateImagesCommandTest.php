<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Command\MigrateImagesCommand;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\Visibility;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class MigrateImagesCommandTest extends TestCase
{
    private string $workDirectory;

    protected function setUp(): void
    {
        $this->workDirectory = sys_get_temp_dir().'/fewohbee-storage-migration-test-'.bin2hex(random_bytes(6));
        mkdir($this->workDirectory.'/public/resources/images/export', 0775, true);
        mkdir($this->workDirectory.'/public/resources/images/room-categories/1', 0775, true);
        mkdir($this->workDirectory.'/var/storage/fonts', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workDirectory);
    }

    public function testMigratesImagesAndCustomFontsToTheirConfiguredStorages(): void
    {
        file_put_contents($this->workDirectory.'/public/resources/images/export/logo.png', 'logo');
        file_put_contents($this->workDirectory.'/public/resources/images/room-categories/1/thumb_photo.jpg', 'photo');
        file_put_contents($this->workDirectory.'/var/storage/fonts/font.ttf', 'font');

        $exportStorage = new Filesystem(new InMemoryFilesystemAdapter());
        $roomCategoryStorage = new Filesystem(new InMemoryFilesystemAdapter());
        $fontStorage = new Filesystem(new InMemoryFilesystemAdapter());
        $command = new MigrateImagesCommand(
            $exportStorage,
            $roomCategoryStorage,
            $fontStorage,
            $this->workDirectory,
            $this->workDirectory.'/var/storage/fonts',
        );

        $exitCode = (new CommandTester($command))->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('logo', $exportStorage->read('logo.png'));
        self::assertSame('photo', $roomCategoryStorage->read('1/thumb_photo.jpg'));
        self::assertSame('font', $fontStorage->read('font.ttf'));
        self::assertSame(Visibility::PRIVATE, $fontStorage->visibility('font.ttf'));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
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
