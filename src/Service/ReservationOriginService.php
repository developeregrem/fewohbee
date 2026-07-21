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
            $commission = str_replace(',', '.', trim((string) $request->request->get('commission-'.$id, '')));
            $origin->setCommissionPercent('' === $commission ? null : $commission);

            $paymentFee = str_replace(',', '.', trim((string) $request->request->get('payment-fee-'.$id, '')));
            $origin->setPaymentFeePercent('' === $paymentFee ? null : $paymentFee);
        } else {
            $origin->setCommissionPercent(null);
            $origin->setPaymentFeePercent(null);
        }

        return $origin;
    }

    /**
     * True when the OTA-fee flag is set but neither percentage was given, so the
     * origin would be marked as charging fees with no fee to charge.
     *
     * @param string $id
     */
    public function isSurchargeFlagSetWithoutValue(Request $request, $id, ReservationOrigin $origin): bool
    {
        return (bool) $request->request->get('surcharge-enabled-'.$id)
            && null === $origin->getCommissionPercent()
            && null === $origin->getPaymentFeePercent();
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
