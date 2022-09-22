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

use App\Entity\Price;
use App\Entity\PricePeriod;
use App\Entity\ReservationOrigin;
use App\Entity\RoomCategory;
use App\Service\CSRFProtectionService;
use App\Service\PriceService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/prices')]
class PriceServiceController extends AbstractController
{
    #[Route('/', name: 'prices.overview', methods: ['GET'])]
    public function indexAction(ManagerRegistry $doctrine)
    {
        $em = $doctrine->getManager();
        $prices = $em->getRepository(Price::class)->findAllOrdered();

        return $this->render('Prices/index.html.twig', [
            'prices' => $prices,
        ]);
    }

    #[Route('/{id}/get', name: 'prices.get.price', methods: ['GET'], defaults: ['id' => '0'])]
    public function getPriceAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, $id)
    {
        $em = $doctrine->getManager();
        $price = $em->getRepository(Price::class)->find($id);

        $origins = $em->getRepository(ReservationOrigin::class)->findAll();
        $categories = $em->getRepository(RoomCategory::class)->findAll();

        $originIds = [];
        // extract ids for twig template
        foreach ($price->getReservationOrigins() as $origin) {
            $originIds[] = $origin->getId();
        }

        return $this->render('Prices/price_form_edit.html.twig', [
            'price' => $price,
            'token' => $csrf->getCSRFTokenForForm(),
            'origins' => $origins,
            'originPricesIds' => $originIds,
            'categories' => $categories,
        ]);
    }

    #[Route('/new', name: 'prices.new.price', methods: ['GET'])]
    public function newPriceAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf)
    {
        $em = $doctrine->getManager();

        $origins = $em->getRepository(ReservationOrigin::class)->findAll();
        $categories = $em->getRepository(RoomCategory::class)->findAll();

        $originIds = [];
        // extract ids for twig template, all origins will be preselected
        foreach ($origins as $origin) {
            $originIds[] = $origin->getId();
        }

        $price = new Price();
        $price->setId('new');

        return $this->render('Prices/price_form_create.html.twig', [
            'price' => $price,
            'token' => $csrf->getCSRFTokenForForm(),
            'origins' => $origins,
            'originPricesIds' => $originIds,
            'categories' => $categories,
        ]);
    }

    #[Route('/create', name: 'prices.create.price', methods: ['POST'])]
    public function createPriceAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, PriceService $ps, Request $request)
    {
        $error = false;
        $conflicts = [];
        if ($csrf->validateCSRFToken($request)) {
            $price = $ps->getPriceFromForm($request, 'new');

            // check for mandatory fields
            if (0 == strlen($price->getDescription()) || 0 == strlen($price->getPrice()) || 0 == strlen($price->getVat())
                || 0 == count($price->getReservationOrigins())) {
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
            } else {
                $conflicts = $ps->findConflictingPrices($price);

                // complain conflicts only when current price is marked as acitve
                if (!$price->getActive() || 0 === count($conflicts)) {
                    $em = $doctrine->getManager();
                    $em->persist($price);
                    $em->flush();
                    // add succes message
                    $this->addFlash('success', 'price.flash.create.success');
                } else {
                    $error = true;
                    $this->addFlash('warning', 'price.flash.create.conflict');
                }
            }
        }

        return $this->render('Prices/feedback.html.twig', [
            'error' => $error,
            'conflicts' => $conflicts,
        ]);
    }

    #[Route('/{id}/edit', name: 'prices.edit.price', methods: ['POST'], defaults: ['id' => '0'])]
    public function editPriceAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, PriceService $ps, Request $request, $id)
    {
        $error = false;
        $conflicts = [];
        if ($csrf->validateCSRFToken($request)) {
            $price = $ps->getPriceFromForm($request, $id);
            $em = $doctrine->getManager();

            // check for mandatory fields
            if (0 == strlen($price->getDescription()) || 0 == strlen($price->getPrice()) || 0 == strlen($price->getVat())
                || 0 == count($price->getReservationOrigins())) {
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
                // stop auto commit of doctrine with invalid field values
                $em->clear(Price::class);
                $em->clear(PricePeriod::class);
            } else {
                $conflicts = $ps->findConflictingPrices($price);
                // during edit we need to remove the current item from the list
                $conflicts->removeElement($price);

                // complain conflicts only when current price is marked as acitve
                if (!$price->getActive() || 0 === count($conflicts)) {
                    $em->persist($price);
                    $em->flush();

                    // add succes message
                    $this->addFlash('success', 'price.flash.edit.success');
                } else {
                    $error = true;
                    $this->addFlash('warning', 'price.flash.create.conflict');
                    // stop auto commit of doctrine with invalid field values
                    $em->clear(Price::class);
                    $em->clear(PricePeriod::class);
                }
            }
        }

        return $this->render('Prices/feedback.html.twig', [
            'error' => $error,
            'conflicts' => $conflicts,
        ]);
    }

    #[Route('/{id}/delete', name: 'prices.delete.price', methods: ['GET', 'POST'])]
    public function deletePriceAction(CSRFProtectionService $csrf, PriceService $ps, Request $request, $id)
    {
        if ('POST' == $request->getMethod()) {
            if ($csrf->validateCSRFToken($request, true)) {
                $price = $ps->deletePrice($id);
                $this->addFlash('success', 'price.flash.delete.success');
            }

            return new Response('', Response::HTTP_NO_CONTENT);
        } else {
            // initial get load (ask for deleting)
            return $this->render('common/form_delete_entry.html.twig', [
                'id' => $id,
                'token' => $csrf->getCSRFTokenForForm(),
            ]);
        }
    }
}
