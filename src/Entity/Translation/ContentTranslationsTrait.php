<?php

declare(strict_types=1);

namespace App\Entity\Translation;

use App\Entity\Attribute\TemplateIgnore;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Storage for {@see ContentTranslatable}: one JSON column `translations` shaped
 * {"en": {"name": "…"}}. The installation language is never stored here; it lives in the
 * regular fields.
 */
trait ContentTranslationsTrait
{
    /** @var array<string, array<string, string>>|null */
    #[ORM\Column(name: 'translations', type: Types::JSON, nullable: true)]
    private ?array $translations = null;

    /** Raw storage; templates print a translation with the `localized` filter instead. */
    #[TemplateIgnore]
    public function getContentTranslations(): array
    {
        return $this->translations ?? [];
    }

    public function setContentTranslations(array $translations): static
    {
        $fields = static::translatableFields();
        $clean = [];
        foreach ($translations as $locale => $texts) {
            if (!\is_string($locale) || '' === $locale || !\is_array($texts)) {
                continue;
            }
            foreach ($texts as $field => $text) {
                if (!\in_array($field, $fields, true) || !\is_string($text) || '' === trim($text)) {
                    continue;
                }
                $clean[$locale][$field] = trim($text);
            }
        }
        ksort($clean);
        $this->translations = [] === $clean ? null : $clean;

        return $this;
    }

    public function getContentTranslation(string $locale, string $field): ?string
    {
        return $this->translations[$locale][$field] ?? null;
    }
}
