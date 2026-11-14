<?php

declare(strict_types=1);

namespace App\Service\Localization;

use App\Service\AppSettingsService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Intl\Languages;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Languages offered to guests on the public pages and the choice of one for a request.
 *
 * The base locale is the installation language (LOCALE): every operator text is stored in it,
 * and it is always offered. The other guest languages come from the settings, limited to the
 * application languages (framework.enabled_locales).
 */
class GuestLocaleService
{
    public const QUERY_PARAMETER = 'lang';

    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        private readonly AppSettingsService $settingsService,
        private readonly LocaleSwitcher $localeSwitcher,
        #[Autowire('%kernel.default_locale%')]
        private readonly string $baseLocale,
        #[Autowire('%kernel.enabled_locales%')]
        private readonly array $enabledLocales,
    ) {
    }

    public function baseLocale(): string
    {
        return $this->baseLocale;
    }

    /**
     * Application languages, base locale first.
     *
     * @return list<string>
     */
    public function enabledLocales(): array
    {
        return array_values(array_unique([$this->baseLocale, ...$this->enabledLocales]));
    }

    /**
     * Languages guests may choose, base locale first. Unset settings offer every enabled language.
     *
     * @return list<string>
     */
    public function guestLocales(): array
    {
        $selected = $this->settingsService->getSettings()->getGuestLocales();
        if (null === $selected) {
            return $this->enabledLocales();
        }

        return array_values(array_filter(
            $this->enabledLocales(),
            fn (string $locale): bool => $locale === $this->baseLocale || \in_array($locale, $selected, true),
        ));
    }

    /**
     * Guest languages that need translated operator texts.
     *
     * @return list<string>
     */
    public function extraLocales(): array
    {
        return array_values(array_diff($this->guestLocales(), [$this->baseLocale]));
    }

    /**
     * Turns the languages ticked in the settings into what is stored: the base locale is always
     * added, unknown codes are dropped, and a selection of every language becomes null so
     * languages added to the application later are offered as well.
     *
     * @param array<mixed> $selected
     *
     * @return list<string>|null
     */
    public function normalizeSelection(array $selected): ?array
    {
        $locales = array_values(array_filter(
            $this->enabledLocales(),
            fn (string $locale): bool => $locale === $this->baseLocale || \in_array($locale, $selected, true),
        ));

        return $locales === $this->enabledLocales() ? null : $locales;
    }

    public function isGuestLocale(string $locale): bool
    {
        return \in_array($locale, $this->guestLocales(), true);
    }

    /**
     * Name of a language: in that language itself for guests choosing one ("English",
     * "Français"), or with $native = false in the active language for the operator ("Englisch").
     */
    public function languageName(string $locale, bool $native = true): string
    {
        $name = Languages::exists($locale)
            ? Languages::getName($locale, $native ? $locale : $this->localeSwitcher->getLocale())
            : $locale;

        return mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1);
    }

    /**
     * Picks the language of a public page and activates it for translator, router and Intl.
     *
     * A valid ?lang= wins. Otherwise the browser's languages decide when $detectBrowserLanguage
     * is set; embedded pages pass false, because the embedding website chooses their language.
     * The base locale is the fallback.
     */
    public function applyToRequest(Request $request, bool $detectBrowserLanguage = true): string
    {
        $locale = $this->requestedLocale($request)
            ?? ($detectBrowserLanguage ? $request->getPreferredLanguage($this->guestLocales()) : null);
        $locale = null !== $locale && $this->isGuestLocale($locale) ? $locale : $this->baseLocale;

        $request->setLocale($locale);
        $this->localeSwitcher->setLocale($locale);

        return $locale;
    }

    /** The explicitly requested guest language (?lang=), or null when absent or not offered. */
    public function requestedLocale(Request $request): ?string
    {
        $requested = $request->query->getString(self::QUERY_PARAMETER);

        return '' !== $requested && $this->isGuestLocale($requested) ? $requested : null;
    }
}
