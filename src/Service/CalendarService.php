<?php

namespace App\Service;

use Yasumi\Yasumi;
use Yasumi\Holiday;
use Yasumi\Filters\OnFilter;
use Yasumi\Provider\AbstractProvider;
use Symfony\Component\Intl\Countries;
use Symfony\Contracts\Translation\TranslatorInterface;

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

class CalendarService {
    /* @var $holidays AbstractProvider */
    private static $holidays = null;

    public function __construct(private TranslatorInterface $translator) {
        
    }

    /**
     * 
     * @param int $year
     * @param string $code
     * @param string $locale
     * @return Holiday[]
     */
    private function initPublicdays(int $year, string $code, string $locale): iterable {
        if (self::$holidays === null || self::$holidays->getYear() !== $year) {
            self::$holidays = Yasumi::createByISO3166_2($code, $year, $locale);
            $this->includeSubdivisions($code, $locale);
        }
        return self::$holidays;
    }

    /**
     * 
     * @param \DateTime $date
     * @return Holiday[]
     */
    public function getPublicdaysForDay(\DateTime $date, string $code, string $locale): iterable {
        $this->initPublicdays($date->format('Y'), $code, $locale);
        return new OnFilter(self::$holidays->getIterator(), $date);
    }

    /**
     * Add holidays of all subdivisions for the given country code
     * @param string $code
     * @param string $locale
     * @return void
     */
    private function includeSubdivisions(string $code, string $locale): void {

        $filtered = $this->getSubdivisions($code);

        foreach ($filtered as $provider) {
            $subDivisionProvider = Yasumi::create($provider, self::$holidays->getYear(), $locale);
            foreach ($subDivisionProvider as $holiday) {
                self::$holidays->addHoliday($holiday);
            }
        }
    }

    /**
     * Get translated country names for all countries
     * @param string $locale
     * @return array
     */
    public function getHolidayCountries(string $locale): array {
        $providers = Yasumi::getProviders();
        $result = [];
        foreach ($providers as $code => $class) {
            if (strlen($code) === 2) {
                $result[$code]['name'] = Countries::getName($code, $locale);
                $result[$code]['subdivisions'] = $this->getTranslatedSubdivisions($code);
            }
        }
        return $result;
    }

    /**
     * Get translated names of subdisvisions for the given country code
     * @param string $country
     * @return array
     */
    public function getTranslatedSubdivisions(string $country): array {
        $filtered = $this->getSubdivisions($country);
        $result = [];
        foreach ($filtered as $code => $class) {
            $result[$code]['name'] = $this->translator->trans($code, [], 'subdivisions');
        }
        return $result;
    }

    /**
     * Get a list of all subdivisions for the given country code
     * @param string $code
     * @return array
     */
    private function getSubdivisions(string $code): array {
        // run only if 2 letter country code is provided, otherwise a subdivision country code is provided
        if (strlen($code) > 2) {
            return [];
        }
        $providers = Yasumi::getProviders();
        $filtered = array_filter(
                $providers,
                // N.b. it's ($val, $key) not ($key, $val):
                fn($key) => $key !== $code && str_starts_with($key, $code . '-'),
                ARRAY_FILTER_USE_KEY
        );

        return $filtered;
    }

}
