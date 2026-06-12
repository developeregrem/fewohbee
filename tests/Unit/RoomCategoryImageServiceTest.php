<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\RoomCategory;
use App\Entity\RoomCategoryImage;
use App\Service\RoomCategoryImageService;
use App\Service\Storage\ImageUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Tests for the RoomCategoryImageService — URL generation (delegated to ImageUrlGenerator)
 * and delete operations against an in-memory Flysystem storage.
 */
final class RoomCategoryImageServiceTest extends TestCase
{
    public function testGetPublicUrlDelegatesToUrlGenerator(): void
    {
        $service = $this->createService(new InMemoryFilesystemAdapter());
        $image = $this->createImageWithCategory(42, 'photo-abc123.jpg');

        self::assertSame('/resources/images/room-categories/42/thumb_photo-abc123.jpg', $service->getPublicUrl($image, 'thumb'));
        self::assertSame('/resources/images/room-categories/42/medium_photo-abc123.jpg', $service->getPublicUrl($image, 'medium'));
        self::assertSame('/resources/images/room-categories/42/photo-abc123.jpg', $service->getPublicUrl($image, 'original'));
    }

    public function testGetPublicUrlDefaultsToMedium(): void
    {
        $service = $this->createService(new InMemoryFilesystemAdapter());
        $image = $this->createImageWithCategory(7, 'test.jpg');
        self::assertSame('/resources/images/room-categories/7/medium_test.jpg', $service->getPublicUrl($image));
    }

    public function testDeleteAllForCategoryHandlesEmptyStorage(): void
    {
        $service = $this->createService(new InMemoryFilesystemAdapter());
        $category = $this->createRoomCategoryWithId(99);

        $service->deleteAllForCategory($category);
        self::assertTrue(true);
    }

    public function testDeleteAllForCategoryRemovesAllFilesInCategoryDir(): void
    {
        $adapter = new InMemoryFilesystemAdapter();
        $fs = new Filesystem($adapter);
        $fs->write('5/photo.jpg', 'fake-jpg');
        $fs->write('5/thumb_photo.jpg', 'fake-thumb');
        $fs->write('5/medium_photo.jpg', 'fake-medium');
        $fs->write('6/other.jpg', 'untouched');

        $service = $this->createService($adapter);
        $service->deleteAllForCategory($this->createRoomCategoryWithId(5));

        self::assertFalse($fs->fileExists('5/photo.jpg'));
        self::assertFalse($fs->fileExists('5/thumb_photo.jpg'));
        self::assertFalse($fs->fileExists('5/medium_photo.jpg'));
        self::assertTrue($fs->fileExists('6/other.jpg'));
    }

    public function testDeleteRemovesAllThreeVariants(): void
    {
        $adapter = new InMemoryFilesystemAdapter();
        $fs = new Filesystem($adapter);
        $fs->write('5/photo.jpg', 'fake');
        $fs->write('5/medium_photo.jpg', 'fake');
        $fs->write('5/thumb_photo.jpg', 'fake');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('remove');
        $em->expects(self::once())->method('flush');

        $service = new RoomCategoryImageService(
            new Filesystem($adapter),
            $this->createUrlGenerator(),
            $this->createStub(ValidatorInterface::class),
            $em,
        );

        $image = $this->createImageWithCategory(5, 'photo.jpg');
        $service->delete($image);

        self::assertFalse($fs->fileExists('5/photo.jpg'));
        self::assertFalse($fs->fileExists('5/medium_photo.jpg'));
        self::assertFalse($fs->fileExists('5/thumb_photo.jpg'));
    }

    private function createService(InMemoryFilesystemAdapter $adapter): RoomCategoryImageService
    {
        return new RoomCategoryImageService(
            new Filesystem($adapter),
            $this->createUrlGenerator(),
            $this->createStub(ValidatorInterface::class),
            $this->createStub(EntityManagerInterface::class),
        );
    }

    private function createUrlGenerator(): ImageUrlGenerator
    {
        return new ImageUrlGenerator(
            'local',
            '',
            '',
            'resources/images/export',
            'resources/images/room-categories',
            new RequestStack(),
        );
    }

    private function createImageWithCategory(int $categoryId, string $filename): RoomCategoryImage
    {
        $category = $this->createRoomCategoryWithId($categoryId);
        $image = new RoomCategoryImage();
        $image->setRoomCategory($category);
        $image->setFilename($filename);

        return $image;
    }

    private function createRoomCategoryWithId(int $id): RoomCategory
    {
        $category = new RoomCategory();
        $ref = new \ReflectionProperty(RoomCategory::class, 'id');
        $ref->setValue($category, $id);

        return $category;
    }
}
