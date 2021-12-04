<?php

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\Persistence\ManagerRegistry;

use App\Service\CSRFProtectionService;
use App\Service\ReservationOriginService;
use App\Entity\ReservationOrigin;

class ReservationOriginServiceController extends AbstractController
{

    public function __construct(private ManagerRegistry $doctrine)
    {
    }

    /**
     * Index-View
     * @return mixed
     */
    public function indexAction()
    {
        $em = $this->doctrine->getManager();
        $origins = $em->getRepository(ReservationOrigin::class)->findAll();

        return $this->render('ReservationOrigin/index.html.twig', array(
            "origins" => $origins
        ));
    }

    /**
     * Show single entity
     * @param $id
     * @return mixed
     */
    public function getAction(CSRFProtectionService $csrf, $id)
    {
        $em = $this->doctrine->getManager();
        $origin = $em->getRepository(ReservationOrigin::class)->find($id);

        return $this->render('ReservationOrigin/reservationorigin_form_edit.html.twig', array(
            'origin' => $origin,
            'token' => $csrf->getCSRFTokenForForm(),
        ));
    }

    /**
     * Show form for new entity
     * @return mixed
     */
    public function newAction(CSRFProtectionService $csrf)
    {
        $em = $this->doctrine->getManager();

        $origin = new ReservationOrigin();
        $origin->setId("new");

        return $this->render('ReservationOrigin/reservationorigin_form_create.html.twig', array(
            'origin' => $origin,
            'token' => $csrf->getCSRFTokenForForm(),
        ));
    }

    /**
     * Create new entity
     * @param Request $request
     * @return mixed
     */
    public function createAction(CSRFProtectionService $csrf, ReservationOriginService $ros, Request $request)
    {
        $error = false;
        if (($csrf->validateCSRFToken($request))) {
            $origin = $ros->getOriginFromForm($request, "new");

            // check for mandatory fields
            if (strlen($origin->getName()) == 0) {
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
            } else {
                $em = $this->doctrine->getManager();
                $em->persist($origin);
                $em->flush();

                // add succes message
                $this->addFlash('success', 'reservationorigin.flash.create.success');
            }
        }

        return $this->render('feedback.html.twig', array(
            "error" => $error
        ));
    }

    /**
     * update entity end show update result
     * @param Request $request
     * @param $id
     * @return mixed
     */
    public function editAction(CSRFProtectionService $csrf, ReservationOriginService $ros, Request $request, $id)
    {
        $error = false;
        if (($csrf->validateCSRFToken($request))) {
            $origin = $ros->getOriginFromForm($request, $id);
            $em = $this->doctrine->getManager();
            
            // check for mandatory fields
            if (strlen($origin->getName()) == 0) {
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
                // stop auto commit of doctrine with invalid field values
                $em->clear(ReservationOrigin::class);
            } else {                
                $em->persist($origin);
                $em->flush();

                // add succes message           
                $this->addFlash('success', 'reservationorigin.flash.edit.success');
            }
        }

        return $this->render('feedback.html.twig', array(
            "error" => $error
        ));
    }

    /**
     * delete entity
     * @param Request $request
     * @param $id
     * @return string
     */
    public function deleteAction(CSRFProtectionService $csrf, ReservationOriginService $ros, Request $request, $id)
    {

        if ($request->getMethod() == 'POST') {
            if (($csrf->validateCSRFToken($request, true))) {
                $origin = $ros->deleteOrigin($id);
                if($origin) {
                    $this->addFlash('success', 'reservationorigin.flash.delete.success');
                }
            }
            return new Response('', Response::HTTP_NO_CONTENT);
        } else {
            // initial get load (ask for deleting)           
            return $this->render('common/form_delete_entry.html.twig', array(
                "id" => $id,
                'token' => $csrf->getCSRFTokenForForm()
            ));
        }

    }
}