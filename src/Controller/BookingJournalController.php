<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AccountingAccount;
use App\Entity\BookingBatch;
use App\Entity\BookingEntry;
use App\Entity\Template;
use App\Form\BookingBatchType;
use App\Form\BookingEntryType;
use App\Repository\AccountingAccountRepository;
use App\Repository\AccountingSettingsRepository;
use App\Repository\BookingBatchRepository;
use App\Repository\BookingEntryRepository;
use App\Service\AccountingSettingsService;
use App\Service\BookingJournalService;
use App\Service\JournalExport\DatevExportService;
use App\Service\OpeningBalanceService;
use App\Service\TemplatesService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/journal')]
#[IsGranted('ROLE_CASHJOURNAL')]
class BookingJournalController extends AbstractController
{
    private const PER_PAGE = 20;

    #[Route('', name: 'journal.overview', methods: ['GET'])]
    public function index(BookingBatchRepository $batchRepo): Response
    {
        $years = $batchRepo->getAvailableYears();
        $currentYear = count($years) > 0 ? $years[0]['year'] : (int) date('Y');

        return $this->render('BookingJournal/index.html.twig', [
            'years' => $years,
            'currentYear' => $currentYear,
        ]);
    }

    #[Route('/batches', name: 'journal.batches', methods: ['GET'])]
    public function batches(
        BookingBatchRepository $batchRepo,
        BookingJournalService $journalService,
        Request $request,
    ): Response {
        $year = (int) $request->query->get('year', date('Y'));
        $page = (int) $request->query->get('page', 1);

        $batches = $batchRepo->findByFilter($year, $page, self::PER_PAGE);
        $pages = ceil($batches->count() / self::PER_PAGE);

        $journalService->populateCashBalances(iterator_to_array($batches), $year);

        return $this->render('BookingJournal/batch_table.html.twig', [
            'batches' => $batches,
            'year' => $year,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    #[Route('/batch/new', name: 'journal.batch.new', methods: ['GET'])]
    public function newBatch(BookingBatchRepository $batchRepo): Response
    {
        $batch = new BookingBatch();
        $youngest = $batchRepo->getYoungestBatch();

        if (null !== $youngest) {
            if (12 === $youngest->getMonth()) {
                $batch->setYear($youngest->getYear() + 1);
                $batch->setMonth(1);
            } else {
                $batch->setYear($youngest->getYear());
                $batch->setMonth($youngest->getMonth() + 1);
            }
        } else {
            $batch->setYear((int) date('Y'));
            $batch->setMonth((int) date('n'));
        }

        $form = $this->createForm(BookingBatchType::class, $batch, [
            'action' => $this->generateUrl('journal.batch.create'),
        ]);

        return $this->render('BookingJournal/_batch_form.html.twig', ['form' => $form]);
    }

    #[Route('/batch/create', name: 'journal.batch.create', methods: ['POST'])]
    public function createBatch(
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        $batch = new BookingBatch();
        $form = $this->createForm(BookingBatchType::class, $batch);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($batch);
            $em->flush();

            $this->addFlash('success', 'accounting.journal.flash.batch_created');

            return $this->redirectToRoute('journal.batch.entries', ['id' => $batch->getId()]);
        }

        return $this->render('BookingJournal/_batch_form.html.twig', ['form' => $form]);
    }

    #[Route('/batch/{id}', name: 'journal.batch.entries', methods: ['GET'])]
    public function batchEntries(
        BookingBatch $batch,
        BookingEntryRepository $entryRepo,
        BookingJournalService $journalService,
        EntityManagerInterface $em,
        Request $request,
    ): Response {
        $page = (int) $request->query->get('page', 1);
        $search = $request->query->get('search', '');
        $filter = $request->query->get('filter', 'all');
        if (!in_array($filter, ['all', 'cashbook', 'bankbook'], true)) {
            $filter = 'all';
        }

        $entries = $entryRepo->findByBatch($batch, $search, $page, self::PER_PAGE, $filter);
        $pages = ceil($entries->count() / self::PER_PAGE);

        $pdfTemplates = $em->getRepository(Template::class)->loadByTypeName(['TEMPLATE_CASHJOURNAL_PDF']);

        $journalService->populateCashBalance($batch);

        $bankOpeningBalance = 0.0;
        $bankClosingBalance = 0.0;
        if ('bankbook' === $filter) {
            $bankOpeningBalance = $entryRepo->getBankOpeningBalance($batch);
            $bankClosingBalance = $bankOpeningBalance + $entryRepo->getBankBatchDelta($batch);
        }

        return $this->render('BookingJournal/entries.html.twig', [
            'batch' => $batch,
            'entries' => $entries,
            'bankOpeningBalance' => $bankOpeningBalance,
            'bankClosingBalance' => $bankClosingBalance,
            'page' => $page,
            'pages' => $pages,
            'search' => $search,
            'filter' => $filter,
            'pdfTemplates' => $pdfTemplates,
        ]);
    }

    #[Route('/batch/{id}/toggle-status', name: 'journal.batch.toggle_status', methods: ['POST'])]
    public function toggleBatchStatus(
        BookingBatch $batch,
        Request $request,
        EntityManagerInterface $em,
        AuthorizationCheckerInterface $authChecker,
    ): Response {
        if (!$this->isCsrfTokenValid('batch_toggle_'.$batch->getId(), $request->request->get('_token'))) {
            $this->addFlash('warning', 'flash.access.denied');

            return $this->redirectToRoute('journal.batch.entries', ['id' => $batch->getId()]);
        }

        if ($batch->isClosed() && !$authChecker->isGranted('ROLE_ADMIN')) {
            $this->addFlash('warning', 'flash.access.denied');

            return $this->redirectToRoute('journal.batch.entries', ['id' => $batch->getId()]);
        }

        $batch->setIsClosed(!$batch->isClosed());

        $em->flush();

        $this->addFlash('success', $batch->isClosed()
            ? 'accounting.journal.flash.batch_closed'
            : 'accounting.journal.flash.batch_reopened'
        );

        return $this->redirectToRoute('journal.batch.entries', ['id' => $batch->getId()]);
    }

    #[Route('/entry/new/{id:batch}', name: 'journal.entry.new', methods: ['GET'])]
    public function newEntry(
        BookingBatch $batch,
        BookingEntryRepository $entryRepo,
        Request $request,
        AccountingSettingsService $settingsService,
    ): Response {
        $docNumber = $entryRepo->getLastDocumentNumber($batch) + 1;
        $cashbookMode = 'cashbook' === $request->query->get('filter');

        $entry = new BookingEntry();
        $entry->setDate(new \DateTime());
        $entry->setDocumentNumber($docNumber);

        $form = $this->createForm(BookingEntryType::class, $entry, [
            'action' => $this->generateUrl('journal.entry.create', ['filter' => $request->query->get('filter', 'all')]),
            'cashbook_mode' => $cashbookMode,
            'active_preset' => $settingsService->getActivePreset(),
        ]);

        return $this->render('BookingJournal/_entry_form.html.twig', ['form' => $form, 'batch' => $batch]);
    }

    #[Route('/entry/create', name: 'journal.entry.create', methods: ['POST'])]
    public function createEntry(
        Request $request,
        EntityManagerInterface $em,
        BookingBatchRepository $batchRepo,
        BookingJournalService $journalService,
        AccountingSettingsService $settingsService,
    ): Response {
        $batch = $batchRepo->find($request->request->get('batchId'));
        $filter = $request->query->get('filter', 'all');
        $cashbookMode = 'cashbook' === $filter;

        if ($batch->isClosed()) {
            $this->addFlash('warning', 'journal.error.journal.closed');

            return $this->redirectToRoute('journal.batch.entries', ['id' => $batch->getId(), 'filter' => $filter]);
        }

        $entry = new BookingEntry();
        $entry->setBookingBatch($batch);
        $entry->setSourceType(BookingEntry::SOURCE_MANUAL);

        $form = $this->createForm(BookingEntryType::class, $entry, [
            'cashbook_mode' => $cashbookMode,
            'active_preset' => $settingsService->getActivePreset(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($cashbookMode) {
                $this->applyCashbookMapping($form, $entry, $em, $settingsService->getActivePreset());
            }

            try {
                $batch = $journalService->assignBatchByEntryDate($entry);
            } catch (\RuntimeException $e) {
                $this->addFlash('warning', $e->getMessage());

                return $this->redirectToRoute('journal.batch.entries', ['id' => $entry->getBookingBatch()->getId(), 'filter' => $filter]);
            }

            $em->persist($entry);
            $em->flush();
            $journalService->recalculateDocumentNumbersForYears($batch->getYear());

            $this->addFlash('success', 'accounting.journal.flash.entry_created');

            return $this->redirectToRoute('journal.batch.entries', ['id' => $batch->getId(), 'filter' => $filter]);
        }

        return $this->render('BookingJournal/_entry_form.html.twig', ['form' => $form, 'batch' => $batch]);
    }

    #[Route('/entry/{id}/edit', name: 'journal.entry.edit', methods: ['GET'])]
    public function editEntry(
        BookingEntry $entry,
        Request $request,
        EntityManagerInterface $em,
        AccountingSettingsService $settingsService,
    ): Response {
        $filter = $request->query->get('filter', 'all');
        $cashbookMode = 'cashbook' === $filter;
        $activePreset = $settingsService->getActivePreset();

        $formOptions = [
            'action' => $this->generateUrl('journal.entry.update', ['id' => $entry->getId(), 'filter' => $filter]),
            'reference_date' => $entry->getDate() ?? new \DateTime(),
            'cashbook_mode' => $cashbookMode,
            'active_preset' => $activePreset,
        ];

        if ($cashbookMode) {
            $cashAccount = $em->getRepository(AccountingAccount::class)->findCashAccount($activePreset);
            if ($entry->getDebitAccount() && $entry->getDebitAccount() === $cashAccount) {
                $formOptions['direction_default'] = 'income';
                $formOptions['category_default'] = $entry->getCreditAccount();
            } else {
                $formOptions['direction_default'] = 'expense';
                $formOptions['category_default'] = $entry->getDebitAccount();
            }
        }

        $form = $this->createForm(BookingEntryType::class, $entry, $formOptions);

        return $this->render('BookingJournal/_entry_form.html.twig', ['form' => $form, 'batch' => $entry->getBookingBatch()]);
    }

    #[Route('/entry/{id}/update', name: 'journal.entry.update', methods: ['POST'])]
    public function updateEntry(
        BookingEntry $entry,
        Request $request,
        EntityManagerInterface $em,
        BookingJournalService $journalService,
        AccountingSettingsService $settingsService,
    ): Response {
        $batch = $entry->getBookingBatch();
        $oldYear = $batch->getYear();
        $filter = $request->query->get('filter', 'all');
        $cashbookMode = 'cashbook' === $filter;

        if ($batch->isClosed()) {
            $this->addFlash('warning', 'journal.error.journal.closed');

            return $this->redirectToRoute('journal.batch.entries', ['id' => $batch->getId(), 'filter' => $filter]);
        }

        $form = $this->createForm(BookingEntryType::class, $entry, [
            'cashbook_mode' => $cashbookMode,
            'active_preset' => $settingsService->getActivePreset(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($cashbookMode) {
                $this->applyCashbookMapping($form, $entry, $em, $settingsService->getActivePreset());
            }

            try {
                $batch = $journalService->assignBatchByEntryDate($entry);
            } catch (\RuntimeException $e) {
                $this->addFlash('warning', $e->getMessage());

                return $this->redirectToRoute('journal.batch.entries', ['id' => $entry->getBookingBatch()->getId(), 'filter' => $filter]);
            }

            $em->flush();
            $journalService->recalculateDocumentNumbersForYears($oldYear, $batch->getYear());

            $this->addFlash('success', 'accounting.journal.flash.entry_updated');

            return $this->redirectToRoute('journal.batch.entries', ['id' => $batch->getId(), 'filter' => $filter]);
        }

        return $this->render('BookingJournal/_entry_form.html.twig', ['form' => $form, 'batch' => $batch]);
    }

    #[Route('/entry/{id}/delete', name: 'journal.entry.delete', methods: ['DELETE'])]
    public function deleteEntry(
        BookingEntry $entry,
        EntityManagerInterface $em,
        Request $request,
        BookingJournalService $journalService,
    ): Response {
        $batch = $entry->getBookingBatch();

        if (!$this->isCsrfTokenValid('delete'.$entry->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'flash.invalidtoken');

            return new Response('', Response::HTTP_NO_CONTENT);
        }        

        if ($batch->isClosed()) {
            $this->addFlash('warning', 'journal.error.journal.closed');

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $em->remove($entry);
        $em->flush();
        $journalService->recalculateDocumentNumbersForYears($batch->getYear());

        $this->addFlash('success', 'accounting.journal.flash.entry_deleted');

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function applyCashbookMapping(
        \Symfony\Component\Form\FormInterface $form,
        BookingEntry $entry,
        EntityManagerInterface $em,
        ?string $activePreset = null,
    ): void {
        $cashAccount = $em->getRepository(AccountingAccount::class)->findCashAccount($activePreset);
        $direction = $form->get('direction')->getData();
        $category = $form->get('category')->getData();

        if ('income' === $direction) {
            $entry->setDebitAccount($cashAccount);
            $entry->setCreditAccount($category);
        } else {
            $entry->setDebitAccount($category);
            $entry->setCreditAccount($cashAccount);
        }
    }

    #[Route('/batch/{id}/export/datev', name: 'journal.batch.export.datev', methods: ['GET'])]
    public function exportDatev(
        BookingBatch $batch,
        DatevExportService $datevExport,
        AccountingSettingsRepository $settingsRepo,
        \App\Service\AppSettingsService $appSettingsService,
        EntityManagerInterface $em,
        Request $request,
    ): Response {
        $settings = $settingsRepo->findSingleton();

        if (null === $settings) {
            $this->addFlash('warning', 'accounting.journal.export.no_settings');

            return $this->redirectToRoute('journal.batch.entries', ['id' => $batch->getId()]);
        }

        $warnings = $datevExport->validateWithSettings($batch, $settings);

        if (count($warnings) > 0 && !$request->query->getBoolean('force')) {
            $this->addFlash('datev_warnings', implode('||', $warnings));

            return $this->redirectToRoute('journal.batch.entries', ['id' => $batch->getId()]);
        }

        $currency = $appSettingsService->getSettings()->getCurrency();
        $csv = $datevExport->export($batch, $settings, $currency);

        $batch->setIsExported(true);
        $em->flush();

        $filename = sprintf('EXTF_Buchungsstapel_%d_%02d.csv', $batch->getYear(), $batch->getMonth());

        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }

    #[Route('/export/year/{year}/datev', name: 'journal.export.year.datev', methods: ['GET'])]
    public function exportYearDatev(
        int $year,
        BookingBatchRepository $batchRepo,
        DatevExportService $datevExport,
        AccountingSettingsRepository $settingsRepo,
        \App\Service\AppSettingsService $appSettingsService,
        EntityManagerInterface $em,
        Request $request,
    ): Response {
        $settings = $settingsRepo->findSingleton();

        if (null === $settings) {
            $this->addFlash('warning', 'accounting.journal.export.no_settings');

            return $this->redirectToRoute('journal.overview');
        }

        $batches = $batchRepo->findByYear($year);

        if ([] === $batches) {
            $this->addFlash('warning', 'accounting.journal.export.no_batches');

            return $this->redirectToRoute('journal.overview');
        }

        $warnings = $datevExport->validateYear($batches, $settings);

        if (count($warnings) > 0 && !$request->query->getBoolean('force')) {
            $this->addFlash('datev_year_warnings', $year.'||'.implode('||', $warnings));

            return $this->redirectToRoute('journal.overview');
        }

        $currency = $appSettingsService->getSettings()->getCurrency();
        $csv = $datevExport->exportYear($year, $batches, $settings, $currency);

        foreach ($batches as $batch) {
            $batch->setIsExported(true);
        }
        $em->flush();

        $filename = sprintf('EXTF_Buchungsjournal_%d.csv', $year);

        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }

    #[Route('/opening-balance/new', name: 'journal.opening_balance.new', methods: ['GET'])]
    public function newOpeningBalance(
        Request $request,
        OpeningBalanceService $openingBalanceService,
        AccountingAccountRepository $accountRepo,
        AccountingSettingsService $settingsService,
    ): Response {
        $year = (int) $request->query->get('year', (int) date('Y'));
        $activePreset = $settingsService->getActivePreset();
        $cashAccount = $accountRepo->findCashAccount($activePreset);
        $bankAccount = $accountRepo->findBankAccount($activePreset);
        $openingAccount = $accountRepo->findOpeningBalanceAccount($activePreset);

        $cashAmount = $cashAccount ? $openingBalanceService->getAmount($year, $cashAccount) : 0.0;
        $bankAmount = $bankAccount ? $openingBalanceService->getAmount($year, $bankAccount) : 0.0;

        if (0.0 === $cashAmount && null !== $cashAccount) {
            $cashAmount = $openingBalanceService->getPriorYearCashClosing($year);
        }
        if (0.0 === $bankAmount && null !== $bankAccount) {
            $bankAmount = $openingBalanceService->getPriorYearBankClosing($year);
        }

        return $this->render('BookingJournal/_opening_balance_form.html.twig', [
            'year' => $year,
            'cashAccount' => $cashAccount,
            'bankAccount' => $bankAccount,
            'openingAccount' => $openingAccount,
            'cashAmount' => $cashAmount,
            'bankAmount' => $bankAmount,
        ]);
    }

    #[Route('/opening-balance/save', name: 'journal.opening_balance.save', methods: ['POST'])]
    public function saveOpeningBalance(
        Request $request,
        OpeningBalanceService $openingBalanceService,
        AccountingAccountRepository $accountRepo,
        AccountingSettingsService $settingsService,
    ): Response {
        if (!$this->isCsrfTokenValid('opening_balance', $request->request->get('_token'))) {
            $this->addFlash('danger', 'flash.invalidtoken');

            return $this->redirectToRoute('journal.overview');
        }

        $year = (int) $request->request->get('year', (int) date('Y'));
        $cashAmount = $request->request->get('cashAmount');
        $bankAmount = $request->request->get('bankAmount');
        $activePreset = $settingsService->getActivePreset();

        if (null === $accountRepo->findOpeningBalanceAccount($activePreset)) {
            $this->addFlash('warning', 'accounting.opening_balance.flash.no_account');

            return $this->redirectToRoute('journal.overview');
        }

        $cashAccount = $accountRepo->findCashAccount($activePreset);
        if (null !== $cashAccount) {
            $openingBalanceService->upsert($year, $cashAccount, '' === (string) $cashAmount ? null : (float) str_replace(',', '.', (string) $cashAmount));
        }

        $bankAccount = $accountRepo->findBankAccount($activePreset);
        if (null !== $bankAccount) {
            $openingBalanceService->upsert($year, $bankAccount, '' === (string) $bankAmount ? null : (float) str_replace(',', '.', (string) $bankAmount));
        }

        $this->addFlash('success', 'accounting.opening_balance.flash.saved');

        return $this->redirectToRoute('journal.overview');
    }

    #[Route('/batch/{id:batch}/export/pdf/{templateId:template.id}', name: 'journal.batch.export.pdf', methods: ['GET'])]
    public function exportPdf(
        BookingBatch $batch,
        Template $template,
        RequestStack $requestStack,
        TemplatesService $ts,
    ): Response {
        $requestStack->getSession()->set('cashjournal-template-id', $template->getId());

        try {
            $templateOutput = $ts->renderTemplate($template->getId(), $batch->getId());
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('warning', $e->getMessage());

            return $this->redirectToRoute('journal.batch.entries', ['id' => $batch->getId()]);
        }

        $pdfOutput = $ts->getPDFOutput(
            $templateOutput,
            'Kassenblatt-'.$batch->getYear().'-'.$batch->getMonth(),
            $template
        );

        $response = new Response($pdfOutput);
        $response->headers->set('Content-Type', 'application/pdf');

        return $response;
    }
}
