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
        $object->setOpeningHoursNote($request->request->get('opening-hours-note-'.$id));
        $object->setCheckInFrom($this->readTime($request, 'check-in-from-'.$id));
        $object->setCheckInUntil($this->readTime($request, 'check-in-until-'.$id));
        $object->setCheckOutUntil($this->readTime($request, 'check-out-until-'.$id));
        $object->setCheckInNote($request->request->get('check-in-note-'.$id));

        return $object;
    }

    /**
     * True when the submitted check-in window cannot be read as written.
     *
     * Two cases, both of which would otherwise be stored as something the operator did
     * not mean: a closing time without an opening one (a window that never starts), and
     * a window that ends before it begins. Handled the way the invoice number pattern and
     * the opening hours already are — the controller turns this into a visible warning.
     *
     * A lone $checkInFrom is fine and means "from 17:00, no published end".
     *
     * @param int|string $id
     */
    public function hasInvalidCheckInWindow(Request $request, $id): bool
    {
        $from = $this->readTime($request, 'check-in-from-'.$id);
        $until = $this->readTime($request, 'check-in-until-'.$id);

        if (null === $until) {
            return false;
        }

        return null === $from || $until <= $from;
    }

    /**
     * Reads one 'HH:MM' form field into a time, or null when it is empty or unparsable.
     *
     * The date part is irrelevant — the column is a TIME — but createFromFormat would
     * otherwise fill it with today, so it is pinned to a fixed day to keep two times
     * comparable.
     */
    private function readTime(Request $request, string $field): ?\DateTimeImmutable
    {
        $value = trim((string) $request->request->get($field, ''));
        if ('' === $value) {
            return null;
        }

        $time = \DateTimeImmutable::createFromFormat('!H:i', $value);

        return false === $time ? null : $time;
    }

    /**
     * True when the submitted grid holds a range with only one end filled in.
     *
     * Subsidiary::setOpeningHours() drops such a range, which on its own would leave the
     * operator believing a lone "07:00" had been saved. The controller turns this into a
     * visible warning instead.
     *
     * @param int|string $id
     */
    public function hasIncompleteOpeningHours(Request $request, $id): bool
    {
        foreach ($request->request->all('opening-hours-'.$id) as $ranges) {
            if (!is_array($ranges)) {
                continue;
            }

            foreach ($ranges as $range) {
                if (!is_array($range)) {
                    continue;
                }

                $from = trim((string) ($range['from'] ?? ''));
                $to = trim((string) ($range['to'] ?? ''));

                if (('' === $from) !== ('' === $to)) {
                    return true;
                }
            }
        }

        return false;
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
