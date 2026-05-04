<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Attribute\ImportDraft;
use App\Exception\BankImportEditException;
use App\Dto\BookingJournal\BankImport\BankImportFormatChoice;
use App\Dto\BookingJournal\BankImport\ImportState;
use App\Dto\BookingJournal\BankImport\MultipleSourceAccountsException;
use App\Dto\BookingJournal\BankImport\ParseResult;
use App\Entity\AccountingAccount;
use App\Entity\BankCsvProfile;
use App\Entity\BankImportRule;
use App\Form\BankStatementUploadType;
use App\Repository\AccountingAccountRepository;
use App\Repository\BankStatementImportRepository;
use App\Repository\TaxRateRepository;
use App\Service\BookingJournal\AccountingSettingsService;
use App\Service\BookingJournal\BankImport\BankImportDraftSession;
use App\Service\BookingJournal\BankImport\BankImportRuleMatcher;
use App\Service\BookingJournal\BankImport\BankStatementCommitter;
use App\Service\BookingJournal\BankImport\BankStatementDeduplicator;
use App\Service\BookingJournal\BankImport\InvoiceMatcher;
use App\Service\BookingJournal\BankImport\Parser\BankStatementParserRegistry;
use App\Service\BookingJournal\BankImport\Parser\ParserInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/journal/bank-import')]
#[IsGranted('ROLE_CASHJOURNAL')]
class BankImportController extends AbstractController
{
    #[Route('', name: 'bank_import.index', methods: ['GET'])]
    public function index(BankImportDraftSession $drafts, AccountingAccountRepository $accountRepo): Response
    {
        $accountsById = [];
        foreach ($accountRepo->findAll() as $account) {
            $accountsById[$account->getId()] = $account;
        }

        $form = $this->createForm(BankStatementUploadType::class, null, [
            'action' => $this->generateUrl('bank_import.upload'),
        ]);

        return $this->render('BookingJournal/BankImport/index.html.twig', [
            'drafts' => $drafts->list(),
            'accountsById' => $accountsById,
            'uploadForm' => $form,
        ]);
    }

    #[Route('/upload', name: 'bank_import.upload', methods: ['POST'])]
    public function upload(
        Request $request,
        BankStatementParserRegistry $parsers,
        BankImportDraftSession $drafts,
        BankStatementDeduplicator $deduplicator,
        InvoiceMatcher $invoiceMatcher,
        BankImportRuleMatcher $ruleMatcher,
        BankStatementImportRepository $statementImportRepo,
        TranslatorInterface $translator,
    ): Response {
        $form = $this->createForm(BankStatementUploadType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('danger', $translator->trans('accounting.bank_import.upload.flash.invalid'));

            return $this->redirectToRoute('bank_import.index');
        }

        /** @var AccountingAccount $bankAccount */
        $bankAccount = $form->get('bankAccount')->getData();
        /** @var BankImportFormatChoice $format */
        $format = $form->get('format')->getData();
        $formatKey = $format->formatKey;
        $profile = $format->profile;
        $files = $this->uploadedFiles($form->get('file')->getData());

        if ([] === $files) {
            $this->addFlash('danger', $translator->trans('accounting.bank_import.upload.flash.invalid'));

            return $this->redirectToRoute('bank_import.index');
        }

        $parser = $parsers->get($formatKey);
        if (!$parser->supportsMultipleFiles() && 1 !== count($files)) {
            $this->addFlash('danger', $translator->trans('accounting.bank_import.upload.flash.csv_requires_single_file'));

            return $this->redirectToRoute('bank_import.index');
        }

        try {
            $result = $this->parseUploadedFiles($files, $parser, $profile);
        } catch (MultipleSourceAccountsException) {
            $this->addFlash('danger', $translator->trans('accounting.bank_import.parser.error.camt_multiple_accounts'));

            return $this->redirectToRoute('bank_import.index');
        } catch (\Throwable $e) {
            $this->addFlash('danger', $translator->trans('accounting.bank_import.upload.flash.parse_failed', [
                '%message%' => $e->getMessage(),
            ]));

            return $this->redirectToRoute('bank_import.index');
        }

        if ([] === $result->lines) {
            $this->addFlash('warning', $translator->trans('accounting.bank_import.upload.flash.no_lines'));

            return $this->redirectToRoute('bank_import.index');
        }

        $state = ImportState::fromParseResult(
            sessionImportId: '',
            bankAccountId: (int) $bankAccount->getId(),
            fileFormat: $formatKey,
            bankCsvProfileId: $profile?->getId(),
            originalFilename: $this->originalFilename($files, $translator),
            result: $result,
        );

        $this->appendOverlapWarnings($state, $bankAccount, $statementImportRepo, $translator);
        $this->prefillBankAccount($state, $bankAccount);
        $deduplicator->annotate($state, $bankAccount);
        $invoiceMatcher->annotate($state);
        $ruleMatcher->annotate($state, $bankAccount);
        $state->normalizeLineStatuses();

        $sessionImportId = $drafts->create($state);

        if (null !== $state->sourceIban && null !== $bankAccount->getIban()
            && $state->sourceIban !== $bankAccount->getIban()) {
            $this->addFlash('warning', $translator->trans('accounting.bank_import.upload.flash.iban_mismatch', [
                '%file%' => $state->sourceIban,
                '%account%' => $bankAccount->getIban(),
            ]));
        }

        return $this->redirectToRoute('bank_import.preview', ['sessionImportId' => $sessionImportId]);
    }

    /**
     * @return list<UploadedFile>
     */
    private function uploadedFiles(mixed $data): array
    {
        if ($data instanceof UploadedFile) {
            return [$data];
        }
        if (!is_array($data)) {
            return [];
        }

        return array_values(array_filter($data, static fn (mixed $file): bool => $file instanceof UploadedFile));
    }

    /**
     * @param list<UploadedFile> $files
     */
    private function parseUploadedFiles(array $files, ParserInterface $parser, ?BankCsvProfile $profile): ParseResult
    {
        if (1 === count($files)) {
            return $parser->parse(new \SplFileInfo($files[0]->getPathname()), $profile);
        }

        $results = [];
        foreach ($files as $file) {
            $results[] = $parser->parse(new \SplFileInfo($file->getPathname()), $profile);
        }

        return ParseResult::merge($results);
    }

    /**
     * @param list<UploadedFile> $files
     */
    private function originalFilename(array $files, TranslatorInterface $translator): string
    {
        if (1 === count($files)) {
            return $files[0]->getClientOriginalName();
        }

        return $translator->trans('accounting.bank_import.upload.multiple_files_name', [
            '%count%' => count($files),
        ]);
    }

    #[Route('/{sessionImportId}', name: 'bank_import.preview', methods: ['GET'], requirements: ['sessionImportId' => '[0-9a-f-]{36}'])]
    public function preview(
        string $sessionImportId,
        BankImportDraftSession $drafts,
        AccountingAccountRepository $accountRepo,
        TaxRateRepository $taxRateRepo,
        AccountingSettingsService $settingsService,
    ): Response {
        $state = $drafts->load($sessionImportId);
        if (null === $state) {
            $this->addFlash('warning', 'accounting.bank_import.draft.not_found');

            return $this->redirectToRoute('bank_import.index');
        }

        $bankAccount = $accountRepo->find($state->bankAccountId);
        if (null === $bankAccount) {
            $drafts->discard($sessionImportId);
            $this->addFlash('danger', 'accounting.bank_import.draft.account_missing');

            return $this->redirectToRoute('bank_import.index');
        }

        $activePreset = $bankAccount->getChartPreset();
        $accounts = $accountRepo->findAllOrdered($activePreset);
        $taxRates = $taxRateRepo->findValidAt(new \DateTimeImmutable(), $activePreset);

        return $this->render('BookingJournal/BankImport/preview.html.twig', [
            'state' => $state,
            'bankAccount' => $bankAccount,
            'counts' => $state->countByStatus(),
            'accounts' => $accounts,
            'taxRates' => $taxRates,
            'invoiceMatchingDisabled' => [] === $settingsService->getSettings()->getInvoiceNumberSamples(),
        ]);
    }

    #[Route('/{sessionImportId}/line/{idx}', name: 'bank_import.line.update', methods: ['POST'], requirements: ['sessionImportId' => '[0-9a-f-]{36}', 'idx' => '\d+'])]
    public function updateLine(
        int $idx,
        Request $request,
        #[ImportDraft] ImportState $state,
        BankImportDraftSession $drafts,
    ): JsonResponse {
        if (!isset($state->lines[$idx])) {
            throw BankImportEditException::lineNotFound();
        }

        if (true === ($state->lines[$idx]['isDuplicate'] ?? false)) {
            // Duplicates are read-only — silently ignore the change.
            return new JsonResponse(['status' => $state->lines[$idx]['status']]);
        }

        $field = (string) $request->request->get('field');
        $value = $request->request->get('value');

        $line = &$state->lines[$idx];

        switch ($field) {
            case 'debitAccountId':
                $line['userDebitAccountId'] = $this->normalizeAccountId($value);
                break;
            case 'creditAccountId':
                $line['userCreditAccountId'] = $this->normalizeAccountId($value);
                break;
            case 'taxRateId':
                $line['userTaxRateId'] = $this->normalizeTaxRateId($value);
                break;
            case 'remark':
                $remark = trim((string) $value);
                $line['userRemark'] = '' === $remark ? null : mb_substr($remark, 0, 255);
                break;
            case 'isIgnored':
                $line['isIgnored'] = (bool) ((int) $value);
                break;
            default:
                return new JsonResponse(['error' => 'unknown_field'], Response::HTTP_BAD_REQUEST);
        }

        $line['status'] = ImportState::deriveLineStatus($line);
        unset($line);

        $drafts->save($state);

        return new JsonResponse([
            'status' => $state->lines[$idx]['status'],
            'counts' => $state->countByStatus(),
        ]);
    }

    #[Route('/{sessionImportId}/line/{idx}/split', name: 'bank_import.line.split', methods: ['POST'], requirements: ['sessionImportId' => '[0-9a-f-]{36}', 'idx' => '\d+'])]
    public function splitLine(
        int $idx,
        Request $request,
        #[ImportDraft] ImportState $state,
        BankImportDraftSession $drafts,
    ): JsonResponse {
        if (!isset($state->lines[$idx])) {
            throw BankImportEditException::lineNotFound();
        }

        if (true === ($state->lines[$idx]['isDuplicate'] ?? false)) {
            throw BankImportEditException::lineReadonly();
        }

        $rawSplits = $request->request->all('splits');
        if (!is_array($rawSplits)) {
            $rawSplits = [];
        }

        $line = &$state->lines[$idx];
        $isOutgoing = ((float) ($line['amount'] ?? 0)) < 0.0;
        $splits = [];

        foreach ($rawSplits as $piece) {
            if (!is_array($piece)) {
                continue;
            }

            $absAmount = abs((float) ($piece['amount'] ?? 0));
            if ($absAmount <= 0) {
                continue;
            }
            $signed = $isOutgoing ? -$absAmount : $absAmount;

            $splits[] = [
                'amount' => number_format($signed, 2, '.', ''),
                'debitAccountId' => $this->normalizeAccountId($piece['debitAccountId'] ?? null),
                'creditAccountId' => $this->normalizeAccountId($piece['creditAccountId'] ?? null),
                'taxRateId' => $this->normalizeTaxRateId($piece['taxRateId'] ?? null),
                'remark' => $this->cleanRemark($piece['remark'] ?? null),
            ];
        }

        $line['splits'] = $splits;
        $line['status'] = ImportState::deriveLineStatus($line);
        unset($line);

        $drafts->save($state);

        return new JsonResponse([
            'status' => $state->lines[$idx]['status'],
            'splitCount' => count($splits),
            'counts' => $state->countByStatus(),
        ]);
    }

    #[Route('/{sessionImportId}/line/{idx}/rule', name: 'bank_import.line.save_rule', methods: ['POST'], requirements: ['sessionImportId' => '[0-9a-f-]{36}', 'idx' => '\d+'])]
    public function saveRuleFromLine(
        int $idx,
        Request $request,
        #[ImportDraft] ImportState $state,
        BankImportDraftSession $drafts,
        AccountingAccountRepository $accountRepo,
        BankImportRuleMatcher $ruleMatcher,
        EntityManagerInterface $em,
    ): JsonResponse {
        if (!isset($state->lines[$idx])) {
            throw BankImportEditException::lineNotFound();
        }

        $bankAccount = $accountRepo->find($state->bankAccountId);
        if (null === $bankAccount) {
            return new JsonResponse(['error' => 'account_missing'], Response::HTTP_NOT_FOUND);
        }

        $name = trim((string) $request->request->get('name'));
        if ('' === $name) {
            return new JsonResponse(['error' => 'name_required'], Response::HTTP_BAD_REQUEST);
        }

        $conditions = $this->buildConditionsFromRequest($request, $state->lines[$idx]);
        if ([] === $conditions) {
            return new JsonResponse(['error' => 'condition_required'], Response::HTTP_BAD_REQUEST);
        }

        $action = $this->buildActionFromRequest($request);

        $rule = new BankImportRule();
        $rule->setName($name);
        $rule->setPriority((int) $request->request->get('priority', 50));
        $rule->setIsEnabled(true);
        $rule->setConditions($conditions);
        $rule->setAction($action);

        if ('1' === (string) $request->request->get('scopeToBankAccount')) {
            $rule->setBankAccount($bankAccount);
        }

        $em->persist($rule);
        $em->flush();

        // Re-run the rule matcher so the freshly saved rule is applied to all
        // remaining unassigned lines in this draft immediately.
        $ruleMatcher->annotate($state, $bankAccount);
        $drafts->save($state);

        return new JsonResponse([
            'ruleId' => $rule->getId(),
            'counts' => $state->countByStatus(),
        ]);
    }

    #[Route('/{sessionImportId}/bulk', name: 'bank_import.bulk', methods: ['POST'], requirements: ['sessionImportId' => '[0-9a-f-]{36}'])]
    public function bulkAction(
        Request $request,
        #[ImportDraft] ImportState $state,
        BankImportDraftSession $drafts,
    ): JsonResponse {
        $action = (string) $request->request->get('action');
        $indices = $request->request->all('indices');
        $indices = array_map('intval', is_array($indices) ? $indices : []);

        $touched = 0;
        foreach ($indices as $idx) {
            if (!isset($state->lines[$idx])) {
                continue;
            }
            if (true === ($state->lines[$idx]['isDuplicate'] ?? false)) {
                continue;
            }

            $line = &$state->lines[$idx];

            switch ($action) {
                case 'ignore':
                    $line['isIgnored'] = true;
                    break;
                case 'unignore':
                    $line['isIgnored'] = false;
                    break;
                case 'assign_debit':
                    $line['userDebitAccountId'] = $this->normalizeAccountId($request->request->get('debitAccountId'));
                    break;
                case 'assign_credit':
                    $line['userCreditAccountId'] = $this->normalizeAccountId($request->request->get('creditAccountId'));
                    break;
                default:
                    unset($line);

                    return new JsonResponse(['error' => 'unknown_action'], Response::HTTP_BAD_REQUEST);
            }

            $line['status'] = ImportState::deriveLineStatus($line);
            unset($line);
            ++$touched;
        }

        $drafts->save($state);

        return new JsonResponse([
            'touched' => $touched,
            'counts' => $state->countByStatus(),
        ]);
    }

    #[Route('/{sessionImportId}/commit', name: 'bank_import.commit', methods: ['POST'], requirements: ['sessionImportId' => '[0-9a-f-]{36}'])]
    public function commit(
        string $sessionImportId,
        Request $request,
        BankImportDraftSession $drafts,
        AccountingAccountRepository $accountRepo,
        BankStatementCommitter $committer,
        TranslatorInterface $translator,
    ): Response {
        if (!$this->isCsrfTokenValid('bank_import_line_'.$sessionImportId, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'flash.invalidtoken');

            return $this->redirectToRoute('bank_import.preview', ['sessionImportId' => $sessionImportId]);
        }

        $state = $drafts->load($sessionImportId);
        if (null === $state) {
            $this->addFlash('warning', 'accounting.bank_import.draft.not_found');

            return $this->redirectToRoute('bank_import.index');
        }

        $bankAccount = $accountRepo->find($state->bankAccountId);
        if (null === $bankAccount) {
            $drafts->discard($sessionImportId);
            $this->addFlash('danger', 'accounting.bank_import.draft.account_missing');

            return $this->redirectToRoute('bank_import.index');
        }

        $user = $this->getUser();
        try {
            $result = $committer->commit($state, $bankAccount, $user instanceof \App\Entity\User ? $user : null);
        } catch (\Throwable $e) {
            $this->addFlash('danger', $translator->trans('accounting.bank_import.commit.flash.failed', [
                '%message%' => $e->getMessage(),
            ]));

            return $this->redirectToRoute('bank_import.preview', ['sessionImportId' => $sessionImportId]);
        }

        $this->addFlash('success', $translator->trans('accounting.bank_import.commit.flash.done', [
            '%committed%' => $result['committed'],
            '%ignored%' => $result['ignored'],
            '%duplicates%' => $result['duplicates'],
            '%redated%' => $result['redated'],
        ]));

        return $this->redirectToRoute('journal.overview');
    }

    #[Route('/{sessionImportId}/discard', name: 'bank_import.discard', methods: ['DELETE'], requirements: ['sessionImportId' => '[0-9a-f-]{36}'])]
    public function discard(
        string $sessionImportId,
        Request $request,
        BankImportDraftSession $drafts,
    ): Response {
        if (!$this->isCsrfTokenValid('delete'.$sessionImportId, $request->request->get('_token'))) {
            $this->addFlash('danger', 'flash.invalidtoken');

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $drafts->discard($sessionImportId);
        $this->addFlash('success', 'accounting.bank_import.draft.discarded');

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * Builds rule conditions from the form. The user picks which fields of
     * the source line should become conditions; we copy the line's actual
     * values in and use sensible operators per field.
     *
     * @param array<string, mixed> $line
     *
     * @return list<array{field: string, operator: string, value: mixed}>
     */
    private function buildConditionsFromRequest(Request $request, array $line): array
    {
        $fields = $request->request->all('conditionFields');
        if (!is_array($fields)) {
            return [];
        }

        $conditions = [];
        foreach ($fields as $field) {
            switch ($field) {
                case BankImportRule::CONDITION_FIELD_COUNTERPARTY_NAME:
                    $value = trim((string) ($line['counterpartyName'] ?? ''));
                    if ('' !== $value) {
                        $conditions[] = ['field' => $field, 'operator' => BankImportRule::CONDITION_OP_CONTAINS, 'value' => $value];
                    }
                    break;
                case BankImportRule::CONDITION_FIELD_COUNTERPARTY_IBAN:
                    $value = trim((string) ($line['counterpartyIban'] ?? ''));
                    if ('' !== $value) {
                        $conditions[] = ['field' => $field, 'operator' => BankImportRule::CONDITION_OP_EQUALS, 'value' => $value];
                    }
                    break;
                case BankImportRule::CONDITION_FIELD_PURPOSE:
                    $needle = trim((string) $request->request->get('purposeContains', ''));
                    if ('' !== $needle) {
                        $conditions[] = ['field' => $field, 'operator' => BankImportRule::CONDITION_OP_CONTAINS, 'value' => $needle];
                    }
                    break;
                case BankImportRule::CONDITION_FIELD_DIRECTION:
                    $direction = ((float) ($line['amount'] ?? 0)) >= 0.0 ? 'in' : 'out';
                    $conditions[] = ['field' => $field, 'operator' => BankImportRule::CONDITION_OP_EQUALS, 'value' => $direction];
                    break;
            }
        }

        return $conditions;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildActionFromRequest(Request $request): array
    {
        $mode = (string) $request->request->get('actionMode', BankImportRule::ACTION_MODE_ASSIGN);

        if (BankImportRule::ACTION_MODE_IGNORE === $mode) {
            return ['mode' => BankImportRule::ACTION_MODE_IGNORE];
        }

        if (BankImportRule::ACTION_MODE_SPLIT === $mode) {
            $rawSplits = $request->request->all('splits');
            $splits = [];
            foreach (is_array($rawSplits) ? $rawSplits : [] as $piece) {
                if (!is_array($piece)) {
                    continue;
                }

                $base = [
                    'debitAccountId' => $this->normalizeAccountId($piece['debitAccountId'] ?? null),
                    'creditAccountId' => $this->normalizeAccountId($piece['creditAccountId'] ?? null),
                    'taxRateId' => $this->normalizeTaxRateId($piece['taxRateId'] ?? null),
                    'remarkTemplate' => $this->cleanRemark($piece['remark'] ?? null),
                ];

                if ('purpose_marker' === (string) ($piece['amountSource'] ?? '')) {
                    $marker = trim((string) ($piece['marker'] ?? ''));
                    if ('' === $marker) {
                        continue;
                    }

                    $splits[] = $base + [
                        'amountSource' => 'purpose_marker',
                        'marker' => mb_substr($marker, 0, 120),
                    ];
                    continue;
                }

                if ($this->truthy($piece['remainder'] ?? false)) {
                    $splits[] = $base + ['remainder' => true];
                    continue;
                }

                if (isset($piece['percent'])) {
                    $percent = abs((float) $piece['percent']);
                    if ($percent > 0) {
                        $splits[] = $base + ['percent' => round($percent, 4)];
                    }
                    continue;
                }

                $absAmount = abs((float) ($piece['amount'] ?? 0));
                if ($absAmount <= 0) {
                    continue;
                }
                $splits[] = $base + ['amount' => round($absAmount, 2)];
            }

            return ['mode' => BankImportRule::ACTION_MODE_SPLIT, 'splits' => $splits];
        }

        return [
            'mode' => BankImportRule::ACTION_MODE_ASSIGN,
            'debitAccountId' => $this->normalizeAccountId($request->request->get('debitAccountId')),
            'creditAccountId' => $this->normalizeAccountId($request->request->get('creditAccountId')),
            'taxRateId' => $this->normalizeTaxRateId($request->request->get('taxRateId')),
            'remarkTemplate' => $this->cleanRemark($request->request->get('remarkTemplate')),
        ];
    }

    private function cleanRemark(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = trim((string) $value);

        return '' === $value ? null : mb_substr($value, 0, 255);
    }

    private function truthy(mixed $value): bool
    {
        return true === $value || 1 === $value || in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Pre-fills the bank account on the side of every line that the user
     * doesn't have to think about: debit for incoming amounts, credit for
     * outgoing. Rules and manual edits later are free to overwrite this.
     */
    private function prefillBankAccount(ImportState $state, AccountingAccount $bankAccount): void
    {
        $bankAccountId = (int) $bankAccount->getId();

        foreach ($state->lines as &$line) {
            if (((float) ($line['amount'] ?? 0)) >= 0.0) {
                $line['userDebitAccountId'] = $bankAccountId;
            } else {
                $line['userCreditAccountId'] = $bankAccountId;
            }
        }
    }

    private function normalizeAccountId(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function normalizeTaxRateId(mixed $value): ?int
    {
        return $this->normalizeAccountId($value);
    }

    private function appendOverlapWarnings(
        ImportState $state,
        AccountingAccount $bankAccount,
        BankStatementImportRepository $statementImportRepo,
        TranslatorInterface $translator,
    ): void {
        if (null === $state->periodFrom || null === $state->periodTo) {
            return;
        }

        try {
            $periodFrom = new \DateTime($state->periodFrom);
            $periodTo = new \DateTime($state->periodTo);
        } catch (\Throwable) {
            return;
        }

        $overlaps = $statementImportRepo->findOverlapping($bankAccount, $periodFrom, $periodTo);
        if ([] === $overlaps) {
            return;
        }

        $examples = [];
        foreach (array_slice($overlaps, 0, 3) as $import) {
            $from = $import->getPeriodFrom()?->format('d.m.Y') ?? '?';
            $to = $import->getPeriodTo()?->format('d.m.Y') ?? '?';
            $committedAt = $import->getCommittedAt()?->format('d.m.Y H:i') ?? '?';
            $examples[] = $translator->trans('accounting.bank_import.preview.overlap_warning.import', [
                '%from%' => $from,
                '%to%' => $to,
                '%committedAt%' => $committedAt,
                '%committed%' => $import->getLineCountCommitted(),
            ]);
        }

        $more = max(0, count($overlaps) - count($examples));
        $moreLabel = $more > 0
            ? $translator->trans('accounting.bank_import.preview.overlap_warning.more', ['%count%' => $more])
            : '';
        $state->warnings[] = $translator->trans('accounting.bank_import.preview.overlap_warning.message', [
            '%from%' => $periodFrom->format('d.m.Y'),
            '%to%' => $periodTo->format('d.m.Y'),
            '%count%' => count($overlaps),
            '%imports%' => implode('; ', $examples),
            '%more%' => $moreLabel,
        ]);
    }
}
