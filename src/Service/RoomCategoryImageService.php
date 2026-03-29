<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\RoomCategory;
use App\Entity\RoomCategoryImage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Handles upload, resize (3 variants), deletion, and URL generation
 * for room category images. Uses PHP GD for image processing.
 */
class RoomCategoryImageService
{
    /** Maximum width for each image variant */
    private const VARIANT_THUMB = 300;
    private const VARIANT_MEDIUM = 800;
    private const VARIANT_ORIGINAL = 1920;

    public function __construct(
        private readonly string $imageDirectory,
        private readonly string $imagePublicDirectory,
        private readonly ValidatorInterface $validator,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Validates and uploads an image file, creates three size variants
     * (thumbnail, medium, original) and persists the RoomCategoryImage entity.
     *
     * @return RoomCategoryImage The persisted image entity
     *
     * @throws \InvalidArgumentException if the file is not a valid image
     * @throws \RuntimeException         if the image cannot be processed
     */
    public function upload(RoomCategory $roomCategory, UploadedFile $file): RoomCategoryImage
    {
        if (!$this->isValidImage($file)) {
            throw new \InvalidArgumentException('Invalid image file. Maximum size: 10MB, allowed formats: JPEG, PNG, WebP.');
        }

        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = transliterator_transliterate(
            'Any-Latin; Latin-ASCII; [^A-Za-z0-9_] remove; Lower()',
            $originalFilename
        );
        $extension = $file->guessExtension() ?: 'jpg';
        $baseFilename = $safeFilename . '-' . uniqid() . '.' . $extension;

        $categoryDir = $this->getCategoryDirectory($roomCategory);
        if (!is_dir($categoryDir)) {
            mkdir($categoryDir, 0775, true);
        }

        // Move the original upload to a temp location for processing
        $tempPath = $categoryDir . '/' . 'upload_' . $baseFilename;
        $file->move($categoryDir, 'upload_' . $baseFilename);

        // Load once, fix orientation, then write all variants from the corrected source
        $source = $this->loadAndOrient($tempPath);
        $mime = $this->detectMime($tempPath);

        $this->saveVariant($source, $categoryDir . '/' . $baseFilename, self::VARIANT_ORIGINAL, $mime);
        $this->saveVariant($source, $categoryDir . '/medium_' . $baseFilename, self::VARIANT_MEDIUM, $mime);
        $this->saveVariant($source, $categoryDir . '/thumb_' . $baseFilename, self::VARIANT_THUMB, $mime);

        unset($source);

        // Remove the temporary upload
        if (file_exists($tempPath)) {
            unlink($tempPath);
        }

        // Determine sort order (append at end)
        $maxSort = 0;
        foreach ($roomCategory->getImages() as $existing) {
            if ($existing->getSortOrder() > $maxSort) {
                $maxSort = $existing->getSortOrder();
            }
        }

        $image = new RoomCategoryImage();
        $image->setFilename($baseFilename);
        $image->setSortOrder($maxSort + 1);

        // First image is automatically primary (no existing primary in the category)
        if (null === $roomCategory->getPrimaryImage()) {
            $image->setIsPrimary(true);
        }

        $roomCategory->addImage($image);
        $this->em->persist($image);
        $this->em->flush();

        return $image;
    }

    /**
     * Deletes a single image entity and all its file variants from disk.
     */
    public function delete(RoomCategoryImage $image): void
    {
        $categoryDir = $this->getCategoryDirectory($image->getRoomCategory());
        $filename = $image->getFilename();

        foreach ([$filename, 'medium_' . $filename, 'thumb_' . $filename] as $variant) {
            $path = $categoryDir . '/' . $variant;
            if (file_exists($path)) {
                unlink($path);
            }
        }

        $this->em->remove($image);
        $this->em->flush();
    }

    /**
     * Deletes all images for a room category including files on disk.
     * Call this before removing a RoomCategory entity.
     */
    public function deleteAllForCategory(RoomCategory $roomCategory): void
    {
        $categoryDir = $this->getCategoryDirectory($roomCategory);

        // Remove all files in the category directory
        if (is_dir($categoryDir)) {
            $files = glob($categoryDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($categoryDir);
        }
    }

    /**
     * Returns the public URL path for a given image variant.
     *
     * @param string $variant One of 'thumb', 'medium', 'original'
     */
    public function getPublicUrl(RoomCategoryImage $image, string $variant = 'medium'): string
    {
        $prefix = match ($variant) {
            'thumb' => 'thumb_',
            'medium' => 'medium_',
            default => '',
        };

        $categoryId = $image->getRoomCategory()->getId();

        return '/' . $this->imagePublicDirectory . '/' . $categoryId . '/' . $prefix . $image->getFilename();
    }

    /**
     * Validates that the uploaded file is a valid image (JPEG, PNG, WebP) under 10MB.
     */
    private function isValidImage(UploadedFile $file): bool
    {
        $constraint = new Assert\Image([
            'maxSize' => '10m',
            'mimeTypes' => ['image/jpeg', 'image/png', 'image/webp'],
        ]);

        return 0 === $this->validator->validate($file, $constraint)->count();
    }

    /**
     * Loads an image from disk, downscales to max variant size first (to reduce
     * memory usage), then applies EXIF orientation correction.
     * Returns a single GdImage that can be reused for all size variants.
     */
    private function loadAndOrient(string $sourcePath): \GdImage
    {
        $mime = $this->detectMime($sourcePath);

        $source = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($sourcePath),
            'image/png' => imagecreatefrompng($sourcePath),
            'image/webp' => imagecreatefromwebp($sourcePath),
            default => throw new \RuntimeException('Unsupported image type: ' . $mime),
        };

        if (false === $source) {
            throw new \RuntimeException('Failed to create image resource from: ' . $sourcePath);
        }

        // Downscale to max variant size first to reduce memory before rotation
        $srcWidth = imagesx($source);
        if ($srcWidth > self::VARIANT_ORIGINAL) {
            $srcHeight = imagesy($source);
            $newHeight = (int) round($srcHeight * (self::VARIANT_ORIGINAL / $srcWidth));
            $scaled = imagescale($source, self::VARIANT_ORIGINAL, $newHeight);
            if (false !== $scaled) {
                unset($source);
                $source = $scaled;
            }
        }

        // Apply EXIF orientation (cameras store portrait photos rotated with an EXIF flag)
        if ('image/jpeg' === $mime && function_exists('exif_read_data')) {
            $exif = @exif_read_data($sourcePath);
            if (false !== $exif && isset($exif['Orientation'])) {
                $rotated = match ((int) $exif['Orientation']) {
                    3 => imagerotate($source, 180, 0),
                    6 => imagerotate($source, -90, 0),
                    8 => imagerotate($source, 90, 0),
                    default => false,
                };
                if (false !== $rotated) {
                    unset($source);
                    $source = $rotated;
                }
            }
        }

        return $source;
    }

    /**
     * Writes a resized variant from an already-loaded GdImage.
     * If the source is smaller than the target width, it is saved without upscaling.
     */
    private function saveVariant(\GdImage $source, string $targetPath, int $maxWidth, string $mime): void
    {
        $srcWidth = imagesx($source);
        $srcHeight = imagesy($source);

        if ($srcWidth > $maxWidth) {
            $newHeight = (int) round($srcHeight * ($maxWidth / $srcWidth));
            $resized = imagescale($source, $maxWidth, $newHeight);
            if (false === $resized) {
                throw new \RuntimeException('Failed to resize image');
            }
        } else {
            $resized = $source;
        }

        match ($mime) {
            'image/jpeg' => imagejpeg($resized, $targetPath, 85),
            'image/png' => imagepng($resized, $targetPath, 6),
            'image/webp' => imagewebp($resized, $targetPath, 85),
        };

        // Free the resized copy (but not the source — it's reused for other variants)
        if ($resized !== $source) {
            unset($resized);
        }
    }

    /** Returns the MIME type of an image file. */
    private function detectMime(string $path): string
    {
        $info = getimagesize($path);
        if (false === $info) {
            throw new \RuntimeException('Cannot read image: ' . $path);
        }

        return $info['mime'];
    }

    /**
     * Returns the filesystem directory path for a room category's images.
     */
    private function getCategoryDirectory(RoomCategory $roomCategory): string
    {
        return $this->imageDirectory . '/' . $roomCategory->getId();
    }
}
