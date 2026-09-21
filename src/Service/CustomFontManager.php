<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\CustomFontFace;
use App\Dto\CustomFontFamily;
use App\Exception\CustomFontException;
use Mpdf\Cache;
use Mpdf\Fonts\FontCache;
use Mpdf\TTFontFileAnalysis;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Discovers and manages custom TrueType fonts stored outside the application code.
 *
 * Font metadata is the source of truth, so native installations and Docker volumes use
 * the same directory layout without a database manifest that could get out of sync.
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
        private readonly string $fontDirectory,
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
        return $this->fontDirectory;
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

        $this->ensureStorageDirectory();
        $filename = sprintf('%s-%s-%s.%s', $alias, strtolower($metadata['style']), substr($checksum, 0, 16), $extension);
        $targetPath = $this->fontDirectory.DIRECTORY_SEPARATOR.$filename;

        $replacedFiles = $this->filesForFamilyAndStyle($alias, $metadata['style']);

        try {
            if (!is_file($targetPath)) {
                $file->move($this->fontDirectory, $filename);
                @chmod($targetPath, 0644);
            }

            foreach ($replacedFiles as $replacedFile) {
                if ($replacedFile !== $targetPath && is_file($replacedFile) && !@unlink($replacedFile)) {
                    $this->logger->warning('An obsolete custom font face could not be removed.', [
                        'filename' => basename($replacedFile),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            throw new CustomFontException('templates.fonts.validation.storage', $e);
        }

        $this->families = null;

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
            if (!@unlink($path)) {
                throw new CustomFontException('templates.fonts.validation.storage');
            }
        }
        $this->families = null;

        return true;
    }

    /**
     * @return list<string>
     */
    private function fontFiles(): array
    {
        if (!is_dir($this->fontDirectory)) {
            return [];
        }

        $entries = scandir($this->fontDirectory);
        if (false === $entries) {
            $this->logger->warning('The custom font directory could not be read.');

            return [];
        }

        $files = [];
        foreach ($entries as $entry) {
            $path = $this->fontDirectory.DIRECTORY_SEPARATOR.$entry;
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

    private function ensureStorageDirectory(): void
    {
        if (!is_dir($this->fontDirectory) && !@mkdir($this->fontDirectory, 0775, true) && !is_dir($this->fontDirectory)) {
            throw new CustomFontException('templates.fonts.validation.storage');
        }

        if (!is_writable($this->fontDirectory)) {
            throw new CustomFontException('templates.fonts.validation.storage');
        }
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
