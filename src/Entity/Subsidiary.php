<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Attribute\TemplateIgnore;
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

    /**
     * Free text shown with the opening hours, e.g. "outside these hours by arrangement,
     * phone 0123 456". Kept separate from the hours themselves because it is prose: it
     * carries the exceptions a grid cannot express.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $openingHoursNote = null;

    /**
     * Earliest time a guest can check in, e.g. 17:00. Null means the house does not
     * publish one.
     *
     * House policy, not a property of any single stay: Reservation::arrivalTime holds
     * when a particular guest announced they would turn up, which is a different
     * statement and deliberately stays independent of this.
     */
    #[ORM\Column(type: 'time_immutable', nullable: true)]
    private ?\DateTimeImmutable $checkInFrom = null;

    /**
     * Latest time a guest can check in, closing the arrival window. Null means there is
     * no published upper bound — arrivals after $checkInFrom stay possible.
     */
    #[ORM\Column(type: 'time_immutable', nullable: true)]
    private ?\DateTimeImmutable $checkInUntil = null;

    /** Time by which the room has to be vacated on the day of departure, e.g. 10:00. */
    #[ORM\Column(type: 'time_immutable', nullable: true)]
    private ?\DateTimeImmutable $checkOutUntil = null;

    /**
     * Free text shown with the check-in times, e.g. "later arrival by arrangement, we
     * will let you in by phone". The prose counterpart to the times, for the exception a
     * pair of clock values cannot carry — same split as the opening hours and their note.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $checkInNote = null;
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
    #[TemplateIgnore]
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

    public function getOpeningHoursNote(): ?string
    {
        return $this->openingHoursNote;
    }

    /**
     * An empty string is stored as null, so "no note" has one representation.
     */
    public function setOpeningHoursNote(?string $openingHoursNote): self
    {
        $openingHoursNote = null === $openingHoursNote ? null : trim($openingHoursNote);
        $this->openingHoursNote = '' === $openingHoursNote ? null : $openingHoursNote;

        return $this;
    }

    public function getCheckInFrom(): ?\DateTimeImmutable
    {
        return $this->checkInFrom;
    }

    public function setCheckInFrom(?\DateTimeImmutable $checkInFrom): self
    {
        $this->checkInFrom = $checkInFrom;

        return $this;
    }

    public function getCheckInUntil(): ?\DateTimeImmutable
    {
        return $this->checkInUntil;
    }

    public function setCheckInUntil(?\DateTimeImmutable $checkInUntil): self
    {
        $this->checkInUntil = $checkInUntil;

        return $this;
    }

    public function getCheckOutUntil(): ?\DateTimeImmutable
    {
        return $this->checkOutUntil;
    }

    public function setCheckOutUntil(?\DateTimeImmutable $checkOutUntil): self
    {
        $this->checkOutUntil = $checkOutUntil;

        return $this;
    }

    public function getCheckInNote(): ?string
    {
        return $this->checkInNote;
    }

    /**
     * An empty string is stored as null, so "no note" has one representation.
     */
    public function setCheckInNote(?string $checkInNote): self
    {
        $checkInNote = null === $checkInNote ? null : trim($checkInNote);
        $this->checkInNote = '' === $checkInNote ? null : $checkInNote;

        return $this;
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
