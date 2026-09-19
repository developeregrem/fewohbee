<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Calendar;

use Symfony\Component\Intl\Countries;
use Symfony\Contracts\Translation\TranslatorInterface;
use Yasumi\Filters\OnFilter;
use Yasumi\Holiday;
use Yasumi\Provider\AbstractProvider;
use Yasumi\ProviderInterface;
use Yasumi\Yasumi;

/** Provides localized public-holiday data independently of calendar synchronization. */
class PublicHolidayService
{
    /** @var array<string, ProviderInterface> */
    private array $holidays = [];

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Build and cache the holiday provider for one exact year, country code and locale.
     *
     * @return ProviderInterface
     */
    private function initPublicdays(int $year, string $code, string $locale): ProviderInterface
    {
        $cacheKey = $year.'|'.$code.'|'.$locale;
        if (!isset($this->holidays[$cacheKey])) {
            $provider = Yasumi::createByISO3166_2($code, $year, $locale);
            $this->includeSubdivisions($provider, $code, $locale);
            $this->holidays[$cacheKey] = $provider;
        }

        return $this->holidays[$cacheKey];
    }

    /**
     * @return Holiday[]
     */
    public function getPublicdaysForDay(\DateTime $date, string $code, string $locale): iterable
    {
        $holidays = $this->initPublicdays((int) $date->format('Y'), $code, $locale);

        return new OnFilter($holidays->getIterator(), $date);
    }

    /** Add holidays of all subdivisions for the given country code. */
    private function includeSubdivisions(ProviderInterface $holidays, string $code, string $locale): void
    {
        foreach ($this->getSubdivisions($code) as $provider) {
            $subdivisionProvider = Yasumi::create($provider, $holidays->getYear(), $locale);
            foreach ($subdivisionProvider->getIterator() as $holiday) {
                $holidays->addHoliday($holiday);
            }
        }
    }

    /**
     * Get translated country names for all supported holiday countries.
     *
     * @return array<string, array{name: string, subdivisions: array<string, array{name: string}>}>
     */
    public function getHolidayCountries(string $locale): array
    {
        $result = [];
        foreach (Yasumi::getProviders() as $code => $class) {
            if (2 === strlen($code)) {
                $result[$code]['name'] = Countries::getName($code, $locale);
                $result[$code]['subdivisions'] = $this->getTranslatedSubdivisions($code, $locale);
            }
        }

        return $result;
    }

    /**
     * Get translated subdivision names for the given country code.
     *
     * @return array<string, array{name: string}>
     */
    public function getTranslatedSubdivisions(string $country, string $locale): array
    {
        $result = [];
        foreach ($this->getSubdivisions($country) as $code => $class) {
            $result[$code]['name'] = $this->translator->trans($code, [], 'subdivisions', $locale);
        }

        return $result;
    }

    /**
     * Get all subdivision providers for a two-letter country code.
     *
     * @return array<string, class-string<AbstractProvider>>
     */
    private function getSubdivisions(string $code): array
    {
        if (strlen($code) > 2) {
            return [];
        }

        return array_filter(
            Yasumi::getProviders(),
            static fn (string $key): bool => $key !== $code && str_starts_with($key, $code.'-'),
            ARRAY_FILTER_USE_KEY
        );
    }
}
