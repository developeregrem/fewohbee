<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Storage\ImageUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ImageUrlGeneratorTest extends TestCase
{
    public function testLocalRoomCategoryUrlsForEachVariant(): void
    {
        $gen = $this->createGenerator('local');

        self::assertSame('/resources/images/room-categories/42/thumb_photo-abc.jpg', $gen->roomCategoryUrl(42, 'photo-abc.jpg', 'thumb'));
        self::assertSame('/resources/images/room-categories/42/medium_photo-abc.jpg', $gen->roomCategoryUrl(42, 'photo-abc.jpg', 'medium'));
        self::assertSame('/resources/images/room-categories/42/photo-abc.jpg', $gen->roomCategoryUrl(42, 'photo-abc.jpg', 'original'));
    }

    public function testLocalRoomCategoryUrlDefaultsToMedium(): void
    {
        $gen = $this->createGenerator('local');
        self::assertSame('/resources/images/room-categories/7/medium_test.jpg', $gen->roomCategoryUrl(7, 'test.jpg'));
    }

    public function testLocalExportUrl(): void
    {
        $gen = $this->createGenerator('local');
        self::assertSame('/resources/images/export/upload-xyz.png', $gen->exportUrl('upload-xyz.png'));
    }

    public function testLocalUrlsIncludeBasePathWhenAppRunsInSubdir(): void
    {
        $stack = new RequestStack();
        $request = Request::create('/myapp/some/page');
        $request->server->set('SCRIPT_FILENAME', '/var/www/myapp/public/index.php');
        $request->server->set('SCRIPT_NAME', '/myapp/index.php');
        $request->server->set('PHP_SELF', '/myapp/index.php');
        $stack->push($request);

        $gen = new ImageUrlGenerator('local', '', 'resources/images/export', 'resources/images/room-categories', $stack);

        self::assertSame('/myapp/resources/images/room-categories/3/medium_x.jpg', $gen->roomCategoryUrl(3, 'x.jpg'));
    }

    public function testS3UrlsAreAbsolute(): void
    {
        $gen = $this->createGenerator('s3', 'https://bucket.fsn1.your-objectstorage.com');

        self::assertSame(
            'https://bucket.fsn1.your-objectstorage.com/room-categories/42/medium_photo.jpg',
            $gen->roomCategoryUrl(42, 'photo.jpg', 'medium')
        );
        self::assertSame(
            'https://bucket.fsn1.your-objectstorage.com/export/upload.png',
            $gen->exportUrl('upload.png')
        );
    }

    public function testS3PublicUrlTrailingSlashIsTrimmed(): void
    {
        $gen = $this->createGenerator('s3', 'https://bucket.example.com/');
        self::assertSame('https://bucket.example.com/export/a.jpg', $gen->exportUrl('a.jpg'));
    }

    private function createGenerator(string $adapter, string $s3PublicUrl = ''): ImageUrlGenerator
    {
        return new ImageUrlGenerator(
            $adapter,
            $s3PublicUrl,
            'resources/images/export',
            'resources/images/room-categories',
            new RequestStack(),
        );
    }
}
