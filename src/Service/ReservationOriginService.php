<?php

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use App\Entity\ReservationOrigin;

class ReservationOriginService
{

    private $em = null;
	private $requestStack;

    /**
     * @param Application $app
     */
    public function __construct(EntityManagerInterface $em, RequestStack $requestStack)
    {
        $this->em = $em;
		$this->requestStack = $requestStack;
    }

    /**
     * Extract form data and return ReservationOrigin object
     * @param Request $request
     * @param string $id
     * @return ReservationOrigin
     */
    public function getOriginFromForm(Request $request, $id = 'new')
    {

        $origin = new ReservationOrigin();
        if ($id !== 'new') {
            $origin = $this->em->getRepository(ReservationOrigin::class)->find($id);
        }

        $origin->setName(trim($request->get("name-" . $id)));

        return $origin;
    }

    /**
     * Delete origin if its not used in reservations
     * @param int $id
     * @return bool
     */
    public function deleteOrigin($id)
    {
        $origin = $this->em->getRepository(ReservationOrigin::class)->find($id);

        if(count($origin->getReservations()) == 0) {
            $this->em->remove($origin);
            $this->em->flush();

            return true;
        } else {
            $this->requestStack->getSession()->getFlashBag()->add('warning', 'reservationorigin.flash.delete.inuse.reservations');

            return false;
        }
    }
}
