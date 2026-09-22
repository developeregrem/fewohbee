<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\CustomFontFace;
use App\Dto\CustomFontFamily;
use App\Exception\CustomFontException;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Visibility;
use Mpdf\Cache;
use Mpdf\Fonts\FontCache;
use Mpdf\TTFontFileAnalysis;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Discovers and manages custom TrueType fonts stored through Flysystem.
 *
 * mPDF requires real local paths. Flysystem remains the source of truth while each
 * process materializes content-addressed font objects in its local application cache.
 */
final class CustomFontManager
{
    public const MAX_FILE_SIZE = 10 * 1024 * 1024;

    /** @var string[] */
    private const ALLOWED_EXTENSIONS = ['ttf', 'otf'];

    /** @var string[] */
    private const ALLOWED_MIME_TYPES = [
        'font/sfnt',
        'font/ttf',
        'font/otf',
        'application/font-sfnt',
        'application/x-font-ttf',
        'application/x-font-truetype',
        'application/x-font-otf',
        'application/x-font-opentype',
        'application/vnd.ms-opentype',
        // Some distributions have no font-specific libmagic entry. The binary parser
        // below remains the authoritative validation in this case.
        'application/octet-stream',
    ];

    /** @var list<CustomFontFamily>|null */
    private ?array $families = null;

    public function __construct(
        private readonly FilesystemOperator $storage,
        private readonly string $fontCacheDirectory,
        private readonly string $analysisCacheDirectory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<CustomFontFamily>
     */
    public function getFamilies(): array
    {
        if (null !== $this->families) {
            return $this->families;
        }

        /**
         * @var array<string, array{
         *     name: string,
         *     alias: string,
         *     fallback: string,
         *     faces: array<string, CustomFontFace>,
         *     requiresOpenTypeLayout: bool
         * }> $grouped
         */
        $grouped = [];

        foreach ($this->fontFiles() as $path) {
            try {
                $metadata = $this->inspectFont($path);
            } catch (CustomFontException $e) {
                $this->logger->warning('Ignoring an invalid custom font file.', [
                    'filename' => basename($path),
                    'reason' => $e->translationKey,
                    'exception' => $e,
                ]);
                continue;
            }

            $familyKey = $this->normalizeFamilyName($metadata['family']);
            $alias = $this->aliasForFamily($metadata['family']);
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $version = hash_file('sha256', $path);
            if (false === $version) {
                $this->logger->warning('Ignoring a custom font whose checksum could not be read.', [
                    'filename' => basename($path),
                ]);
                continue;
            }

            $grouped[$familyKey] ??= [
                'name' => $metadata['family'],
                'alias' => $alias,
                'fallback' => $metadata['fallback'],
                'faces' => [],
                'requiresOpenTypeLayout' => false,
            ];

            if (isset($grouped[$familyKey]['faces'][$metadata['style']])) {
                $this->logger->warning('Ignoring a duplicate custom font face.', [
                    'filename' => basename($path),
                    'family' => $metadata['family'],
                    'style' => $metadata['style'],
                ]);
                continue;
            }

            $grouped[$familyKey]['faces'][$metadata['style']] = new CustomFontFace(
                $metadata['style'],
                basename($path),
                $path,
                $version,
                $extension,
            );
            $grouped[$familyKey]['requiresOpenTypeLayout'] = $grouped[$familyKey]['requiresOpenTypeLayout']
                || $metadata['requiresOpenTypeLayout'];
        }

        $families = array_map(
            static fn (array $family): CustomFontFamily => new CustomFontFamily(
                $family['name'],
                $family['alias'],
                $family['fallback'],
                $family['faces'],
                $family['requiresOpenTypeLayout'],
            ),
            array_values($grouped),
        );
        usort($families, static fn (CustomFontFamily $a, CustomFontFamily $b): int => strnatcasecmp($a->name, $b->name));

        return $this->families = $families;
    }

    /**
     * @return array<string, array<string, string|int>>
     */
    public function getMpdfFontData(): array
    {
        $fontData = [];
        foreach ($this->getFamilies() as $family) {
            if ($family->isUsable()) {
                $fontData[$family->alias] = $family->getMpdfFontData();
            }
        }

        return $fontData;
    }

    public function getFontDirectory(): string
    {
        return $this->fontCacheDirectory;
    }

    /** A content-derived cache namespace prevents stale mPDF metrics after replacement. */
    public function getCacheFingerprint(): string
    {
        $parts = [];
        foreach ($this->getFamilies() as $family) {
            foreach ($family->faces as $style => $face) {
                $parts[] = $family->alias.':'.$style.':'.$face->version;
            }
        }

        return substr(hash('sha256', implode('|', $parts)), 0, 16);
    }

    public function findFamily(string $alias): ?CustomFontFamily
    {
        foreach ($this->getFamilies() as $family) {
            if (hash_equals($family->alias, $alias)) {
                return $family;
            }
        }

        return null;
    }

    public function findFace(string $alias, string $style): ?CustomFontFace
    {
        if (!in_array($style, [
            CustomFontFace::REGULAR,
            CustomFontFace::BOLD,
            CustomFontFace::ITALIC,
            CustomFontFace::BOLD_ITALIC,
        ], true)) {
            return null;
        }

        return $this->findFamily($alias)?->getFace($style);
    }

    /**
     * Installs one uploaded font face and replaces an existing face of the same family/style.
     *
     * @throws CustomFontException
     */
    public function upload(UploadedFile $file): CustomFontFamily
    {
        if (!$file->isValid()) {
            throw new CustomFontException('templates.fonts.validation.upload');
        }

        $size = $file->getSize();
        if (false === $size || $size <= 0 || $size > self::MAX_FILE_SIZE) {
            throw new CustomFontException('templates.fonts.validation.size');
        }

        $extension = strtolower((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new CustomFontException('templates.fonts.validation.extension');
        }

        $mimeType = $file->getMimeType();
        if (null === $mimeType || !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new CustomFontException('templates.fonts.validation.mime');
        }

        $metadata = $this->inspectFont($file->getPathname());
        $alias = $this->aliasForFamily($metadata['family']);
        $checksum = hash_file('sha256', $file->getPathname());
        if (false === $checksum) {
            throw new CustomFontException('templates.fonts.validation.invalid');
        }

        $filename = sprintf('%s-%s-%s.%s', $alias, strtolower($metadata['style']), substr($checksum, 0, 16), $extension);
        $this->synchronizeLocalCache(true);
        $replacedFiles = $this->filesForFamilyAndStyle($alias, $metadata['style']);

        $stream = @fopen($file->getPathname(), 'rb');
        if (false === $stream) {
            throw new CustomFontException('templates.fonts.validation.storage');
        }

        try {
            $this->storage->writeStream($filename, $stream, ['visibility' => Visibility::PRIVATE]);
        } catch (FilesystemException $e) {
            throw new CustomFontException('templates.fonts.validation.storage', $e);
        } finally {
            fclose($stream);
        }

        foreach ($replacedFiles as $replacedFile) {
            $replacedFilename = basename($replacedFile);
            if ($replacedFilename === $filename) {
                continue;
            }

            try {
                $this->storage->delete($replacedFilename);
            } catch (FilesystemException $e) {
                $this->logger->warning('An obsolete custom font face could not be removed.', [
                    'filename' => $replacedFilename,
                    'exception' => $e,
                ]);
                try {
                    $this->storage->delete($filename);
                } catch (FilesystemException) {
                    // The next synchronization still handles duplicate objects safely.
                }

                throw new CustomFontException('templates.fonts.validation.storage', $e);
            }
        }

        $this->families = null;
        $this->synchronizeLocalCache(true);

        return $this->findFamily($alias)
            ?? throw new CustomFontException('templates.fonts.validation.storage');
    }

    /**
     * @throws CustomFontException
     */
    public function deleteFamily(string $alias): bool
    {
        $matchingFiles = [];
        foreach ($this->fontFiles() as $path) {
            try {
                $metadata = $this->inspectFont($path);
            } catch (CustomFontException) {
                continue;
            }

            if (hash_equals($this->aliasForFamily($metadata['family']), $alias)) {
                $matchingFiles[] = $path;
            }
        }

        if ([] === $matchingFiles) {
            return false;
        }

        foreach ($matchingFiles as $path) {
            try {
                $this->storage->delete(basename($path));
            } catch (FilesystemException $e) {
                throw new CustomFontException('templates.fonts.validation.storage', $e);
            }
        }
        $this->families = null;
        $this->synchronizeLocalCache(true);

        return true;
    }

    /**
     * @return list<string>
     */
    private function fontFiles(): array
    {
        $this->synchronizeLocalCache();

        $entries = scandir($this->fontCacheDirectory);
        if (false === $entries) {
            $this->logger->warning('The local custom font cache could not be read.');

            return [];
        }

        $files = [];
        foreach ($entries as $entry) {
            $path = $this->fontCacheDirectory.DIRECTORY_SEPARATOR.$entry;
            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (in_array($extension, self::ALLOWED_EXTENSIONS, true) && is_file($path) && !is_link($path)) {
                $files[] = $path;
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @return array{family: string, style: string, fallback: string, requiresOpenTypeLayout: bool}
     *
     * @throws CustomFontException
     */
    private function inspectFont(string $path): array
    {
        $size = @filesize($path);
        if (false === $size || $size <= 0 || $size > self::MAX_FILE_SIZE) {
            throw new CustomFontException('templates.fonts.validation.size');
        }

        try {
            $cache = new FontCache(new Cache($this->analysisCacheDirectory));
            $analysis = new TTFontFileAnalysis($cache, 'winTypo');
            $info = $analysis->extractCoreInfo($path);
        } catch (\Throwable $e) {
            throw new CustomFontException('templates.fonts.validation.invalid', $e);
        }

        $family = trim((string) ($info[0] ?? ''));
        if ('' === $family) {
            throw new CustomFontException('templates.fonts.validation.invalid');
        }

        $bold = true === ($info[1] ?? false);
        $italic = true === ($info[2] ?? false);
        $style = match (true) {
            $bold && $italic => CustomFontFace::BOLD_ITALIC,
            $bold => CustomFontFace::BOLD,
            $italic => CustomFontFace::ITALIC,
            default => CustomFontFace::REGULAR,
        };

        $fallback = match ($info[3] ?? '') {
            'serif' => 'serif',
            'mono' => 'monospace',
            'cursive' => 'cursive',
            default => 'sans-serif',
        };

        return [
            'family' => $family,
            'style' => $style,
            'fallback' => $fallback,
            'requiresOpenTypeLayout' => true === ($info[5] ?? false) || true === ($info[6] ?? false),
        ];
    }

    private function synchronizeLocalCache(bool $failOnStorageError = false): void
    {
        $this->ensureCacheDirectory();

        try {
            $storedPaths = $this->storedFontPaths();
        } catch (CustomFontException $e) {
            if ($failOnStorageError) {
                throw $e;
            }

            $this->logger->error('Custom font storage could not be synchronized; using the local cache.', [
                'exception' => $e,
            ]);

            return;
        }

        $expectedFiles = [];
        foreach ($storedPaths as $storedPath) {
            $filename = basename($storedPath);
            $expectedFiles[$filename] = true;
            $localPath = $this->fontCacheDirectory.DIRECTORY_SEPARATOR.$filename;
            if ($this->isContentAddressedFilename($filename) && is_file($localPath)) {
                continue;
            }

            try {
                $this->cacheStoredFont($storedPath, $localPath);
            } catch (CustomFontException $e) {
                if ($failOnStorageError && 'templates.fonts.validation.storage' === $e->translationKey) {
                    throw $e;
                }

                $this->logger->warning('A custom font could not be cached locally.', [
                    'filename' => $filename,
                    'reason' => $e->translationKey,
                    'exception' => $e,
                ]);
            }
        }

        $entries = scandir($this->fontCacheDirectory);
        if (false === $entries) {
            throw new CustomFontException('templates.fonts.validation.storage');
        }

        foreach ($entries as $entry) {
            $path = $this->fontCacheDirectory.DIRECTORY_SEPARATOR.$entry;
            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (!isset($expectedFiles[$entry])
                && in_array($extension, self::ALLOWED_EXTENSIONS, true)
                && is_file($path)
                && !is_link($path)
                && !@unlink($path)
            ) {
                $this->logger->warning('An obsolete locally cached font could not be removed.', [
                    'filename' => $entry,
                ]);
            }
        }
    }

    private function ensureCacheDirectory(): void
    {
        if (!is_dir($this->fontCacheDirectory)
            && !@mkdir($this->fontCacheDirectory, 0775, true)
            && !is_dir($this->fontCacheDirectory)
        ) {
            throw new CustomFontException('templates.fonts.validation.storage');
        }

        if (!is_writable($this->fontCacheDirectory)) {
            throw new CustomFontException('templates.fonts.validation.storage');
        }
    }

    /**
     * @return list<string>
     */
    private function storedFontPaths(): array
    {
        $paths = [];

        try {
            foreach ($this->storage->listContents('', false) as $item) {
                $path = $item->path();
                $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if ($item->isFile()
                    && basename($path) === $path
                    && in_array($extension, self::ALLOWED_EXTENSIONS, true)
                ) {
                    $paths[] = $path;
                }
            }
        } catch (FilesystemException $e) {
            throw new CustomFontException('templates.fonts.validation.storage', $e);
        }

        sort($paths, SORT_STRING);

        return $paths;
    }

    private function cacheStoredFont(string $storedPath, string $localPath): void
    {
        $temporaryPath = $this->fontCacheDirectory.DIRECTORY_SEPARATOR.'.font-'.bin2hex(random_bytes(8)).'.tmp';
        $source = null;
        $target = null;
        $downloaded = false;

        try {
            $source = $this->storage->readStream($storedPath);
            if (!is_resource($source)) {
                throw new CustomFontException('templates.fonts.validation.storage');
            }
            $target = @fopen($temporaryPath, 'wb');
            if (false === $target) {
                throw new CustomFontException('templates.fonts.validation.storage');
            }

            $copied = stream_copy_to_stream($source, $target, self::MAX_FILE_SIZE + 1);
            if (false === $copied) {
                throw new CustomFontException('templates.fonts.validation.storage');
            }
            if ($copied > self::MAX_FILE_SIZE) {
                throw new CustomFontException('templates.fonts.validation.size');
            }
            $downloaded = true;
        } catch (FilesystemException $e) {
            throw new CustomFontException('templates.fonts.validation.storage', $e);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }
            if (!$downloaded) {
                @unlink($temporaryPath);
            }
        }

        @chmod($temporaryPath, 0644);
        if (!@rename($temporaryPath, $localPath)) {
            @unlink($temporaryPath);
            throw new CustomFontException('templates.fonts.validation.storage');
        }
    }

    private function isContentAddressedFilename(string $filename): bool
    {
        return 1 === preg_match('/^fhbcustom[0-9a-f]{16}-(?:r|b|i|bi)-[0-9a-f]{16}\.(?:ttf|otf)$/', $filename);
    }

    /**
     * @return list<string>
     */
    private function filesForFamilyAndStyle(string $alias, string $style): array
    {
        $files = [];
        foreach ($this->fontFiles() as $path) {
            try {
                $metadata = $this->inspectFont($path);
            } catch (CustomFontException) {
                continue;
            }

            if ($style === $metadata['style'] && hash_equals($alias, $this->aliasForFamily($metadata['family']))) {
                $files[] = $path;
            }
        }

        return $files;
    }

    private function aliasForFamily(string $family): string
    {
        return 'fhbcustom'.substr(hash('sha256', $this->normalizeFamilyName($family)), 0, 16);
    }

    private function normalizeFamilyName(string $family): string
    {
        return mb_strtolower(trim($family), 'UTF-8');
    }
}
