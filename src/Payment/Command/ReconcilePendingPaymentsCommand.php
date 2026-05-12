<?php

declare(strict_types=1);

namespace App\Payment\Command;

use App\Payment\Service\PaymentService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'payment:reconcile-pending',
    description: 'Poll provider status for all pending/initiated payment transactions and dispatch events on status change.'
)]
class ReconcilePendingPaymentsCommand extends Command
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of transactions to process in this run', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int) $input->getOption('limit');

        $pending = $this->paymentService->findPending($limit);
        if ([] === $pending) {
            $io->success('No pending payment transactions.');

            return Command::SUCCESS;
        }

        $io->note(sprintf('Processing %d pending transaction(s)…', count($pending)));

        $changed = 0;
        foreach ($pending as $transaction) {
            $before = $transaction->getStatus();
            try {
                $after = $this->paymentService->syncTransaction($transaction);
            } catch (\Throwable $e) {
                $io->warning(sprintf(
                    'Transaction #%d (%s/%s): %s',
                    $transaction->getId(),
                    $transaction->getProviderId(),
                    $transaction->getProviderPaymentId(),
                    $e->getMessage(),
                ));
                continue;
            }

            if ($after !== $before) {
                ++$changed;
                $io->writeln(sprintf(
                    'Transaction #%d: %s → %s',
                    $transaction->getId(),
                    $before->value,
                    $after->value,
                ));
            }
        }

        $io->success(sprintf('Done. %d transaction(s) changed state.', $changed));

        return Command::SUCCESS;
    }
}
