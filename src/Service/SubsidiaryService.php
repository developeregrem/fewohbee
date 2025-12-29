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

        return $object;
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
