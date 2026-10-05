<?php

declare(strict_types=1);

namespace App\Dto;

/** Describes one locally stored face of a custom PDF font family. */
final readonly class CustomFontFace
{
    public const REGULAR = 'R';
    public const BOLD = 'B';
    public const ITALIC = 'I';
    public const BOLD_ITALIC = 'BI';

    public function __construct(
        public string $style,
        public string $filename,
        public string $path,
        public string $version,
        public string $extension,
    ) {
    }

    public function getCssFontWeight(): string
    {
        return str_contains($this->style, 'B') ? '700' : '400';
    }

    public function getCssFontStyle(): string
    {
        return str_contains($this->style, 'I') ? 'italic' : 'normal';
    }

    public function getCssFormat(): string
    {
        return 'otf' === $this->extension ? 'opentype' : 'truetype';
    }
}
