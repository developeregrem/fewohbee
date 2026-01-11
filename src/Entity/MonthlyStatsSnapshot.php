<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: 'App\Repository\MonthlyStatsSnapshotRepository')]
#[ORM\Table(name: 'monthly_stats_snapshots')]
#[ORM\Index(name: 'idx_month_year', columns: ['year', 'month'])]
class MonthlyStatsSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'smallint')]
    private int $month;

    #[ORM\Column(type: 'smallint')]
    private int $year;

    #[ORM\Column(type: 'boolean')]
    private bool $isAll = false;

    #[ORM\ManyToOne(targetEntity: Subsidiary::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Subsidiary $subsidiary = null;

    #[ORM\Column(type: Types::JSON)]
    private array $metrics = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * Initialize timestamps for a new snapshot.
     */
    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Get the snapshot id.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Get the month (1-12) for the snapshot.
     */
    public function getMonth(): int
    {
        return $this->month;
    }

    /**
     * Set the month (1-12) for the snapshot.
     */
    public function setMonth(int $month): void
    {
        $this->month = $month;
    }

    /**
     * Get the year for the snapshot.
     */
    public function getYear(): int
    {
        return $this->year;
    }

    /**
     * Set the year for the snapshot.
     */
    public function setYear(int $year): void
    {
        $this->year = $year;
    }

    /**
     * Check whether this snapshot represents "all" subsidiaries.
     */
    public function isAll(): bool
    {
        return $this->isAll;
    }

    /**
     * Mark whether this snapshot represents "all" subsidiaries.
     */
    public function setIsAll(bool $isAll): void
    {
        $this->isAll = $isAll;
    }


    /**
     * Get the subsidiary scope of this snapshot or null for "all".
     */
    public function getSubsidiary(): ?Subsidiary
    {
        return $this->subsidiary;
    }

    /**
     * Set the subsidiary scope of this snapshot or null for "all".
     */
    public function setSubsidiary(?Subsidiary $subsidiary): void
    {
        $this->subsidiary = $subsidiary;
    }

    /**
     * Get the metrics payload for this snapshot.
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    /**
     * Set the metrics payload for this snapshot.
     */
    public function setMetrics(array $metrics): void
    {
        $this->metrics = $metrics;
    }

    /**
     * Get the creation timestamp.
     */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Set the creation timestamp.
     */
    public function setCreatedAt(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    /**
     * Get the last update timestamp.
     */
    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Refresh the update timestamp to "now".
     */
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
