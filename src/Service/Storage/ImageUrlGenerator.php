<?php

declare(strict_types=1);

namespace App\Service\Storage;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds public URLs for uploaded images, abstracting over the configured storage adapter.
 *
 * Local mode  → returns root-relative URLs ("/resources/images/...") including the request's
 *               base path (if the app runs in a sub-directory). Files are served directly by
 *               nginx from the public/ tree.
 * S3 mode     → returns absolute URLs ("https://bucket.fsn1.your-objectstorage.com/...").
 *               Bucket must be configured with a public-read policy.
 *
 * Callers receive a usable URL in either case and must not prepend any further base path.
 */
final class ImageUrlGenerator
{
    public function __construct(
        private readonly string $adapter,
        private readonly string $s3PublicUrl,
        private readonly string $localExportPrefix,
        private readonly string $localRoomCategoryPrefix,
        private readonly RequestStack $requestStack,
    ) {
    }

    /** URL for a file produced by FileUploader (template image upload). */
    public function exportUrl(string $filename): string
    {
        if ($this->isS3()) {
            return $this->s3Url('export/' . $filename);
        }

        return $this->localUrl($this->localExportPrefix . '/' . $filename);
    }

    /**
     * URL for a room-category image variant.
     *
     * @param string $variant One of 'thumb', 'medium', 'original'
     */
    public function roomCategoryUrl(int $categoryId, string $filename, string $variant = 'medium'): string
    {
        $prefix = match ($variant) {
            'thumb' => 'thumb_',
            'medium' => 'medium_',
            default => '',
        };

        $path = $categoryId . '/' . $prefix . $filename;

        if ($this->isS3()) {
            return $this->s3Url('room-categories/' . $path);
        }

        return $this->localUrl($this->localRoomCategoryPrefix . '/' . $path);
    }

    public function isS3(): bool
    {
        return 's3' === $this->adapter;
    }

    private function s3Url(string $key): string
    {
        return rtrim($this->s3PublicUrl, '/') . '/' . $key;
    }

    private function localUrl(string $relativePath): string
    {
        $basePath = $this->requestStack->getCurrentRequest()?->getBasePath() ?? '';

        return rtrim($basePath, '/') . '/' . $relativePath;
    }
}
