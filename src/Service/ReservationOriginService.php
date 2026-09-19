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

namespace App\Service;

use App\Entity\Enum\PaymentCollection;
use App\Entity\ReservationOrigin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class ReservationOriginService
{
    private $em;
    private $requestStack;

    public function __construct(EntityManagerInterface $em, RequestStack $requestStack)
    {
        $this->em = $em;
        $this->requestStack = $requestStack;
    }

    /**
     * Extract form data and return ReservationOrigin object.
     *
     * @param string $id
     *
     * @return ReservationOrigin
     */
    public function getOriginFromForm(Request $request, $id = 'new')
    {
        $origin = new ReservationOrigin();
        if ('new' !== $id) {
            $origin = $this->em->getRepository(ReservationOrigin::class)->find($id);
        }

        $origin->setName(trim($request->request->get('name-'.$id)));
        $color = strtolower(trim((string) $request->request->get('color-'.$id)));
        $colorEnabled = $request->request->getBoolean('color-enabled-'.$id);
        $origin->setColor($colorEnabled && 1 === preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : null);

        // The two percentages only apply while the origin is flagged as charging
        // them; without the flag they are cleared, whatever the hidden fields
        // still carried.
        if ($request->request->get('surcharge-enabled-'.$id)) {
            $origin->setCommissionPercent($this->percentFromForm($request, 'commission-'.$id));
            $origin->setPaymentFeePercent($this->percentFromForm($request, 'payment-fee-'.$id));

            // Who collects the money is only asked where fees are charged, since
            // that is all it decides. An unreadable value falls back to the
            // house, which is the answer that charges nothing.
            $origin->setPaymentCollection($this->collectionFromForm($request, 'payment-collection-'.$id));
            $origin->setTouristTaxCollection($this->collectionFromForm($request, 'tourist-tax-collection-'.$id));
        } else {
            $origin->setCommissionPercent(null);
            $origin->setPaymentFeePercent(null);
            $origin->setPaymentCollection(PaymentCollection::PROPERTY);
            $origin->setTouristTaxCollection(PaymentCollection::PROPERTY);
        }

        return $origin;
    }

    /**
     * A percentage as it may have been typed, or null where nothing readable
     * was given.
     *
     * Anything the column cannot hold is dropped rather than handed on: the
     * field is a decimal(5,2), and a value it cannot take is either rounded
     * away or refused by the database further down, where nobody connects it
     * to what they typed. What is dropped here is reported separately, see
     * findSurchargeValueError().
     */
    private function percentFromForm(Request $request, string $field): ?string
    {
        $raw = str_replace(',', '.', trim((string) $request->request->get($field, '')));

        return self::isValidPercent($raw) ? $raw : null;
    }

    /** Whether a typed percentage is a figure this fee can actually be charged at. */
    private static function isValidPercent(string $raw): bool
    {
        return 1 === preg_match('/^\\d{1,3}(\\.\\d{1,2})?$/', $raw) && (float) $raw <= 100.0;
    }

    /**
     * The key of the message explaining why the form cannot be saved, or null
     * when it can.
     *
     * The HTML fields carry min, max and step, which is a courtesy rather than
     * a guarantee - they are trivially bypassed, and what arrives here has to
     * stand on its own.
     *
     * @param string $id
     */
    public function findSurchargeValueError(Request $request, $id, ReservationOrigin $origin): ?string
    {
        if (!$request->request->get('surcharge-enabled-'.$id)) {
            return null;
        }

        foreach (['commission-'.$id, 'payment-fee-'.$id] as $field) {
            $raw = str_replace(',', '.', trim((string) $request->request->get($field, '')));
            if ('' !== $raw && !self::isValidPercent($raw)) {
                return 'reservationorigin.flash.surcharge_invalid';
            }
        }

        // Flagged as charging fees with no fee to charge.
        if (null === $origin->getCommissionPercent() && null === $origin->getPaymentFeePercent()) {
            return 'reservationorigin.flash.surcharge_required';
        }

        return null;
    }

    private function collectionFromForm(Request $request, string $field): PaymentCollection
    {
        return PaymentCollection::tryFrom((string) $request->request->get($field, '')) ?? PaymentCollection::PROPERTY;
    }

    /**
     * Delete origin if its not used in reservations.
     *
     * @return bool
     */
    public function deleteOrigin(ReservationOrigin $origin)
    {
        if (0 == count($origin->getReservations())) {
            $this->em->remove($origin);
            $this->em->flush();

            return true;
        } else {
            $this->requestStack->getSession()->getFlashBag()->add('warning', 'reservationorigin.flash.delete.inuse.reservations');

            return false;
        }
    }
}
