<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\RoomCategory;
use App\Entity\RoomCategoryImage;
use App\Service\RoomCategoryImageService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Tests for the RoomCategoryImageService, specifically URL generation
 * and delete-all-for-category logic (without touching the filesystem).
 */
final class RoomCategoryImageServiceTest extends TestCase
{
    /** Verify that getPublicUrl generates correct paths for each variant. */
    public function testGetPublicUrlGeneratesCorrectPaths(): void
    {
        $service = $this->createService();
        $image = $this->createImageWithCategory(42, 'photo-abc123.jpg');

        self::assertSame(
            '/resources/images/room-categories/42/thumb_photo-abc123.jpg',
            $service->getPublicUrl($image, 'thumb')
        );
        self::assertSame(
            '/resources/images/room-categories/42/medium_photo-abc123.jpg',
            $service->getPublicUrl($image, 'medium')
        );
        self::assertSame(
            '/resources/images/room-categories/42/photo-abc123.jpg',
            $service->getPublicUrl($image, 'original')
        );
    }

    /** Verify that the default variant is 'medium'. */
    public function testGetPublicUrlDefaultsToMedium(): void
    {
        $service = $this->createService();
        $image = $this->createImageWithCategory(7, 'test.jpg');

        self::assertSame(
            '/resources/images/room-categories/7/medium_test.jpg',
            $service->getPublicUrl($image)
        );
    }

    /** Verify that deleteAllForCategory handles non-existent directory gracefully. */
    public function testDeleteAllForCategoryHandlesNonExistentDirectory(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $validator = $this->createStub(ValidatorInterface::class);

        $service = new RoomCategoryImageService(
            '/tmp/nonexistent-' . uniqid(),
            'resources/images/room-categories',
            $validator,
            $em,
        );

        $category = $this->createRoomCategoryWithId(99);

        // Should not throw — gracefully handles missing directory
        $service->deleteAllForCategory($category);
        self::assertTrue(true);
    }

    /** Verify that deleteAllForCategory removes files and directory. */
    public function testDeleteAllForCategoryRemovesFilesAndDirectory(): void
    {
        $tempDir = sys_get_temp_dir() . '/fewohbee-test-' . uniqid();
        $categoryDir = $tempDir . '/5';
        mkdir($categoryDir, 0775, true);

        // Create test files
        file_put_contents($categoryDir . '/photo.jpg', 'fake-jpg');
        file_put_contents($categoryDir . '/thumb_photo.jpg', 'fake-thumb');
        file_put_contents($categoryDir . '/medium_photo.jpg', 'fake-medium');

        $em = $this->createStub(EntityManagerInterface::class);
        $validator = $this->createStub(ValidatorInterface::class);

        $service = new RoomCategoryImageService(
            $tempDir,
            'resources/images/room-categories',
            $validator,
            $em,
        );

        $category = $this->createRoomCategoryWithId(5);
        $service->deleteAllForCategory($category);

        self::assertDirectoryDoesNotExist($categoryDir);
    }

    private function createService(): RoomCategoryImageService
    {
        return new RoomCategoryImageService(
            '/var/www/public/resources/images/room-categories',
            'resources/images/room-categories',
            $this->createStub(ValidatorInterface::class),
            $this->createStub(EntityManagerInterface::class),
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

    /**
     * Creates a RoomCategory with a specific ID set via reflection.
     */
    private function createRoomCategoryWithId(int $id): RoomCategory
    {
        $category = new RoomCategory();
        $ref = new \ReflectionProperty(RoomCategory::class, 'id');
        $ref->setValue($category, $id);

        return $category;
    }
}
