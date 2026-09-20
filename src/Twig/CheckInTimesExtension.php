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

namespace App\Twig;

use App\Entity\Subsidiary;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Renders a branch's check-in window and check-out time as one line for letter and
 * email templates.
 *
 * Separate from OpeningHoursExtension because it answers a different question: the
 * opening hours say when someone can be reached, these say when a guest may arrive and
 * when the room has to be free. A house can have one without the other.
 *
 * The wording is translated rather than assembled from prose in the template, so a
 * mail rendered by the workflow cron reads the same as one rendered from a request —
 * the same reason the opening hours take their locale from the translator.
 */
final class CheckInTimesExtension extends AbstractExtension
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('check_in_times', $this->checkInTimes(...)),
        ];
    }

    /**
     * One line, e.g. "Check-in 17:00–20:00 Uhr · Check-out bis 10:00 Uhr".
     *
     * Each half is optional: a house may publish an arrival window without a check-out
     * time, or the other way round. Returns an empty string when neither is configured,
     * so a template can guard with data-if.
     */
    public function checkInTimes(?Subsidiary $subsidiary): string
    {
        if (null === $subsidiary) {
            return '';
        }

        $parts = [];

        $from = $subsidiary->getCheckInFrom();
        $until = $subsidiary->getCheckInUntil();
        if (null !== $from) {
            // An upper bound is only printed when there is one; "from 17:00" and
            // "17:00-20:00" are different promises to the guest.
            $parts[] = null === $until
                ? $this->translator->trans('object.check_in.value.from', ['%from%' => $from->format('H:i')])
                : $this->translator->trans('object.check_in.value.window', ['%from%' => $from->format('H:i'), '%until%' => $until->format('H:i')]);
        }

        $checkOut = $subsidiary->getCheckOutUntil();
        if (null !== $checkOut) {
            $parts[] = $this->translator->trans('object.check_out.value', ['%until%' => $checkOut->format('H:i')]);
        }

        return implode(' · ', $parts);
    }
}
