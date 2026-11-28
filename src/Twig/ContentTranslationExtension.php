<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Translation\ContentTranslatable;
use App\Service\Localization\ContentTranslator;
use App\Service\Localization\GuestLocaleService;
use App\Service\Localization\GuestSalutations;
use App\Service\OnlineBooking\PublicPricingService;
use Symfony\Component\Translation\LocaleSwitcher;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Guest languages in templates.
 *
 * `{{ category|localized('name') }}` prints an operator text in the active language, falling
 * back to the installation language. Use it only where guests read the text (public pages,
 * guest mails); everywhere else print the field itself. Salutations work the same way:
 * `{{ customer.salutation|salutation_label }}`, and salutation_choices() feeds a select whose
 * values are the stored salutations.
 */
final class ContentTranslationExtension extends AbstractExtension
{
    public function __construct(
        private readonly ContentTranslator $contentTranslator,
        private readonly GuestLocaleService $guestLocales,
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly GuestSalutations $salutations,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('localized', $this->localized(...)),
            // Money grouped like the active language, as the public pages print it.
            new TwigFilter('salutation_label', fn (?string $salutation): string => null === $salutation || '' === $salutation ? '' : $this->salutations->label($salutation)),
            new TwigFilter('localized_amount', fn (float|int|string $amount): string => PublicPricingService::formatAmount((float) $amount, $this->localeSwitcher->getLocale())),
            // A date as the active language writes it (28.11.2026, Nov 28, 2026, …).
            new TwigFilter('localized_date', $this->localizedDate(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('guest_locales', $this->guestLocales->guestLocales(...)),
            new TwigFunction('guest_extra_locales', $this->guestLocales->extraLocales(...)),
            new TwigFunction('base_locale', $this->guestLocales->baseLocale(...)),
            new TwigFunction('language_name', $this->guestLocales->languageName(...)),
            new TwigFunction('salutation_choices', $this->salutations->choices(...)),
        ];
    }

    /**
     * Formats a Y-m-d string or a date in the active language. Anything that is not a
     * date is returned unchanged, so a half-typed value still shows what the guest sent.
     */
    public function localizedDate(\DateTimeInterface|string|null $date): string
    {
        if (null === $date || '' === $date) {
            return '';
        }

        if (is_string($date)) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (false === $parsed || $parsed->format('Y-m-d') !== $date) {
                return $date;
            }
            $date = $parsed;
        }

        // Only the calendar day matters. Re-anchor it in UTC so an offset or
        // abbreviation timezone can neither shift the day nor trip up intl.
        $day = new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone('UTC'));
        $formatter = \IntlDateFormatter::create(
            $this->localeSwitcher->getLocale(),
            \IntlDateFormatter::MEDIUM,
            \IntlDateFormatter::NONE,
            'UTC',
        );
        $formatted = null === $formatter ? false : $formatter->format($day);

        return false === $formatted ? $day->format('Y-m-d') : $formatted;
    }

    public function localized(?ContentTranslatable $entity, string $field, ?string $locale = null): ?string
    {
        return $this->contentTranslator->text($entity, $field, $locale);
    }
}
