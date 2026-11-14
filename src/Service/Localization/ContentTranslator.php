<?php

declare(strict_types=1);

namespace App\Service\Localization;

use App\Entity\Translation\ContentTranslatable;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Reads operator texts in a guest's language.
 *
 * Only guest-facing views call this. Everything else keeps using the regular getters, which
 * always return the installation language, so nothing translated is ever stored or invoiced.
 */
class ContentTranslator
{
    public function __construct(
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly PropertyAccessorInterface $propertyAccessor,
        private readonly GuestLocaleService $guestLocales,
    ) {
    }

    /**
     * The field in $locale (default: the active locale); falls back to the stored base text
     * when no translation exists.
     */
    public function text(?ContentTranslatable $entity, string $field, ?string $locale = null): ?string
    {
        if (null === $entity) {
            return null;
        }
        if (!\in_array($field, $entity::translatableFields(), true)) {
            throw new \InvalidArgumentException(\sprintf('%s::%s is not translatable.', $entity::class, $field));
        }

        $translated = $entity->getContentTranslation($locale ?? $this->localeSwitcher->getLocale(), $field);
        if (null !== $translated) {
            return $translated;
        }

        $base = $this->propertyAccessor->getValue($entity, $field);

        return null === $base ? null : (string) $base;
    }

    /**
     * Applies translations posted by a hand-built form, shaped [locale => [field => text]].
     *
     * Only the guest languages offered right now and the fields listed in $maxLengths are taken
     * over; texts are cut to the length of their base column. Translations of languages that are
     * not offered at the moment stay untouched, so switching a language off loses nothing.
     *
     * @param array<string, int|null> $maxLengths field => maximum length (null: unlimited)
     */
    public function applySubmitted(ContentTranslatable $entity, mixed $submitted, array $maxLengths): void
    {
        $submitted = \is_array($submitted) ? $submitted : [];
        $translations = $entity->getContentTranslations();
        foreach ($this->guestLocales->extraLocales() as $locale) {
            $texts = \is_array($submitted[$locale] ?? null) ? $submitted[$locale] : [];
            foreach ($maxLengths as $field => $maxLength) {
                $text = \is_string($texts[$field] ?? null) ? trim($texts[$field]) : '';
                $translations[$locale][$field] = null === $maxLength ? $text : mb_substr($text, 0, $maxLength);
            }
        }

        $entity->setContentTranslations($translations);
    }
}
