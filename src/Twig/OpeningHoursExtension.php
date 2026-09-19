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
 * Renders a branch's weekly opening hours as one line for letter and email templates.
 *
 * Deliberately not a getter on the entity: weekday names are locale-dependent, and the
 * only locale an entity could reach is \Locale::getDefault(). That value is set from the
 * request, so a template rendered by the workflow cron would fall back to whatever the
 * server happens to be configured with and print English weekdays into a German mail.
 * The translator carries the application's configured locale in every context, CLI
 * included, which is why the formatting lives here.
 */
final class OpeningHoursExtension extends AbstractExtension
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('opening_hours', $this->openingHours(...)),
        ];
    }

    /**
     * One line for the whole week, e.g. "Mo.–Fr. 08:00–12:00, 16:00–19:00 · Sa. 09:00–12:00".
     *
     * Consecutive weekdays sharing the same hours are folded into a range so the common
     * "Monday to Friday" case does not print five near-identical entries. Returns an empty
     * string when no hours are configured, so a template can guard with data-if.
     */
    public function openingHours(?Subsidiary $subsidiary, ?string $locale = null): string
    {
        $hours = $subsidiary?->getOpeningHours() ?? [];
        if ([] === $hours) {
            return '';
        }

        $locale ??= $this->translator->getLocale();

        $groups = [];
        foreach (range(1, 7) as $weekday) {
            $ranges = $hours[$weekday] ?? [];
            if ([] === $ranges) {
                continue;
            }

            $times = implode(', ', array_map(
                static fn (array $range): string => $range[0].'–'.$range[1],
                $ranges
            ));

            $last = array_key_last($groups);
            // Extend the previous group only when it ends on the day right before this
            // one; a gap (e.g. closed on Wednesday) has to start a new group.
            if (null !== $last && $groups[$last]['times'] === $times && $groups[$last]['to'] === $weekday - 1) {
                $groups[$last]['to'] = $weekday;
                continue;
            }

            $groups[] = ['from' => $weekday, 'to' => $weekday, 'times' => $times];
        }

        $parts = [];
        foreach ($groups as $group) {
            $label = self::weekdayName($group['from'], $locale);
            if ($group['to'] > $group['from']) {
                $label .= '–'.self::weekdayName($group['to'], $locale);
            }

            $parts[] = $label.' '.$group['times'];
        }

        return implode(' · ', $parts);
    }

    /**
     * Abbreviated weekday name for an ISO weekday (1 = Monday) in the given locale.
     */
    private static function weekdayName(int $weekday, string $locale): string
    {
        // 2024-01-01 was a Monday, so offsetting from it lands on the wanted weekday.
        $date = (new \DateTimeImmutable('2024-01-01'))->modify('+'.($weekday - 1).' days');

        $formatter = new \IntlDateFormatter(
            $locale,
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::NONE,
            null,
            null,
            'EEE'
        );

        return $formatter->format($date) ?: '';
    }
}
