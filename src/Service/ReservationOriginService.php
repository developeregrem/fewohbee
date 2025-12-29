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

        return $origin;
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
