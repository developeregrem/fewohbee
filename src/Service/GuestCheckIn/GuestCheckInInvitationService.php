<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn;

use App\Entity\Reservation;
use App\Entity\Template;
use App\Entity\Workflow;
use App\Repository\WorkflowLogRepository;
use App\Repository\WorkflowRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Which workflows bring the check-in link to the guest, so settings and the reservation dialog
 * can tell whether an invitation goes out at all.
 *
 * A workflow counts when it is enabled and sends a template that renders the link or its QR
 * code; no other configuration (trigger, conditions) is interpreted.
 */
class GuestCheckInInvitationService
{
    private const ACTION_TYPE = 'send_template_email';
    private const LINK_FUNCTIONS = ['guest_checkin_url', 'guest_checkin_qr'];

    /** @var list<Workflow>|null */
    private ?array $workflows = null;

    public function __construct(
        private readonly WorkflowRepository $workflowRepository,
        private readonly WorkflowLogRepository $logRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return list<Workflow> */
    public function findInvitationWorkflows(): array
    {
        if (null !== $this->workflows) {
            return $this->workflows;
        }

        $workflows = [];
        foreach ($this->workflowRepository->findBy(['actionType' => self::ACTION_TYPE, 'isEnabled' => true], ['name' => 'ASC']) as $workflow) {
            $templateId = (int) ($workflow->getActionConfig()['templateId'] ?? 0);
            $template = $templateId > 0 ? $this->em->find(Template::class, $templateId) : null;
            if ($template instanceof Template && self::rendersLink((string) $template->getText())) {
                $workflows[] = $workflow;
            }
        }

        return $this->workflows = $workflows;
    }

    /** When one of the invitation workflows last sent something for this reservation. */
    public function lastSentAt(Reservation $reservation): ?\DateTimeImmutable
    {
        $ids = array_values(array_filter(array_map(
            static fn (Workflow $workflow): ?int => $workflow->getId(),
            $this->findInvitationWorkflows(),
        )));

        return null === $reservation->getId()
            ? null
            : $this->logRepository->findLastSuccessfulExecutionAt($ids, Reservation::class, $reservation->getId());
    }

    private static function rendersLink(string $text): bool
    {
        foreach (self::LINK_FUNCTIONS as $function) {
            if (str_contains($text, $function)) {
                return true;
            }
        }

        return false;
    }
}
