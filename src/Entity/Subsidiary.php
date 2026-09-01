<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SubsidiaryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SubsidiaryRepository::class)]
#[ORM\Table(name: 'objects')]
class Subsidiary
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;
    #[ORM\Column(type: 'string', length: 45)]
    private $name;
    #[ORM\Column(type: 'string', length: 255)]
    private $description;

    /**
     * Invoice number range of this branch, e.g. 'NORD-<year>-<number:4>'.
     * Null means the global default from AppSettings applies.
     */
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $invoiceNumberPattern = null;

    /**
     * Opening hours of this branch, keyed by ISO weekday (1 = Monday … 7 = Sunday).
     * Each weekday holds a list of [from, to] ranges in 'HH:MM' notation; a weekday
     * missing from the array is closed.
     *
     * Display data only: nothing derives availability, arrival windows or any other
     * behaviour from it. That is why it is a JSON column and not a queryable table —
     * no query ever asks "is the branch open at 14:00?".
     *
     * @var array<int, list<array{0: string, 1: string}>>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $openingHours = null;
    #[ORM\OneToMany(targetEntity: 'Appartment', mappedBy: 'object')]
    private $appartments;

    public function __construct()
    {
        $this->appartments = new ArrayCollection();
    }

    /**
     * Set id.
     *
     * @param int $id
     *
     * @return Subsidiary
     */
    public function setId($id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getAppartments(): ArrayCollection
    {
        return $this->appartments;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function getInvoiceNumberPattern(): ?string
    {
        return $this->invoiceNumberPattern;
    }

    /**
     * An empty string is stored as null so "not configured" has exactly one
     * representation and the fallback to the global pattern stays unambiguous.
     */
    public function setInvoiceNumberPattern(?string $invoiceNumberPattern): self
    {
        $invoiceNumberPattern = null === $invoiceNumberPattern ? null : trim($invoiceNumberPattern);
        $this->invoiceNumberPattern = '' === $invoiceNumberPattern ? null : $invoiceNumberPattern;

        return $this;
    }

    /**
     * @return array<int, list<array{0: string, 1: string}>>
     */
    public function getOpeningHours(): array
    {
        return $this->openingHours ?? [];
    }

    /**
     * Incomplete ranges (only one end filled in) and weekdays outside 1..7 are dropped,
     * and an entirely empty grid is stored as null, so "no opening hours configured" has
     * exactly one representation — the same reasoning as setInvoiceNumberPattern().
     *
     * @param array<int|string, iterable<array{0?: ?string, 1?: ?string}>>|null $openingHours
     */
    public function setOpeningHours(?array $openingHours): self
    {
        $normalized = [];

        foreach ($openingHours ?? [] as $weekday => $ranges) {
            $weekday = (int) $weekday;
            if ($weekday < 1 || $weekday > 7) {
                continue;
            }

            foreach ($ranges as $range) {
                $from = trim((string) ($range[0] ?? ''));
                $to = trim((string) ($range[1] ?? ''));

                // A range only carries meaning once both ends are known.
                if ('' === $from || '' === $to) {
                    continue;
                }

                $normalized[$weekday][] = [$from, $to];
            }
        }

        ksort($normalized);

        $this->openingHours = [] === $normalized ? null : $normalized;

        return $this;
    }

    /**
     * One-line rendering for correspondence templates and overviews, e.g.
     * "Mo.–Fr. 08:00–12:00, 16:00–19:00 · Sa. 09:00–12:00".
     *
     * Consecutive weekdays sharing the same hours are folded into a range so the common
     * "Monday to Friday" case does not print five near-identical entries. Weekday names
     * follow the current default locale, which Symfony sets per request — reading a global
     * keeps the entity free of injected services.
     */
    public function getOpeningHoursFormatted(): string
    {
        $hours = $this->getOpeningHours();
        if ([] === $hours) {
            return '';
        }

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
            $label = self::weekdayName($group['from']);
            if ($group['to'] > $group['from']) {
                $label .= '–'.self::weekdayName($group['to']);
            }

            $parts[] = $label.' '.$group['times'];
        }

        return implode(' · ', $parts);
    }

    /**
     * Abbreviated weekday name for an ISO weekday (1 = Monday) in the default locale.
     */
    private static function weekdayName(int $weekday): string
    {
        // 2024-01-01 was a Monday, so offsetting from it lands on the wanted weekday.
        $date = (new \DateTimeImmutable('2024-01-01'))->modify('+'.($weekday - 1).' days');

        $formatter = new \IntlDateFormatter(
            \Locale::getDefault(),
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::NONE,
            null,
            null,
            'EEE'
        );

        return $formatter->format($date) ?: '';
    }

    public function setAppartments($appartments): void
    {
        $this->appartments = $appartments;
    }

    /**
     * Add appartments.
     *
     * @return Subsidiary
     */
    public function addAppartment(Appartment $appartments)
    {
        $this->appartments[] = $appartments;

        return $this;
    }

    /**
     * Remove appartments.
     */
    public function removeAppartment(Appartment $appartments): void
    {
        $this->appartments->removeElement($appartments);
    }
}
