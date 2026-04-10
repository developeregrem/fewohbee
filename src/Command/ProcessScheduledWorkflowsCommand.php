<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\WorkflowLogRepository;
use App\Repository\WorkflowRepository;
use App\Workflow\Condition\WorkflowConditionRegistry;
use App\Workflow\Trigger\MonthlyScheduleTrigger;
use App\Workflow\Trigger\WorkflowTriggerRegistry;
use App\Workflow\WorkflowEngine;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[AsCommand(
    name: 'workflow:process-scheduled',
    description: 'Process all active time-based workflows (run via cron every 15 minutes).',
)]
class ProcessScheduledWorkflowsCommand extends Command
{
    public function __construct(
        private readonly WorkflowRepository $workflowRepository,
        private readonly WorkflowLogRepository $workflowLogRepository,
        private readonly WorkflowTriggerRegistry $triggerRegistry,
        private readonly WorkflowConditionRegistry $conditionRegistry,
        private readonly WorkflowEngine $engine,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show which records would be processed without executing any actions.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        // Some services (e.g. ReservationService::getTotalPricesForTemplate) use the session
        // as a temporary store. In CLI context there is no request/session, so we push a
        // synthetic request with an in-memory session to satisfy those dependencies.
        $request = Request::create('/');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->requestStack->push($request);

        // Collect all time-based trigger type keys
        $timeBasedTypes = array_map(
            fn ($trigger) => $trigger->getType(),
            $this->triggerRegistry->getTimeBased()
        );

        if (empty($timeBasedTypes)) {
            $io->note('No time-based triggers registered.');

            return Command::SUCCESS;
        }

        $workflows = $this->workflowRepository->findActiveByTriggerTypes($timeBasedTypes);

        if (empty($workflows)) {
            $io->note('No active time-based workflows found.');

            return Command::SUCCESS;
        }

        $totalCandidates = 0;
        $totalExecuted = 0;

        foreach ($workflows as $workflow) {
            $triggerType = $workflow->getTriggerType();

            if (!$this->triggerRegistry->has($triggerType)) {
                $this->logger->warning('Scheduled workflow has unknown trigger type', [
                    'workflow_id' => $workflow->getId(),
                    'trigger_type' => $triggerType,
                ]);
                continue;
            }

            $trigger = $this->triggerRegistry->get($triggerType);

            // Handle monthly schedule triggers separately
            if ($trigger instanceof MonthlyScheduleTrigger) {
                if (!$trigger->matchesToday($workflow->getTriggerConfig())) {
                    continue;
                }

                if ($dryRun) {
                    $io->writeln(sprintf('[dry-run] Monthly workflow "%s" would fire today', $workflow->getName()));
                    continue;
                }

                if ($this->engine->processScheduledWorkflow($workflow, new \stdClass())) {
                    ++$totalExecuted;
                }
                continue;
            }

            // --- Two-phase entity loading + batch dedup ---

            // Phase 1: load only IDs (cheap scalar query, uses index)
            $entityIds = $trigger->findMatchingIds($this->em, $workflow->getTriggerConfig(), 500);

            if (empty($entityIds)) {
                continue;
            }

            $entityClass = $trigger->getEntityClass();
            $workflowId = $workflow->getId();

            // Phase 2: batch dedup — one query instead of N individual checks
            $processedIds = ($workflowId !== null && $entityClass !== null)
                ? $this->workflowLogRepository->findProcessedEntityIds($workflowId, $entityClass)
                : [];

            $pendingIds = array_filter($entityIds, fn (int $id) => !isset($processedIds[$id]));

            if (empty($pendingIds)) {
                continue;
            }

            $totalCandidates += count($pendingIds);

            // Phase 3: load full entities only for pending IDs
            $entities = $this->em->getRepository($entityClass)->findBy(['id' => array_values($pendingIds)]);

            foreach ($entities as $entity) {
                // Evaluate all conditions (AND logic, same as engine but without logging)
                $conditionsMet = true;
                foreach ($workflow->getConditions() as $conditionDef) {
                    if ($this->conditionRegistry->has($conditionDef['type'])) {
                        $condition = $this->conditionRegistry->get($conditionDef['type']);
                        if (!$condition->evaluate($conditionDef['config'] ?? [], $entity, [])) {
                            $conditionsMet = false;
                            break;
                        }
                    }
                }
                if (!$conditionsMet) {
                    continue;
                }

                if ($dryRun) {
                    $entityId = method_exists($entity, 'getId') ? $entity->getId() : '?';
                    $io->writeln(sprintf(
                        '[dry-run] Workflow "%s" would process %s#%s',
                        $workflow->getName(),
                        $entity::class,
                        $entityId
                    ));
                    ++$totalExecuted;
                    continue;
                }

                if ($this->engine->processScheduledWorkflow($workflow, $entity)) {
                    ++$totalExecuted;
                }
            }
        }

        if ($dryRun) {
            $io->note(sprintf('[dry-run] %d action(s) would be executed (%d candidates checked).', $totalExecuted, $totalCandidates));
        } else {
            $io->success(sprintf('%d action(s) executed (%d candidates checked).', $totalExecuted, $totalCandidates));
            $this->logger->info('workflow:process-scheduled completed', [
                'candidates' => $totalCandidates,
                'executed' => $totalExecuted,
            ]);
        }

        return Command::SUCCESS;
    }
}
