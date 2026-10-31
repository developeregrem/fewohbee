<?php

declare(strict_types=1);

namespace App\Workflow\Trigger;

use Doctrine\ORM\EntityManagerInterface;

interface WorkflowTriggerInterface
{
    public function getType(): string;

    public function getLabelKey(): string;

    /** The entity class this trigger relates to, or null for non-entity triggers. */
    public function getEntityClass(): ?string;

    /**
     * Schema describing triggerConfig fields for the UI form builder.
     * Field definitions are ordered; their available keys and values depend on the field type.
     *
     * @return list<array<string, mixed>>
     */
    public function getConfigSchema(): array;

    /** Whether this trigger is event-driven (true) or time-based/cron (false). */
    public function isEventDriven(): bool;

    /**
     * Find entities that would be affected by this trigger (dry run / preview).
     *
     * For event-driven triggers: returns recent entities of the trigger type.
     * For time-based triggers: returns entities that currently match the criteria.
     *
     * @param array<string, mixed> $config Implementation-specific configuration values
     *
     * @return object[]
     */
    public function findPreviewEntities(EntityManagerInterface $em, array $config, int $limit = 20): array;

    /**
     * Find only the IDs of matching entities (used by the scheduler for efficient dedup filtering).
     * Returns an empty array for event-driven triggers.
     *
     * @param array<string, mixed> $config Implementation-specific configuration values
     *
     * @return int[]
     */
    public function findMatchingIds(EntityManagerInterface $em, array $config, int $limit = 500): array;
}
