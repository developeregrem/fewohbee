<?php

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <alex.pensionsverwaltung@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

use App\Entity\Subsidiary;
use App\Entity\Appartment;

class SubsidiaryService
{

    private $em = null;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public function getObjectFromForm(Request $request, $id = 'new')
    {

        $object = null;

        if ($id === 'new') {
            $object = new Subsidiary();
        } else {
            $object = $this->em->getRepository(Subsidiary::class)->find($id);
        }

        $object->setName($request->get("name-" . $id));
        $object->setDescription($request->get("description-" . $id));

        return $object;
    }

    public function deleteObject($id)
    {
        $object = $this->em->getRepository(Subsidiary::class)->find($id);

        $appartments = $this->em->getRepository(Appartment::class)->findByObject($id);

        if (count($appartments) == 0) {
            $this->em->remove($object);
            $this->em->flush();

            return true;
        } else {
            return false;
        }
    }
}
