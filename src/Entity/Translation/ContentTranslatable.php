<?php

declare(strict_types=1);

namespace App\Entity\Translation;

/**
 * An entity whose operator-written texts can be translated for guests.
 *
 * The regular fields always hold the text in the installation language. Translations are kept
 * beside them and are only read where a guest-facing page asks for them explicitly (see
 * ContentTranslator), so invoices, letters and stored records never pick up a translated text.
 */
interface ContentTranslatable
{
    /**
     * Fields of this entity that may be translated; each must be readable as a property.
     *
     * @return list<string>
     */
    public static function translatableFields(): array;

    /** @return array<string, array<string, string>> locale => field => text */
    public function getContentTranslations(): array;

    /** @param array<string, mixed> $translations locale => field => text; blanks and unknown fields are dropped */
    public function setContentTranslations(array $translations): static;

    /** The stored translation of one field, or null when there is none. */
    public function getContentTranslation(string $locale, string $field): ?string;
}
