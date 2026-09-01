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

use App\Entity\Appartment;
use App\Entity\Subsidiary;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

class SubsidiaryService
{
    private $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public function getObjectFromForm(Request $request, $id = 'new')
    {
        $object = null;

        if ('new' === $id) {
            $object = new Subsidiary();
        } else {
            $object = $this->em->getRepository(Subsidiary::class)->find($id);
        }

        $object->setName($request->request->get('name-'.$id));
        $object->setDescription($request->request->get('description-'.$id));
        $object->setInvoiceNumberPattern($request->request->get('invoice-number-pattern-'.$id));
        $object->setOpeningHours($this->getOpeningHoursFromForm($request, $id));

        return $object;
    }

    /**
     * Reads the weekday grid of the object form into the shape the entity stores:
     * weekday => list of [from, to]. Empty and half-filled ranges are left in place
     * here and dropped by Subsidiary::setOpeningHours(), so normalisation lives in
     * exactly one spot.
     *
     * @param int|string $id
     *
     * @return array<int, list<array{0: string, 1: string}>>
     */
    private function getOpeningHoursFromForm(Request $request, $id): array
    {
        $hours = [];

        foreach ($request->request->all('opening-hours-'.$id) as $weekday => $ranges) {
            if (!is_array($ranges)) {
                continue;
            }

            foreach ($ranges as $range) {
                if (!is_array($range)) {
                    continue;
                }

                $hours[(int) $weekday][] = [
                    (string) ($range['from'] ?? ''),
                    (string) ($range['to'] ?? ''),
                ];
            }
        }

        return $hours;
    }

    public function deleteObject(Subsidiary $object)
    {
        $appartments = $this->em->getRepository(Appartment::class)->findBy(['object' => $object]);

        if (0 == count($appartments)) {
            $this->em->remove($object);
            $this->em->flush();

            return true;
        } else {
            return false;
        }
    }
}
