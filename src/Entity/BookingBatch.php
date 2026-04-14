<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BookingBatchRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BookingBatchRepository::class)]
#[ORM\Table(name: 'booking_batches')]
class BookingBatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $year;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $month;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isClosed = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isExported = false;

    #[ORM\Column(type: Types::DECIMAL, precision: 13, scale: 2, nullable: true)]
    private ?string $cashStart = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 13, scale: 2, nullable: true)]
    private ?string $cashEnd = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, BookingEntry> */
    #[ORM\OneToMany(targetEntity: BookingEntry::class, mappedBy: 'bookingBatch', cascade: ['remove'])]
    #[ORM\OrderBy(['date' => 'ASC', 'documentNumber' => 'ASC'])]
    private Collection $entries;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->entries = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getYear(): int
    {
        return $this->year;
    }

    public function setYear(int $year): self
    {
        $this->year = $year;

        return $this;
    }

    public function getMonth(): int
    {
        return $this->month;
    }

    public function setMonth(int $month): self
    {
        $this->month = $month;

        return $this;
    }

    public function isClosed(): bool
    {
        return $this->isClosed;
    }

    public function setIsClosed(bool $isClosed): self
    {
        $this->isClosed = $isClosed;

        return $this;
    }

    public function isExported(): bool
    {
        return $this->isExported;
    }

    public function setIsExported(bool $isExported): self
    {
        $this->isExported = $isExported;

        return $this;
    }

    public function getCashStart(): ?float
    {
        return null !== $this->cashStart ? (float) $this->cashStart : null;
    }

    public function setCashStart(mixed $cashStart): self
    {
        $this->cashStart = null !== $cashStart ? (string) $cashStart : null;

        return $this;
    }

    public function getCashEnd(): ?float
    {
        return null !== $this->cashEnd ? (float) $this->cashEnd : null;
    }

    public function setCashEnd(mixed $cashEnd): self
    {
        $this->cashEnd = null !== $cashEnd ? (string) $cashEnd : null;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, BookingEntry> */
    public function getEntries(): Collection
    {
        return $this->entries;
    }

    public function addEntry(BookingEntry $entry): self
    {
        if (!$this->entries->contains($entry)) {
            $this->entries->add($entry);
            $entry->setBookingBatch($this);
        }

        return $this;
    }

    public function removeEntry(BookingEntry $entry): self
    {
        $this->entries->removeElement($entry);

        return $this;
    }

    // ── Kassenbuch template compatibility getters ────────────────

    public function getCashYear(): int
    {
        return $this->year;
    }

    public function getCashMonth(): int
    {
        return $this->month;
    }

    public function getCashStartF(): string
    {
        return number_format((float) ($this->cashStart ?? 0), 2, ',', '.');
    }

    public function getCashEndF(): string
    {
        return number_format((float) ($this->cashEnd ?? 0), 2, ',', '.');
    }

    public function getIsClosed(): bool
    {
        return $this->isClosed;
    }

    public function getIsBooked(): bool
    {
        return $this->isExported;
    }

    public function setIsBooked(bool $isBooked): self
    {
        return $this->setIsExported($isBooked);
    }

    /**
     * Returns entries filtered to cash account (debit or credit is cash).
     * Each entry is wrapped to provide incomes/expenses/inventory getters.
     *
     * @return CashJournalEntryAdapter[]
     */
    public function getCashJournalEntries(): array
    {
        $cashEntries = [];
        $inventory = (float) ($this->cashStart ?? 0);

        foreach ($this->entries as $entry) {
            $isCashDebit = $entry->getDebitAccount()?->isCashAccount() ?? false;
            $isCashCredit = $entry->getCreditAccount()?->isCashAccount() ?? false;

            if (!$isCashDebit && !$isCashCredit) {
                continue;
            }

            $incomes = $isCashDebit ? (float) $entry->getAmount() : 0.0;
            $expenses = $isCashCredit ? (float) $entry->getAmount() : 0.0;
            $inventory += $incomes - $expenses;

            $counterAccount = $isCashDebit
                ? ($entry->getCreditAccount()?->getLabel() ?? $entry->getCounterAccountLegacy() ?? '')
                : ($entry->getDebitAccount()?->getLabel() ?? $entry->getCounterAccountLegacy() ?? '');

            $cashEntries[] = new CashJournalEntryAdapter(
                $entry,
                $incomes,
                $expenses,
                $inventory,
                $counterAccount,
            );
        }

        return $cashEntries;
    }
}
