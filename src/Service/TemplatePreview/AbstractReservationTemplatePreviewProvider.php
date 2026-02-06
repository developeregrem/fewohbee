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

namespace App\Service\TemplatePreview;

use App\Entity\Appartment;
use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Entity\Template;
use App\Interfaces\ITemplatePreviewProvider;
use App\Service\ReservationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Base implementation for reservation-based template previews.
 */
abstract class AbstractReservationTemplatePreviewProvider implements ITemplatePreviewProvider
{
    public function __construct(
        protected EntityManagerInterface $em,
        protected ReservationService $reservationService
    ) {
    }

    /**
     * Return the template type name this provider supports.
     */
    abstract protected function getSupportedTemplateType(): string;

    public function supportsPreview(Template $template): bool
    {
        $typeName = $template->getTemplateType()?->getName();

        return $typeName === $this->getSupportedTemplateType();
    }

    public function getPreviewContextDefinition(): array
    {
        return [
            [
                'name' => 'reservationIds',
                'type' => 'text',
                'label' => 'templates.preview.reservation',
                'placeholder' => 'templates.preview.reservation.placeholder',
                'help' => 'templates.preview.reservation.help',
            ],
        ];
    }

    public function buildSampleContext(): array
    {
        return [];
    }

    public function buildPreviewRenderParams(Template $template, array $ctx): array
    {
        $ids = $this->normalizeIds($ctx['reservationIds'] ?? null);
        if (!empty($ids)) {
            $reservations = $this->em->getRepository(Reservation::class)->findBy(['id' => $ids]);
            if (!empty($reservations)) {
                return $this->reservationService->getRenderParams($template, $reservations);
            }
            $ctx['_previewWarning'] = 'templates.preview.reservation.notfound';
            $ctx['_previewWarningVars'] = ['%value%' => implode(', ', $ids)];
        }

        return $this->buildSampleParams($ctx);
    }

    public function getAvailableSnippets(): array
    {
        return [
            [
                'id' => 'reservation.booker.firstname',
                'label' => 'templates.preview.snippet.booker_firstname',
                'group' => 'Reservation',
                'complexity' => 'simple',
                'content' => '[[ reservation.booker.firstname ]]',
            ],
            [
                'id' => 'reservation.booker.lastname',
                'label' => 'templates.preview.snippet.booker_lastname',
                'group' => 'Reservation',
                'complexity' => 'simple',
                'content' => '[[ reservation.booker.lastname ]]',
            ],
            [
                'id' => 'reservation.dates',
                'label' => 'templates.preview.snippet.reservation_dates',
                'group' => 'Reservation',
                'complexity' => 'simple',
                'content' => '[[ reservation.startDate|date(\'d.m.Y\') ]] - [[ reservation.endDate|date(\'d.m.Y\') ]]',
            ],
            [
                'id' => 'reservation.price.total',
                'label' => 'templates.preview.snippet.reservation_total',
                'group' => 'Reservation',
                'complexity' => 'simple',
                'content' => '[[ totalPrice ]]',
            ],
            [
                'id' => 'reservation.loop',
                'label' => 'templates.preview.snippet.reservation_loop',
                'group' => 'Reservation',
                'complexity' => 'advanced',
                'content' => "[% for reservation in reservations %]\n<p>[[ reservation.booker.lastname ]]</p>\n[% endfor %]",
            ],
        ];
    }

    /**
     * Normalize comma-separated reservation ids.
     *
     * @return int[]
     */
    protected function normalizeIds(mixed $raw): array
    {
        if (is_array($raw)) {
            $values = $raw;
        } elseif (is_string($raw)) {
            $values = array_filter(array_map('trim', explode(',', $raw)));
        } else {
            $values = [];
        }

        $ids = [];
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Build a minimal sample payload for reservation templates.
     */
    protected function buildSampleParams(array $ctx = []): array
    {
        $customer = new Customer();
        $customer->setSalutation('Herr');
        $customer->setFirstname('Max');
        $customer->setLastname('Mustermann');

        $address = new CustomerAddresses();
        $address->setType('private');
        $address->setAddress('Musterstraße 1');
        $address->setZip('12345');
        $address->setCity('Musterstadt');
        $address->setCountry('DE');
        $address->setEmail('max@example.com');
        $address->setPhone('+49 30 123456');

        $appartment = new Appartment();
        $appartment->setNumber('1');
        $appartment->setDescription('Doppelzimmer');
        $appartment->setBedsMax(2);

        $status = new ReservationStatus();
        $status->setName('Bestätigt');
        $status->setColor('#4caf50');
        $status->setContrastColor('#ffffff');

        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTimeImmutable('today'));
        $reservation->setEndDate(new \DateTimeImmutable('today +3 days'));
        $reservation->setPersons(2);
        $reservation->setBooker($customer);
        $reservation->setAppartment($appartment);
        $reservation->setReservationStatus($status);

        $params = [
            'reservation1' => $reservation,
            'address' => $address,
            'reservations' => [$reservation],
            'sumApartmentRaw' => 360.0,
            'sumMiscRaw' => 40.0,
            'totalPriceRaw' => 400.0,
            'sumApartment' => number_format(360.0, 2, ',', '.'),
            'sumMisc' => number_format(40.0, 2, ',', '.'),
            'totalPrice' => number_format(400.0, 2, ',', '.'),
            'apartmentPositions' => [],
            'miscPositions' => [],
        ];

        return $this->appendPreviewMeta($params, $ctx);
    }

    /**
     * Append preview metadata such as warnings to the template params.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $ctx
     *
     * @return array<string, mixed>
     */
    protected function appendPreviewMeta(array $params, array $ctx): array
    {
        if (!empty($ctx['_previewWarning'])) {
            $params['_previewWarning'] = $ctx['_previewWarning'];
            $params['_previewWarningVars'] = $ctx['_previewWarningVars'] ?? [];
        }

        return $params;
    }
}
