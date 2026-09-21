<?php

declare(strict_types=1);

namespace App\Dto;

/** Groups the available regular, bold and italic files under one stable mPDF alias. */
final readonly class CustomFontFamily
{
    /**
     * @param array<string, CustomFontFace> $faces keyed by R, B, I or BI
     */
    public function __construct(
        public string $name,
        public string $alias,
        public string $fallback,
        public array $faces,
        public bool $requiresOpenTypeLayout,
    ) {
    }

    public function isUsable(): bool
    {
        return isset($this->faces[CustomFontFace::REGULAR]);
    }

    public function getFace(string $style): ?CustomFontFace
    {
        return $this->faces[$style] ?? null;
    }

    /**
     * @return array<string, string|int>
     */
    public function getMpdfFontData(): array
    {
        $fontData = [];
        foreach ($this->faces as $style => $face) {
            $fontData[$style] = $face->filename;
        }

        if ($this->requiresOpenTypeLayout) {
            $fontData['useOTL'] = 0xFF;
        }

        return $fontData;
    }
}
