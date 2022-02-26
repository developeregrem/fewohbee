<?php

namespace App\Service;

use Yasumi\Yasumi;
use Yasumi\Holiday;
use Yasumi\Filters\OnFilter;
use Yasumi\Provider\AbstractProvider;
use Symfony\Component\Intl\Countries;
use Symfony\Contracts\Translation\TranslatorInterface;

use App\Entity\Appartment;
use App\Entity\Reservation;
use App\Entity\CalendarSync;

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
    
    public function getIcalContent(CalendarSync $sync): string {
        $room = $sync->getApartment();
        $content = $this->getIcalHeader($room);
        
        /* @var $reservation \App\Entity\Reservation */
        foreach($room->getReservations() as $reservation) {
            // filter reservation status
            if( $sync->getReservationStatus()->contains($reservation->getReservationStatus()) ) {
                $content .= $this->getIcalEventBody($reservation, $sync);
            }                       
        }
        
        $content .= $this->getIcalFooter();
        
        return $content;
    }
    
    private function getIcalHeader(Appartment $room): string {
        if(ini_get('date.timezone') && strlen(ini_get('date.timezone') > 0)) {
            $timezone = ini_get('date.timezone');
        } else {
            $timezone = "Europe/Berlin";
        }
        
        return  "BEGIN:VCALENDAR\r\n" .
                "PRODID:-//FewohBee//Calendar 1.0//EN\r\nVERSION:2.0\r\n" .
                "CALSCALE:GREGORIAN\r\n" .
                "METHOD:PUBLISH\r\n" .
                "X-WR-CALNAME:Bookings Apartment " . $room->getNumber() . "\r\n" . 
                "X-WR-TIMEZONE:" . $timezone . "\r\n";
    }
    
    private function getIcalEventBody(Reservation $resevation, CalendarSync $sync): string {
        //The "DTEND" property for a "VEVENT" calendar component specifies the non-inclusive end of the event.
        // therefore we need to add one day to the actual end date
        $endDate = clone $resevation->getEndDate();
        $endDate->add(new \DateInterval('P1D'));
        
        if( $sync->getExportGuestName() ) {
            $title = $resevation->getBooker()->getSalutation() . ' ' . $resevation->getBooker()->getFirstname() .' ' .
                    $resevation->getBooker()->getLastname() . ' (' . $title = $resevation->getReservationStatus()->getName() . ')';
        } else {
            $title = $resevation->getReservationStatus()->getName();
        }
        
        return  "BEGIN:VEVENT\r\n" .
                "DTSTART;VALUE=DATE:" . $resevation->getStartDate()->format('Ymd') . "\r\n" .                
                "DTEND;VALUE=DATE:" . $endDate->format('Ymd') . "\r\n" .                
                // the date of the cration of this ics file
                "DTSTAMP: " . date('Ymd') . "T" . date('His') . "Z\r\n" .
                "UID:" . $resevation->getUuid()->toBase32() . "@fewohbee\r\n" .
                // the date of the creation of the reservation itself
                "CREATED:" . $resevation->getReservationDate()->format('Ymd') . "T" . $resevation->getReservationDate()->format('His') . "Z\r\n" .
                "DESCRIPION:\r\n" .
                // the date of the creation of the reservation itself
                "LAST-MODIFIED:" . $resevation->getReservationDate()->format('Ymd') . "T" . $resevation->getReservationDate()->format('His') . "Z\r\n" .
                "LOCATION:\r\n" .
                "SEQUENCE:0\r\n" . 
                "STATUS:CONFIRMED\r\n" .
                "SUMMARY:" . $title . "\r\n" .
                "TRANSP:TRANSPARENT\r\n" .
                "END:VEVENT\r\n";
    }
    
    private function getIcalFooter(): string {
        return "END:VCALENDAR\r\n";
    }

}
