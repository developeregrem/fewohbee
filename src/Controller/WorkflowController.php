<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ReservationStatus;
use App\Entity\Template;
use App\Entity\Workflow;
use App\Service\AppSettingsService;
use App\Repository\WorkflowLogRepository;
use App\Repository\WorkflowRepository;
use App\Workflow\Action\WorkflowActionRegistry;
use App\Workflow\Condition\WorkflowConditionRegistry;
use App\Workflow\Trigger\WorkflowTriggerRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/settings/workflows')]
#[IsGranted('ROLE_ADMIN')]
class WorkflowController extends AbstractController
{
    public function __construct(
        private readonly WorkflowRepository $workflowRepository,
        private readonly WorkflowTriggerRegistry $triggerRegistry,
        private readonly WorkflowConditionRegistry $conditionRegistry,
        private readonly WorkflowActionRegistry $actionRegistry,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly AppSettingsService $settingsService,
    ) {
    }

    #[Route('', name: 'settings.workflows.index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('Settings/Workflow/index.html.twig', [
            'systemWorkflows' => $this->workflowRepository->findSystemWorkflows(),
            'userWorkflows' => $this->workflowRepository->findUserWorkflows(),
            'triggers' => $this->triggerRegistry->all(),
        ]);
    }

    #[Route('/new', name: 'settings.workflows.new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $workflow = new Workflow();

        return $this->handleForm($request, $workflow, true);
    }

    #[Route('/{id}/edit', name: 'settings.workflows.edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Workflow $workflow): Response
    {
        return $this->handleForm($request, $workflow, false);
    }

    #[Route('/{id}/toggle', name: 'settings.workflows.toggle', methods: ['POST'])]
    public function toggle(Request $request, Workflow $workflow): JsonResponse
    {
        if (!$this->isCsrfTokenValid('workflow-toggle-' . $workflow->getId(), $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], 403);
        }

        $workflow->setIsEnabled(!$workflow->isEnabled());
        $this->em->flush();

        return new JsonResponse([
            'enabled' => $workflow->isEnabled(),
        ]);
    }

    #[Route('/{id}/delete', name: 'settings.workflows.delete', methods: ['DELETE'])]
    public function delete(Request $request, Workflow $workflow): Response
    {
        if ($workflow->isSystem()) {
            $this->addFlash('danger', 'workflow.flash.cannot_delete_system');

            return $this->redirectToRoute('settings.workflows.index', [], 303);
        }

        if ($this->isCsrfTokenValid('delete' . $workflow->getId(), $request->request->get('_token'))) {
            $this->em->remove($workflow);
            $this->em->flush();
            $this->addFlash('success', 'workflow.flash.deleted');
        }

        return $this->redirectToRoute('settings.workflows.index', [], 303);
    }

    #[Route('/{id}/logs', name: 'settings.workflows.logs', methods: ['GET'])]
    public function logs(Request $request, Workflow $workflow, WorkflowLogRepository $logRepository): Response
    {
        $perPage = 25;
        $page = max(1, (int) $request->query->get('page', 1));
        $total = $logRepository->countByWorkflow($workflow->getId());
        $pages = $total > 0 ? (int) ceil($total / $perPage) : 1;
        $page = min($page, $pages);

        return $this->render('Settings/Workflow/_log_table.html.twig', [
            'logs' => $logRepository->findRecentByWorkflow($workflow->getId(), $page, $perPage),
            'workflow' => $workflow,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    #[Route('/preview', name: 'settings.workflows.preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $triggerType = $request->request->get('triggerType', '');
        $conditionType = $request->request->get('conditionType');
        $conditionConfigRaw = $request->request->get('conditionConfig', '{}');

        if (!$this->triggerRegistry->has($triggerType)) {
            return new JsonResponse(['entities' => [], 'total' => 0]);
        }

        $trigger = $this->triggerRegistry->get($triggerType);
        $triggerConfigRaw = $request->request->get('triggerConfig', '{}');
        $triggerConfig = json_decode($triggerConfigRaw, true) ?: [];
        $conditionConfig = json_decode($conditionConfigRaw, true) ?: [];

        $entities = $trigger->findPreviewEntities($this->em, $triggerConfig, 20);

        // Apply condition filter if set
        if ($conditionType && $this->conditionRegistry->has($conditionType)) {
            $condition = $this->conditionRegistry->get($conditionType);
            $entities = array_filter(
                $entities,
                fn ($entity) => $condition->evaluate($conditionConfig, $entity, [])
            );
        }

        $results = [];
        foreach ($entities as $entity) {
            $results[] = $this->serializePreviewEntity($entity);
        }

        return new JsonResponse([
            'entities' => array_values($results),
            'total' => count($results),
        ]);
    }

    #[Route('/compatible-options', name: 'settings.workflows.compatible_options', methods: ['POST'])]
    public function compatibleOptions(Request $request): JsonResponse
    {
        $triggerType = $request->request->get('triggerType', '');

        if (!$this->triggerRegistry->has($triggerType)) {
            return new JsonResponse(['conditions' => [], 'actions' => []]);
        }

        $trigger = $this->triggerRegistry->get($triggerType);
        $entityClass = $trigger->getEntityClass();

        $conditions = [['type' => '', 'label' => $this->translator->trans('workflow.form.no_condition')]];
        if ($entityClass) {
            foreach ($this->conditionRegistry->getForEntityClass($entityClass) as $condition) {
                $conditions[] = [
                    'type' => $condition->getType(),
                    'label' => $this->translator->trans($condition->getLabelKey()),
                    'configSchema' => $this->enrichAndTranslateSchema($condition->getConfigSchema(), $entityClass),
                ];
            }
        }

        $actions = [];
        foreach ($this->actionRegistry->getForEntityClass($entityClass ?: null) as $action) {
            $supportedTriggers = $action->getSupportedTriggerTypes();
            if (!empty($supportedTriggers) && !in_array($triggerType, $supportedTriggers, true)) {
                continue;
            }
            $actions[] = [
                'type' => $action->getType(),
                'label' => $this->translator->trans($action->getLabelKey()),
                'configSchema' => $this->enrichAndTranslateSchema($action->getConfigSchema(), $entityClass ?? ''),
            ];
        }

        return new JsonResponse([
            'conditions' => $conditions,
            'actions' => $actions,
            'triggerConfigSchema' => $this->enrichAndTranslateSchema($trigger->getConfigSchema(), $entityClass ?? ''),
        ]);
    }

    /**
     * Translate label keys in a config schema and resolve template_select fields.
     *
     * @param array<int, array<string, mixed>> $schema
     * @return array<int, array<string, mixed>>
     */
    private function enrichAndTranslateSchema(array $schema, string $entityClass): array
    {
        $result = [];
        foreach ($schema as $field) {
            $type = $field['type'] ?? '';

            if ($type === 'template_select') {
                $templateTypes = $field['templateTypes'] ?? [];
                $field['type'] = 'select';
                $field['options'] = $this->loadTemplateOptions($templateTypes, $entityClass);
                unset($field['templateTypes']);
            } elseif ($type === 'reservation_status_select') {
                $field['type'] = 'select';
                $field['options'] = $this->loadReservationStatusOptions();
            }

            $field = $this->translateField($field);

            // For recipientType selects: filter by entity class and resolve %email% placeholder
            if (($field['key'] ?? '') === 'recipientType' && isset($field['options'])) {
                $notificationEmail = $this->settingsService->getNotificationEmail() ?? '–';
                $filtered = [];
                foreach ($field['options'] as $opt) {
                    // Skip options restricted to a different entity
                    if (isset($opt['onlyForEntity']) && $opt['onlyForEntity'] !== $entityClass) {
                        continue;
                    }
                    unset($opt['onlyForEntity']);
                    if (($opt['value'] ?? '') === 'notification_email') {
                        $opt['label'] = str_replace('%email%', $notificationEmail, $opt['label']);
                    }
                    $filtered[] = $opt;
                }
                $field['options'] = $filtered;
            }

            $result[] = $field;
        }

        return $result;
    }

    /** @return array<int, array{value: int, label: string}> */
    private function loadReservationStatusOptions(): array
    {
        $statuses = $this->em->getRepository(ReservationStatus::class)->findBy([], ['name' => 'ASC']);
        $options = [['value' => 0, 'label' => '–']];
        foreach ($statuses as $status) {
            $options[] = ['value' => $status->getId(), 'label' => $status->getName()];
        }

        return $options;
    }

    /** @param array<string, mixed> $field */
    private function translateField(array $field): array
    {
        if (isset($field['label'])) {
            $field['label'] = $this->translator->trans($field['label']);
        }
        if (isset($field['help'])) {
            $field['help'] = $this->translator->trans($field['help']);
        }
        if (isset($field['options']) && is_array($field['options'])) {
            $field['options'] = array_map(function (array $opt) {
                if (isset($opt['label'])) {
                    $opt['label'] = $this->translator->trans($opt['label']);
                }

                return $opt;
            }, $field['options']);
        }

        return $field;
    }

    /**
     * Load templates of the given types, filtered by entity class compatibility.
     *
     * @param string[] $templateTypes
     * @return array<int, array{value: int, label: string}>
     */
    private function loadTemplateOptions(array $templateTypes, string $entityClass): array
    {
        // Map entity class to the single compatible template type
        $entityTemplateTypeMap = [
            \App\Entity\Reservation::class => 'TEMPLATE_RESERVATION_EMAIL',
            \App\Entity\Invoice::class => 'TEMPLATE_INVOICE_EMAIL',
        ];

        $compatibleType = $entityTemplateTypeMap[$entityClass] ?? null;
        $typesToLoad = $compatibleType !== null
            ? array_filter($templateTypes, fn (string $t) => $t === $compatibleType)
            : $templateTypes;

        if (empty($typesToLoad)) {
            return [['value' => 0, 'label' => '–']];
        }

        $templates = $this->em->getRepository(Template::class)->loadByTypeName(array_values($typesToLoad));
        $options = [['value' => 0, 'label' => '–']];
        foreach ($templates as $template) {
            $options[] = ['value' => $template->getId(), 'label' => $template->getName()];
        }

        return $options;
    }

    private function handleForm(Request $request, Workflow $workflow, bool $isNew): Response
    {
        if ($request->isMethod('POST')) {
            $workflow->setName((string) $request->request->get('name', ''));
            $workflow->setDescription($request->request->get('description') ?: null);
            $workflow->setTriggerType((string) $request->request->get('triggerType', ''));
            $workflow->setTriggerConfig(json_decode($request->request->get('triggerConfig', '{}'), true) ?: []);
            $workflow->setConditionType($request->request->get('conditionType') ?: null);
            $workflow->setConditionConfig(
                $request->request->get('conditionType')
                    ? (json_decode($request->request->get('conditionConfig', '{}'), true) ?: [])
                    : null
            );
            $workflow->setActionType((string) $request->request->get('actionType', ''));
            $workflow->setActionConfig(json_decode($request->request->get('actionConfig', '{}'), true) ?: []);

            $errors = [];
            if ('' === $workflow->getName()) {
                $errors[] = 'workflow.error.name_required';
            }
            if ('' === $workflow->getTriggerType() || !$this->triggerRegistry->has($workflow->getTriggerType())) {
                $errors[] = 'workflow.error.trigger_required';
            }
            if ('' === $workflow->getActionType() || !$this->actionRegistry->has($workflow->getActionType())) {
                $errors[] = 'workflow.error.action_required';
            }

            if (empty($errors)) {
                if ($isNew) {
                    $this->em->persist($workflow);
                }
                $this->em->flush();
                $this->addFlash('success', $isNew ? 'workflow.flash.created' : 'workflow.flash.updated');

                return $this->redirectToRoute('settings.workflows.index');
            }

            foreach ($errors as $error) {
                $this->addFlash('danger', $error);
            }
        }

        $triggerChoices = [];
        foreach ($this->triggerRegistry->all() as $trigger) {
            $triggerChoices[] = [
                'type' => $trigger->getType(),
                'label' => $trigger->getLabelKey(),
                'entityClass' => $trigger->getEntityClass(),
                'isEventDriven' => $trigger->isEventDriven(),
                'configSchema' => $trigger->getConfigSchema(),
            ];
        }

        return $this->render('Settings/Workflow/edit.html.twig', [
            'workflow' => $workflow,
            'isNew' => $isNew,
            'triggerChoices' => $triggerChoices,
        ]);
    }

    private function serializePreviewEntity(object $entity): array
    {
        $data = ['id' => method_exists($entity, 'getId') ? $entity->getId() : null];

        if (method_exists($entity, 'getStartDate') && $entity->getStartDate()) {
            $data['startDate'] = $entity->getStartDate()->format('d.m.Y');
        }
        if (method_exists($entity, 'getEndDate') && $entity->getEndDate()) {
            $data['endDate'] = $entity->getEndDate()->format('d.m.Y');
        }
        if (method_exists($entity, 'getAppartment') && $entity->getAppartment()) {
            $data['room'] = (string) $entity->getAppartment()->getNumber();
        }
        if (method_exists($entity, 'getBooker') && $entity->getBooker()) {
            $booker = $entity->getBooker();
            $data['booker'] = trim($booker->getFirstname() . ' ' . $booker->getLastname());
        }
        if (method_exists($entity, 'getNumber')) {
            $data['number'] = $entity->getNumber();
        }
        if (method_exists($entity, 'getDate') && $entity->getDate()) {
            $data['date'] = $entity->getDate()->format('d.m.Y');
        }
        if (method_exists($entity, 'getStatus')) {
            $data['status'] = $entity->getStatus();
        }

        return $data;
    }
}
