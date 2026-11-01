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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraints\Image as ImageConstraint;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

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

    #[DataProvider('supportedImageFormats')]
    public function testUploadPreservesFormatAcrossAllVariants(string $mime, string $extension): void
    {
        $file = $this->createImageUpload($extension);
        $adapter = new InMemoryFilesystemAdapter();
        $fs = new Filesystem($adapter);
        $validator = $this->createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList());
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(RoomCategoryImage::class));
        $em->expects(self::once())->method('flush');
        $service = new RoomCategoryImageService(
            $fs,
            $this->createUrlGenerator(),
            $validator,
            $this->createStub(TranslatorInterface::class),
            $em,
        );

        try {
            $image = $service->upload($this->createRoomCategoryWithId(5), $file);

            foreach (['' => [400, 200], 'medium_' => [400, 200], 'thumb_' => [300, 150]] as $prefix => [$width, $height]) {
                $data = $fs->read('5/'.$prefix.$image->getFilename());
                $info = getimagesizefromstring($data);
                self::assertNotFalse($info);
                self::assertSame($mime, $info['mime']);
                self::assertSame($width, $info[0]);
                self::assertSame($height, $info[1]);
            }
        } finally {
            if (is_file($file->getPathname())) {
                unlink($file->getPathname());
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function supportedImageFormats(): iterable
    {
        yield 'JPEG' => ['image/jpeg', 'jpg'];
        yield 'PNG' => ['image/png', 'png'];
        yield 'WebP' => ['image/webp', 'webp'];
    }

    public function testUnsupportedImageDoesNotWriteFilesOrPersistAnEntity(): void
    {
        $file = $this->createImageUpload('gif');
        $fs = new Filesystem(new InMemoryFilesystemAdapter());
        // Exercise the processing boundary even if a validator accepts the file.
        $validator = $this->createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList());
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');
        $service = new RoomCategoryImageService(
            $fs,
            $this->createUrlGenerator(),
            $validator,
            $this->createStub(TranslatorInterface::class),
            $em,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported image type: image/gif');

        try {
            $service->upload($this->createRoomCategoryWithId(5), $file);
        } finally {
            self::assertSame([], $fs->listContents('', true)->toArray());
            if (is_file($file->getPathname())) {
                unlink($file->getPathname());
            }
        }
    }

    public function testUploadRejectsUnsafeDimensionsBeforeGdDecode(): void
    {
        $file = new UploadedFile(__FILE__, 'oversized.jpg', 'image/jpeg', null, true);
        $message = 'translated-resolution-message';

        $violation = $this->createStub(ConstraintViolationInterface::class);
        $violation->method('getCode')->willReturn(ImageConstraint::TOO_MANY_PIXEL_ERROR);

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects(self::once())
            ->method('validate')
            ->with(
                $file,
                self::callback(static fn (mixed $constraint): bool => $constraint instanceof ImageConstraint
                    && RoomCategoryImageService::MAX_PIXELS === $constraint->maxPixels
                    && RoomCategoryImageService::MAX_DIMENSION === $constraint->maxWidth
                    && RoomCategoryImageService::MAX_DIMENSION === $constraint->maxHeight
                    && ['image/jpeg', 'image/png', 'image/webp'] === $constraint->mimeTypes),
            )
            ->willReturn(new ConstraintViolationList([$violation]));

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('category.images.validation.resolution')
            ->willReturn($message);

        $service = new RoomCategoryImageService(
            new Filesystem(new InMemoryFilesystemAdapter()),
            $this->createUrlGenerator(),
            $validator,
            $translator,
            $this->createStub(EntityManagerInterface::class),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $service->upload($this->createRoomCategoryWithId(5), $file);
    }

    public function testDeleteAllForMissingCategoryPreservesOtherFiles(): void
    {
        $adapter = new InMemoryFilesystemAdapter();
        $fs = new Filesystem($adapter);
        $fs->write('5/photo.jpg', 'unrelated-image');
        $service = $this->createService($adapter);
        $category = $this->createRoomCategoryWithId(99);

        $service->deleteAllForCategory($category);

        self::assertFalse($fs->directoryExists('99'));
        self::assertSame('unrelated-image', $fs->read('5/photo.jpg'));
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
            $this->createStub(TranslatorInterface::class),
            $em,
        );

        $image = $this->createImageWithCategory(5, 'photo.jpg');
        $service->delete($image);

        self::assertFalse($fs->fileExists('5/photo.jpg'));
        self::assertFalse($fs->fileExists('5/medium_photo.jpg'));
        self::assertFalse($fs->fileExists('5/thumb_photo.jpg'));
    }

    private function createImageUpload(string $extension): UploadedFile
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD is required for the image-processing cases.');
        }
        $encoder = match ($extension) {
            'jpg' => 'imagejpeg',
            'png' => 'imagepng',
            'webp' => 'imagewebp',
            'gif' => 'imagegif',
            default => throw new \InvalidArgumentException('Unknown test image extension.'),
        };
        if (!function_exists($encoder)) {
            self::markTestSkipped('GD does not provide the '.$extension.' encoder.');
        }
        $path = tempnam(sys_get_temp_dir(), 'fewohbee-image-test-');
        if (false === $path) {
            self::fail('Could not create a temporary image file.');
        }
        $image = imagecreatetruecolor(400, 200);
        if (false === $image) {
            unlink($path);
            self::fail('Could not create the synthetic test image.');
        }
        $written = match ($extension) {
            'jpg' => imagejpeg($image, $path),
            'png' => imagepng($image, $path),
            'webp' => imagewebp($image, $path),
            'gif' => imagegif($image, $path),
        };
        if (!$written) {
            unlink($path);
            self::fail('Could not write the synthetic test image.');
        }

        return new UploadedFile($path, 'synthetic.'.$extension, null, null, true);
    }

    private function createService(InMemoryFilesystemAdapter $adapter): RoomCategoryImageService
    {
        return new RoomCategoryImageService(
            new Filesystem($adapter),
            $this->createUrlGenerator(),
            $this->createStub(ValidatorInterface::class),
            $this->createStub(TranslatorInterface::class),
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
