<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn;

use App\Entity\MailCorrespondence;
use App\Entity\Reservation;
use App\Entity\Template;
use App\Entity\Workflow;
use App\Repository\WorkflowLogRepository;
use App\Repository\WorkflowRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * How the check-in link reaches the guest: workflows that send it, and the invitation template
 * the reservation dialog sends by hand. Settings and the dialog use it to tell whether and when
 * an invitation goes out.
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
        private readonly GuestCheckInConfigService $configService,
    ) {
    }

    /** The template sent by hand from the reservation dialog; null hides that button. */
    public function invitationTemplate(): ?Template
    {
        $template = $this->configService->getConfig()->getInvitationTemplate();

        return null !== $template && !$template->isHidden() ? $template : null;
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
        if (null === $reservation->getId()) {
            return null;
        }

        $ids = array_values(array_filter(array_map(
            static fn (Workflow $workflow): ?int => $workflow->getId(),
            $this->findInvitationWorkflows(),
        )));
        $byWorkflow = $this->logRepository->findLastSuccessfulExecutionAt($ids, Reservation::class, $reservation->getId());
        $byHand = $this->lastSentByHandAt($reservation);

        return null === $byWorkflow || (null !== $byHand && $byHand > $byWorkflow) ? $byHand : $byWorkflow;
    }

    /**
     * When the invitation template was last mailed for this reservation, in whichever language
     * variant; read from the conversations, where every sent mail is recorded.
     */
    private function lastSentByHandAt(Reservation $reservation): ?\DateTimeImmutable
    {
        $template = $this->invitationTemplate();
        if (null === $template) {
            return null;
        }

        $mails = $this->em->getRepository(MailCorrespondence::class)->findBy(
            ['reservation' => $reservation, 'template' => [$template, ...$template->getVariants()]],
            ['created' => 'DESC'],
            1,
        );

        return [] === $mails ? null : \DateTimeImmutable::createFromInterface($mails[0]->getCreated());
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
