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
use App\Entity\InvoiceAppartment;
use App\Entity\InvoicePosition;
use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Entity\Template;
use App\Interfaces\ITemplatePreviewProvider;
use App\Service\ReservationService;
use Doctrine\ORM\EntityManagerInterface;

// apartmentPositions/miscPositions are plain arrays of stdClass-like objects built by
// ReservationService::getTotalPricesForTemplate() – not Doctrine entities.

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
        $reservation = $this->em->getRepository(Reservation::class)->findOneBy([], ['id' => 'DESC']);
        if ($reservation instanceof Reservation) {
            return ['reservationIds' => (string) $reservation->getId()];
        }

        return [];
    }

    public function buildPreviewRenderParams(Template $template, array $ctx): array
    {
        $ids = $this->normalizeIds($ctx['reservationIds'] ?? null);
        if (!empty($ids)) {
            $params = $this->buildRenderParams($template, $ids);
            if (!empty($params)) {
                return $params;
            }
            $ctx['_previewWarning'] = 'templates.preview.reservation.notfound';
            $ctx['_previewWarningVars'] = ['%value%' => implode(', ', $ids)];
        }

        return $this->buildSampleParams($ctx);
    }

    public function buildRenderParams(Template $template, mixed $input): array
    {
        $reservations = $this->resolveReservations($input);
        if (empty($reservations)) {
            return [];
        }

        return $this->reservationService->buildTemplateRenderParams($template, $reservations);
    }

    public function getAvailableSnippets(): array
    {
        return [
            [
                'id' => 'reservation.booker.salutation',
                'label' => 'customer.salutation',
                'group' => 'Reservation',
                'complexity' => 'simple',
                'content' => '[[ reservation1.booker.salutation ]]',
            ],
            [
                'id' => 'reservation.booker.lastname',
                'label' => 'customer.lastname',
                'group' => 'Reservation',
                'complexity' => 'simple',
                'content' => '[[ reservation1.booker.lastname ]]',
            ],
            [
                'id' => 'reservation.booker.firstname',
                'label' => 'customer.firstname',
                'group' => 'Reservation',
                'complexity' => 'simple',
                'content' => '[[ reservation1.booker.firstname ]]',
            ],
            [
                'id' => 'reservation.booker.birthday',
                'label' => 'customer.birthday',
                'group' => 'Reservation',
                'complexity' => 'simple',
                'content' => "[[ reservation1.booker.birthday|date('d.m.Y') ]]",
            ],
            [
                'id' => 'reservation.address.company',
                'label' => 'templates.editor.company',
                'group' => 'Reservation',
                'complexity' => 'simple',
                'content' => '[[ address.company ]]',
            ],
            [
                'id' => 'reservation.address.address',
                'label' => 'templates.editor.address',
                'group' => 'Reservation',
                'complexity' => 'simple',
                'content' => '[[ address.address ]]',
            ],
            [
                'id' => 'reservation.address.zip',
                'label' => 'templates.editor.zip',
                'group' => 'Reservation',
                'complexity' => 'simple',
                'content' => '[[ address.zip ]]',
            ],
            [
                'id' => 'reservation.address.city',
                'label' => 'templates.editor.city',
                'group' => 'Reservation',
                'complexity' => 'simple',
                'content' => '[[ address.city ]]',
            ],
            [
                'id' => 'reservation.address.phone',
                'label' => 'templates.editor.phone',
                'group' => 'Reservation',
                'complexity' => 'simple',
                'content' => '[[ address.phone ]]',
            ],
            [
                'id' => 'reservation.price.total',
                'label' => 'templates.editor.price.total',
                'group' => 'Totals',
                'complexity' => 'simple',
                'content' => '[[ sumApartment ]] €',
            ],
            [
                'id' => 'reservation.price.misc',
                'label' => 'templates.editor.misc.total',
                'group' => 'Totals',
                'complexity' => 'simple',
                'content' => '[[ sumMisc ]] €',
            ],
            [
                'id' => 'reservation.price.sum',
                'label' => 'templates.editor.price.sum',
                'group' => 'Totals',
                'complexity' => 'simple',
                'content' => '[[ totalPrice ]] €',
            ],
            [
                'id' => 'reservation.apartment_positions.row',
                'label' => 'templates.editor.appartment.positions',
                'group' => 'Reservation',
                'complexity' => 'easy',
                'content' => "<table style=\"width:100%; border-collapse: collapse;\"><tr><th>{{ 'reservation.startdate'|trans }}</th><th>{{ 'reservation.enddate'|trans }}</th><th>{{ 'reservation.appartment.name'|trans }}</th><th>{{ 'reservation.persons'|trans }}</th><th>{{ 'reservation.price'|trans }}</th></tr><tr data-repeat=\"apartmentPositions\" data-repeat-as=\"position\"><td>[[ position.startDate|date('d.m.Y') ]]</td><td>[[ position.endDate|date('d.m.Y') ]]</td><td>[[ position.description ]]</td><td>[[ position.persons ]]</td><td>[[ position.totalPrice ]] €</td></tr></table>",
            ],
            [
                'id' => 'reservation.misc_positions.line',
                'label' => 'templates.editor.misc.positions',
                'group' => 'Reservation',
                'complexity' => 'easy',
                'content' => "<span data-repeat=\"miscPositions\" data-repeat-as=\"position\">[[ position.description ]]: [[ position.totalPrice ]] €<br /></span>",
            ],
        ];
    }

    public function getRenderParamsSchema(): array
    {
        return [
            'reservation1' => ['class' => Reservation::class],
            'address' => ['class' => CustomerAddresses::class],
            'reservations' => ['class' => Reservation::class, 'collection' => true],
            'sumApartment' => ['type' => 'scalar'],
            'sumMisc' => ['type' => 'scalar'],
            'totalPrice' => ['type' => 'scalar'],
            'sumApartmentRaw' => ['type' => 'scalar'],
            'sumMiscRaw' => ['type' => 'scalar'],
            'totalPriceRaw' => ['type' => 'scalar'],
            'apartmentPositions' => ['class' => InvoiceAppartment::class, 'collection' => true],
            'miscPositions' => ['class' => InvoicePosition::class, 'collection' => true],
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
     * Resolve reservation entities from mixed render input.
     *
     * @return array<int, Reservation>
     */
    protected function resolveReservations(mixed $input): array
    {
        if ($input instanceof Reservation) {
            return [$input];
        }

        if (is_array($input)) {
            $allReservations = !empty($input)
                && array_reduce($input, static fn (bool $carry, mixed $item) => $carry && $item instanceof Reservation, true);
            if ($allReservations) {
                return $input;
            }
        }

        $ids = $this->normalizeIds($input);
        if (empty($ids)) {
            return [];
        }

        return $this->em->getRepository(Reservation::class)->findBy(['id' => $ids]);
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
