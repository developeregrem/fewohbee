<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow;

use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Workflow\Action\WorkflowActionInterface;
use App\Workflow\Action\WorkflowActionRegistry;
use PHPUnit\Framework\TestCase;

final class WorkflowRegistryTest extends TestCase
{
    private function makeAction(string $type, array $entityClasses): WorkflowActionInterface
    {
        $action = $this->createStub(WorkflowActionInterface::class);
        $action->method('getType')->willReturn($type);
        $action->method('getSupportedEntityClasses')->willReturn($entityClasses);

        return $action;
    }

    private function makeRegistry(): WorkflowActionRegistry
    {
        return new WorkflowActionRegistry([
            $this->makeAction('send_template_email', [Reservation::class, Invoice::class]),
            $this->makeAction('send_notification_email', [Reservation::class]),
            $this->makeAction('send_general_email', []),
        ]);
    }

    public function testGetForEntityClassReturnsCompatibleActions(): void
    {
        $registry = $this->makeRegistry();
        $actions = $registry->getForEntityClass(Reservation::class);

        $types = array_map(fn ($a) => $a->getType(), $actions);
        self::assertContains('send_template_email', $types);
        self::assertContains('send_notification_email', $types);
        self::assertNotContains('send_general_email', $types);
    }

    public function testGetForEntityClassInvoiceExcludesReservationOnlyAction(): void
    {
        $registry = $this->makeRegistry();
        $actions = $registry->getForEntityClass(Invoice::class);

        $types = array_map(fn ($a) => $a->getType(), $actions);
        self::assertContains('send_template_email', $types);
        self::assertNotContains('send_notification_email', $types);
        self::assertNotContains('send_general_email', $types);
    }

    public function testGetForEntityClassNullReturnsEntityLessActions(): void
    {
        $registry = $this->makeRegistry();
        $actions = $registry->getForEntityClass(null);

        $types = array_map(fn ($a) => $a->getType(), $actions);
        self::assertContains('send_general_email', $types);
        self::assertNotContains('send_template_email', $types);
        self::assertNotContains('send_notification_email', $types);
    }

    public function testGetForEntityClassNullReturnsSameAsNoEntity(): void
    {
        $registry = $this->makeRegistry();
        $actions = $registry->getForEntityClass(null);

        $types = array_map(fn ($a) => $a->getType(), $actions);
        self::assertContains('send_general_email', $types);
    }

    public function testHasReturnsTrueForRegisteredType(): void
    {
        $registry = $this->makeRegistry();
        self::assertTrue($registry->has('send_template_email'));
    }

    public function testHasReturnsFalseForUnknownType(): void
    {
        $registry = $this->makeRegistry();
        self::assertFalse($registry->has('nonexistent_action'));
    }

    public function testGetThrowsForUnknownType(): void
    {
        $registry = $this->makeRegistry();
        $this->expectException(\InvalidArgumentException::class);
        $registry->get('nonexistent_action');
    }
}
