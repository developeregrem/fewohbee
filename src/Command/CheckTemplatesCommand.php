<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Template;
use App\Service\TemplatesService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists stored templates that use tags, filters, functions or tests the template sandbox rejects,
 * so operators can fix them before invoices or emails fail to render. Method and property
 * access depends on the data and is only reported when a template is rendered.
 */
#[AsCommand(
    name: 'app:templates:check',
    description: 'List templates that use features not allowed in the template sandbox.',
)]
final class CheckTemplatesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TemplatesService $templatesService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = [];

        foreach ($this->em->getRepository(Template::class)->findBy([], ['id' => 'ASC']) as $template) {
            $violation = $this->templatesService->findSandboxViolation($template);
            if (null !== $violation) {
                $rows[] = [
                    $template->getId(),
                    $template->getTemplateType()?->getName() ?? '',
                    $template->getName(),
                    $template->getLocale() ?? '',
                    $violation,
                ];
            }
        }

        if ([] === $rows) {
            $io->success('All templates only use features allowed in the template sandbox.');

            return Command::SUCCESS;
        }

        $io->table(['ID', 'Type', 'Name', 'Language', 'Problem'], $rows);
        $io->error(\sprintf('%d template(s) will not render until they are adjusted.', \count($rows)));

        return Command::FAILURE;
    }
}
