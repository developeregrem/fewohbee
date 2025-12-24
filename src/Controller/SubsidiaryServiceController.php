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

namespace App\Controller;

use App\Entity\Subsidiary;
use App\Service\CSRFProtectionService;
use App\Service\SubsidiaryService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/settings/objects')]
class SubsidiaryServiceController extends AbstractController
{
    #[Route('/', name: 'objects.overview', methods: ['GET'])]
    public function indexAction(ManagerRegistry $doctrine)
    {
        $em = $doctrine->getManager();
        $objects = $em->getRepository(Subsidiary::class)->findAll();

        return $this->render(
            'Subsidiary/index.html.twig',
            [
                'objects' => $objects,
            ]
        );
    }

    #[Route('/{id}/get', name: 'objects.get.object', methods: ['GET'], defaults: ['id' => '0'])]
    public function getObjectAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, $id)
    {
        $em = $doctrine->getManager();
        $object = $em->getRepository(Subsidiary::class)->find($id);

        return $this->render(
            'Subsidiary/object_form_edit.html.twig',
            [
                'object' => $object,
                'token' => $csrf->getCSRFTokenForForm(),
            ]
        );
    }

    #[Route('/new', name: 'objects.new.object', methods: ['GET'])]
    public function newObjectAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf)
    {
        $em = $doctrine->getManager();
        $sub = new Subsidiary();
        $sub->setId('new');

        return $this->render(
            'Subsidiary/object_form_create.html.twig',
            [
                'object' => $sub,
                'token' => $csrf->getCSRFTokenForForm(),
            ]
        );
    }

    #[Route('/create', name: 'objects.create.object', methods: ['POST'])]
    public function createObjectAction(ManagerRegistry $doctrine, SubsidiaryService $sub, CSRFProtectionService $csrf, Request $request)
    {
        $error = false;
        if ($csrf->validateCSRFToken($request)) {
            /* @var $object \Pensionsverwaltung\Database\Entity\Subsidiary */
            $object = $sub->getObjectFromForm($request, 'new');

            // check for mandatory fields
            if (0 == strlen($object->getName()) || 0 == strlen($object->getDescription())) {
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
            [
                'error' => $error,
            ]
        );
    }

    #[Route('/{id}/edit', name: 'objects.edit.object', methods: ['POST'], defaults: ['id' => '0'])]
    public function editObjectAction(ManagerRegistry $doctrine, SubsidiaryService $sub, CSRFProtectionService $csrf, Request $request, $id)
    {
        $error = false;
        if ($csrf->validateCSRFToken($request)) {
            /* @var $customer \Pensionsverwaltung\Database\Entity\Customer */
            $object = $sub->getObjectFromForm($request, $id);
            $em = $doctrine->getManager();

            // check for mandatory fields
            if (0 == strlen($object->getName()) || 0 == strlen($object->getDescription())) {
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
            [
                'error' => $error,
            ]
        );
    }

    #[Route('/{id}/delete', name: 'objects.delete.object', methods: ['DELETE'])]
    public function deleteObjectAction(SubsidiaryService $sub, CSRFProtectionService $csrf, Request $request, Subsidiary $entry)
    {
        if ($this->isCsrfTokenValid('delete'.$entry->getId(), $request->request->get('_token'))) {
            $object = $sub->deleteObject($entry);

            if ($object) {
                $this->addFlash('success', 'object.flash.delete.success');
            } else {
                $this->addFlash('warning', 'object.flash.delete.error.still.in.use');
            }
        } else {
            $this->addFlash('warning', 'flash.invalidtoken');
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
