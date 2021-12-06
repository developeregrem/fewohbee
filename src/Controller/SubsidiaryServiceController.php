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
use App\Entity\Subsidiary;
use App\Service\SubsidiaryService;

class SubsidiaryServiceController extends AbstractController
{

    public function indexAction(ManagerRegistry $doctrine)
    {
        $em = $doctrine->getManager();
        $objects = $em->getRepository(Subsidiary::class)->findAll();

        return $this->render(
            'Subsidiary/index.html.twig',
            array(
                "objects" => $objects
            )
        );
    }

    public function getObjectAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, $id)
    {
        $em = $doctrine->getManager();
        $object = $em->getRepository(Subsidiary::class)->find($id);

        return $this->render(
            'Subsidiary/object_form_edit.html.twig',
            array(
                'object' => $object,
                'token' => $csrf->getCSRFTokenForForm()
            )
        );
    }

    public function newObjectAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf)
    {
        $em = $doctrine->getManager();
        $sub = new Subsidiary();
        $sub->setId("new");
        return $this->render(
            'Subsidiary/object_form_create.html.twig',
            array(
                'object' => $sub,
                'token' => $csrf->getCSRFTokenForForm()
            )
        );
    }

    public function createObjectAction(ManagerRegistry $doctrine, SubsidiaryService $sub, CSRFProtectionService $csrf, Request $request)
    {
        $error = false;
        if (($csrf->validateCSRFToken($request))) {
            /* @var $object \Pensionsverwaltung\Database\Entity\Subsidiary */
            $object = $sub->getObjectFromForm($request, "new");

            // check for mandatory fields
            if (strlen($object->getName()) == 0 || strlen($object->getDescription()) == 0) {
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
            } else {
                $em = $doctrine->getManager();
                $em->persist($object);
                $em->flush();

                // add succes message
                $this->addFlash('success', 'object.flash.create.success');
            }
        }

        return $this->render(
            'feedback.html.twig',
            array(
                "error" => $error
            )
        );
    }

    public function editObjectAction(ManagerRegistry $doctrine, SubsidiaryService $sub, CSRFProtectionService $csrf, Request $request, $id)
    {
        $error = false;
        if (($csrf->validateCSRFToken($request))) {
            /* @var $customer \Pensionsverwaltung\Database\Entity\Customer */
            $object = $sub->getObjectFromForm($request, $id);
            $em = $doctrine->getManager();
            
            // check for mandatory fields
            if (strlen($object->getName()) == 0 || strlen($object->getDescription()) == 0) {
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
                // stop auto commit of doctrine with invalid field values
                $em->clear(Subsidiary::class);
            } else {                
                $em->persist($object);
                $em->flush();

                // add succes message           
                $this->addFlash('success', 'object.flash.edit.success');
            }
        }

        return $this->render(
            'feedback.html.twig',
            array(
                "error" => $error
            )
        );
    }

    public function deleteObjectAction(SubsidiaryService $sub, CSRFProtectionService $csrf, Request $request, $id)
    {
        if ($request->getMethod() == 'POST') {
            if (($csrf->validateCSRFToken($request, true))) {
                $object = $sub->deleteObject($id);

                if ($object) {
                    $this->addFlash('success', 'object.flash.delete.success');
                } else {
                    $this->addFlash('warning', 'object.flash.delete.error.still.in.use');
                }
            }

            return new Response('', Response::HTTP_NO_CONTENT);
        } else {
            // initial get load (ask for deleting)           
            return $this->render(
                'common/form_delete_entry.html.twig',
                array(
                    "id" => $id,
                    'token' => $csrf->getCSRFTokenForForm()
                )
            );
        }

    }
}