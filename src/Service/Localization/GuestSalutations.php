<?php

declare(strict_types=1);

namespace App\Service\Localization;

use App\Entity\AppSettings;
use App\Service\AppSettingsService;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The salutations the operator offers, and how guests read them.
 *
 * Customers, invoices and letters keep a salutation in the installation language ("Frau"); that
 * stored text is the value every form submits. A guest reads it in their language: the
 * operator's translation, else the application's own translation of the built-in entries
 * ("Ms", "Mr", "Family" — older installations list these keys), else the stored text.
 */
class GuestSalutations
{
    /** Longest salutation a customer record holds (customers.salutation). */
    public const MAX_LENGTH = 20;

    public function __construct(
        private readonly AppSettingsService $settingsService,
        private readonly TranslatorInterface $translator,
        private readonly GuestLocaleService $guestLocales,
        private readonly LocaleSwitcher $localeSwitcher,
    ) {
    }

    /**
     * The stored salutations in the configured order.
     *
     * @return list<string>
     */
    public function offered(): array
    {
        // Keys are cast back, as PHP turns numeric strings into integer keys.
        return array_map(strval(...), array_keys($this->entriesByStoredText()));
    }

    /** Wording of a stored salutation in $locale (default: the active language). */
    public function label(string $stored, ?string $locale = null): string
    {
        $locale ??= $this->localeSwitcher->getLocale();
        $translated = $this->settings()->getCustomerSalutationTranslations()[$stored][$locale] ?? null;
        if (\is_string($translated) && '' !== $translated) {
            return $translated;
        }

        $entry = $this->entriesByStoredText()[$stored] ?? null;
        if (null !== $entry && $entry !== $stored) {
            // A built-in entry: the application translates it into every language.
            return $this->translator->trans($entry, [], 'messages', $locale);
        }

        return $stored;
    }

    /**
     * Choices for a salutation field: wording in the active language => stored text.
     *
     * @return array<string, string>
     */
    public function choices(?string $locale = null): array
    {
        $choices = [];
        foreach ($this->offered() as $stored) {
            $choices[$this->label($stored, $locale)] = $stored;
        }

        return $choices;
    }

    /**
     * The stored text of a salutation given in any guest language, as stored text or as a
     * built-in key ("Mr"); null when it is none of the offered ones.
     */
    public function normalize(?string $given): ?string
    {
        $given = trim((string) $given);
        if ('' === $given) {
            return null;
        }

        foreach ($this->entriesByStoredText() as $stored => $entry) {
            $stored = (string) $stored;
            if ($given === $stored || $given === $entry) {
                return $stored;
            }
            foreach ($this->guestLocales->enabledLocales() as $locale) {
                if ($given === $this->label($stored, $locale)) {
                    return $stored;
                }
            }
        }

        return null;
    }

    /**
     * Rows of the settings table: the stored text and its wording per further guest language.
     * A language shows a value only where it differs from the stored text.
     *
     * @return list<array<string, string>> ['salutation' => stored, <locale> => wording, …]
     */
    public function settingsRows(): array
    {
        $rows = [];
        foreach ($this->offered() as $stored) {
            $row = ['salutation' => $stored];
            foreach ($this->guestLocales->extraLocales() as $locale) {
                $label = $this->label($stored, $locale);
                $row[$locale] = $label !== $stored ? $label : '';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Stores the edited settings table. Blank and repeated salutations are dropped; a
     * translation equal to the stored text is not kept. Languages not offered right now keep
     * their translations as long as the salutation stays.
     *
     * @param array<mixed> $rows as submitted, shaped like {@see settingsRows()}
     */
    public function applySettingsRows(AppSettings $settings, array $rows): void
    {
        $previous = $settings->getCustomerSalutationTranslations();
        $offered = [];
        $translations = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $stored = mb_substr(trim(\is_string($row['salutation'] ?? null) ? $row['salutation'] : ''), 0, self::MAX_LENGTH);
            if ('' === $stored || \in_array($stored, $offered, true)) {
                continue;
            }
            $offered[] = $stored;

            $wordings = $previous[$stored] ?? [];
            foreach ($this->guestLocales->extraLocales() as $locale) {
                $wording = trim(\is_string($row[$locale] ?? null) ? $row[$locale] : '');
                unset($wordings[$locale]);
                if ('' !== $wording && $wording !== $stored) {
                    $wordings[$locale] = mb_substr($wording, 0, 50);
                }
            }
            if ([] !== $wordings) {
                ksort($wordings);
                $translations[$stored] = $wordings;
            }
        }

        $settings->setCustomerSalutations($offered);
        $settings->setCustomerSalutationTranslations([] === $translations ? null : $translations);
    }

    /**
     * Configured entries keyed by their stored text: a built-in key ("Ms") stores as its
     * translation into the installation language ("Frau"), any other entry as itself.
     *
     * @return array<string, string> stored text => configured entry
     */
    private function entriesByStoredText(): array
    {
        $entries = [];
        foreach ($this->settings()->getCustomerSalutations() as $entry) {
            $entry = trim($entry);
            if ('' === $entry) {
                continue;
            }
            $stored = $this->translator->trans($entry, [], 'messages', $this->guestLocales->baseLocale());
            $entries[$stored] ??= $entry;
        }

        return $entries;
    }

    private function settings(): AppSettings
    {
        return $this->settingsService->getSettings();
    }
}
