<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow;

use App\Entity\Workflow;
use App\Repository\WorkflowRepository;
use App\Workflow\WorkflowSeeder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Verifies the canonical system workflows created for fresh installations.
 */
final class WorkflowSeederTest extends TestCase
{
    public function testSeedsOnlineBookingConfirmationAsDisabledBookerWorkflow(): void
    {
        $repository = $this->createStub(WorkflowRepository::class);
        $repository->method('findBySystemCode')->willReturn(null);

        $persisted = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(3))
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });
        $entityManager->expects(self::once())->method('flush');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        (new WorkflowSeeder($entityManager, $repository, $translator))->seedInternalWorkflows();

        $confirmation = array_find(
            $persisted,
            static fn (object $entity): bool => $entity instanceof Workflow
                && 'confirm_online_booking' === $entity->getSystemCode(),
        );

        self::assertInstanceOf(Workflow::class, $confirmation);
        self::assertTrue($confirmation->isSystem());
        self::assertFalse($confirmation->isEnabled());
        self::assertSame('online_booking.created', $confirmation->getTriggerType());
        self::assertSame('send_template_email', $confirmation->getActionType());
        self::assertSame(
            [['type' => 'reservation.has_booker_email', 'config' => []]],
            $confirmation->getConditions(),
        );
        self::assertSame('booker_email', $confirmation->getActionConfig()['recipientType']);
        self::assertSame(0, $confirmation->getActionConfig()['templateId']);
        self::assertSame([], $confirmation->getActionConfig()['attachments']);
    }
    public function testInternalSeedingLeavesOutAssistantWorkflow(): void
    {
        $repository = $this->createStub(WorkflowRepository::class);
        $repository->method('findBySystemCode')->willReturn(null);

        $persisted = [];
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        (new WorkflowSeeder($entityManager, $repository, $this->translator()))->seedInternalWorkflows();

        self::assertNull(array_find(
            $persisted,
            static fn (object $entity): bool => $entity instanceof Workflow && 'notify_assistant_booking' === $entity->getSystemCode(),
        ));
    }

    public function testSeedsAssistantWorkflowAsEnabledInAppNotification(): void
    {
        $repository = $this->createStub(WorkflowRepository::class);
        $repository->method('findBySystemCode')->willReturn(null);

        $persisted = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $entityManager->expects(self::once())->method('flush');

        (new WorkflowSeeder($entityManager, $repository, $this->translator()))->seedAssistantWorkflows();

        $workflow = $persisted[0];
        self::assertInstanceOf(Workflow::class, $workflow);
        self::assertSame('notify_assistant_booking', $workflow->getSystemCode());
        self::assertSame('assistant_booking.created', $workflow->getTriggerType());
        self::assertSame('create_in_app_notification', $workflow->getActionType());
        self::assertTrue($workflow->isEnabled());
        self::assertTrue($workflow->isSystem());
    }

    public function testReseedingAssistantWorkflowKeepsTheExistingOneAsIs(): void
    {
        $existing = new Workflow();
        $existing->setSystemCode('notify_assistant_booking');
        $existing->setIsEnabled(false);

        $repository = $this->createStub(WorkflowRepository::class);
        $repository->method('findBySystemCode')->willReturn($existing);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');

        (new WorkflowSeeder($entityManager, $repository, $this->translator()))->seedAssistantWorkflows();

        self::assertFalse($existing->isEnabled());
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }
}
