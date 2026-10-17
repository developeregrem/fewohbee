<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AccountingAccount;
use App\Entity\ReceiptProposal;
use App\Entity\User;
use App\Repository\AccountingAccountRepository;
use App\Repository\BookingEntryRepository;
use App\Repository\ReceiptProposalRepository;
use App\Repository\TaxRateRepository;
use App\Service\BookingJournal\AccountingSettingsService;
use App\Service\BookingJournal\Receipt\ReceiptBookingException;
use App\Service\BookingJournal\Receipt\ReceiptBookingRequest;
use App\Service\BookingJournal\Receipt\ReceiptLine;
use App\Service\BookingJournal\Receipt\ReceiptProposalService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Receipts handed in by AI assistants, waiting in the booking journal until a person checks them,
 * picks the payment they belong to and books or discards them.
 */
#[Route('/journal/receipts')]
#[IsGranted('ROLE_CASHJOURNAL')]
final class ReceiptProposalController extends AbstractController
{
    private const EXTRA_LINES = 2;
    private const RECENTLY_DECIDED = 10;

    public function __construct(
        private readonly ReceiptProposalService $proposalService,
        private readonly AccountingAccountRepository $accountRepository,
        private readonly TaxRateRepository $taxRateRepository,
        private readonly AccountingSettingsService $settingsService,
    ) {
    }

    #[Route('', name: 'journal.receipts.index', methods: ['GET'])]
    public function index(ReceiptProposalRepository $repository): Response
    {
        return $this->render('BookingJournal/ReceiptProposal/index.html.twig', [
            'open' => $repository->findOpen(),
            'decided' => $repository->findRecentlyDecided(self::RECENTLY_DECIDED),
        ]);
    }

    #[Route('/{id}', name: 'journal.receipts.show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(ReceiptProposal $proposal, BookingEntryRepository $entryRepository): Response
    {
        if (!$proposal->isOpen()) {
            return $this->render('BookingJournal/ReceiptProposal/decided.html.twig', [
                'proposal' => $proposal,
                'entries' => $entryRepository->findBy(['id' => $proposal->getBookedEntryIds()], ['id' => 'ASC']),
            ]);
        }

        $candidates = $this->proposalService->paymentCandidates($proposal);
        $bookedAlready = $this->proposalService->possiblyBookedAlready($proposal);

        return $this->renderForm($proposal, $candidates, $bookedAlready, $this->defaults($proposal, $candidates, $bookedAlready));
    }

    #[Route('/{id}/book', name: 'journal.receipts.book', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function book(ReceiptProposal $proposal, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::tokenId($proposal), $request->request->getString('_token'))) {
            $this->addFlash('danger', 'flash.invalidtoken');

            return $this->redirectToRoute('journal.receipts.show', ['id' => $proposal->getId()]);
        }

        $values = self::submittedValues($request);
        try {
            $bookingRequest = $this->buildRequest($proposal, $values);
            $this->proposalService->book($proposal, $bookingRequest, $this->currentUser());
        } catch (ReceiptBookingException $error) {
            return $this->renderForm($proposal, $this->proposalService->paymentCandidates($proposal), $this->proposalService->possiblyBookedAlready($proposal), $values, $error, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', 'accounting.receipt_proposal.flash.booked');

        return $this->redirectToRoute('journal.receipts.index');
    }

    #[Route('/{id}/discard', name: 'journal.receipts.discard', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function discard(ReceiptProposal $proposal, Request $request): Response
    {
        // Token id as rendered by the shared delete popover ('delete' ~ id).
        if (!$this->isCsrfTokenValid('delete'.$proposal->getId(), $request->request->getString('_token'))) {
            $this->addFlash('danger', 'flash.invalidtoken');

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $this->proposalService->discard($proposal, $this->currentUser());
        $this->addFlash('success', 'accounting.receipt_proposal.flash.discarded');

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    public static function tokenId(ReceiptProposal $proposal): string
    {
        return 'receipt_proposal_'.$proposal->getId();
    }

    /**
     * @param list<\App\Entity\BookingEntry>                                                                                               $candidates
     * @param list<\App\Entity\BookingEntry>                                                                                               $bookedAlready
     * @param array{payment: string, remark: string, invoiceNumber: string, lines: list<array{accountNumber: string, amount: string, taxRate: string}>} $values
     */
    private function renderForm(ReceiptProposal $proposal, array $candidates, array $bookedAlready, array $values, ?ReceiptBookingException $error = null, int $status = Response::HTTP_OK): Response
    {
        $preset = $this->settingsService->getActivePreset();
        $accounts = $this->accountRepository->findAllOrdered($preset);

        return $this->render('BookingJournal/ReceiptProposal/show.html.twig', [
            'proposal' => $proposal,
            'candidates' => $candidates,
            'bookedAlready' => $bookedAlready,
            'values' => $values,
            'error' => $error,
            'partAccounts' => array_values(array_filter($accounts, static fn (AccountingAccount $a): bool => !$a->isCashAccount() && !$a->isBankAccount())),
            'paymentAccounts' => array_values(array_filter($accounts, static fn (AccountingAccount $a): bool => $a->isCashAccount() || $a->isBankAccount())),
            'taxRates' => $this->taxRateRepository->findValidAt($proposal->getReceiptDate(), $preset),
            'tokenId' => self::tokenId($proposal),
            'extraLines' => self::EXTRA_LINES,
        ], new Response(status: $status));
    }

    /**
     * What the form starts with: the suggested parts, the likeliest payment and the supplier as
     * booking text. When the receipt looks booked already and no payment is left to split,
     * nothing is preselected: booking anew must be a deliberate choice then.
     *
     * @param list<\App\Entity\BookingEntry> $candidates
     * @param list<\App\Entity\BookingEntry> $bookedAlready
     *
     * @return array{payment: string, remark: string, invoiceNumber: string, lines: list<array{accountNumber: string, amount: string, taxRate: string}>}
     */
    private function defaults(ReceiptProposal $proposal, array $candidates, array $bookedAlready): array
    {
        if ([] !== $candidates) {
            $payment = 'entry:'.$candidates[0]->getId();
        } elseif ([] !== $bookedAlready) {
            $payment = '';
        } else {
            $preset = $this->settingsService->getActivePreset();
            $account = ReceiptProposal::PAYMENT_CASH === $proposal->getPayment()
                ? $this->accountRepository->findCashAccount($preset)
                : $this->accountRepository->findBankAccount($preset);
            $payment = null !== $account ? 'new:'.$account->getAccountNumber() : '';
        }

        return [
            'payment' => $payment,
            'remark' => $proposal->getSupplier(),
            'invoiceNumber' => (string) $proposal->getReceiptNumber(),
            'lines' => array_map(static fn (array $line): array => [
                'accountNumber' => (string) $line['accountNumber'],
                'amount' => number_format((float) $line['amount'], 2, ',', ''),
                // Same format as TaxRate::getRate(), so the select finds its option.
                'taxRate' => null !== $line['taxRate'] ? number_format((float) $line['taxRate'], 2, '.', '') : '',
            ], $proposal->getLines()),
        ];
    }

    /**
     * The form fields, read one by one (never bound wholesale to an entity).
     *
     * @return array{payment: string, remark: string, invoiceNumber: string, lines: list<array{accountNumber: string, amount: string, taxRate: string}>}
     */
    private static function submittedValues(Request $request): array
    {
        $lines = [];
        foreach ($request->request->all('lines') as $line) {
            if (!\is_array($line)) {
                continue;
            }
            $lines[] = [
                'accountNumber' => trim((string) ($line['accountNumber'] ?? '')),
                'amount' => trim((string) ($line['amount'] ?? '')),
                'taxRate' => trim((string) ($line['taxRate'] ?? '')),
            ];
        }

        return [
            'payment' => $request->request->getString('payment'),
            'remark' => trim($request->request->getString('remark')),
            'invoiceNumber' => trim($request->request->getString('invoiceNumber')),
            'lines' => $lines,
        ];
    }

    /**
     * @param array{payment: string, remark: string, invoiceNumber: string, lines: list<array{accountNumber: string, amount: string, taxRate: string}>} $values
     */
    private function buildRequest(ReceiptProposal $proposal, array $values): ReceiptBookingRequest
    {
        $lines = [];
        foreach ($values['lines'] as $index => $line) {
            // Rows left empty (the spare ones) are skipped; a half-filled row is an error.
            if ('' === $line['amount'] && '' === $line['accountNumber']) {
                continue;
            }
            if ('' === $line['accountNumber']) {
                throw new ReceiptBookingException('accounting.receipt_proposal.error.account_missing', ['%line%' => $index + 1]);
            }
            $lines[] = new ReceiptLine($line['accountNumber'], self::normalizeAmount($line['amount']), '' !== $line['taxRate'] ? $line['taxRate'] : null);
        }

        $remark = '' !== $values['remark'] ? mb_substr($values['remark'], 0, 255) : null;
        $invoiceNumber = '' !== $values['invoiceNumber'] ? mb_substr($values['invoiceNumber'], 0, 50) : null;

        if (1 === preg_match('/^entry:(\d+)$/', $values['payment'], $match)) {
            return new ReceiptBookingRequest(lines: $lines, entryId: (int) $match[1], invoiceNumber: $invoiceNumber, remark: $remark);
        }
        if (1 === preg_match('/^new:(\S+)$/', $values['payment'], $match)) {
            return new ReceiptBookingRequest(
                lines: $lines,
                date: $proposal->getReceiptDate(),
                paymentAccountNumber: $match[1],
                invoiceNumber: $invoiceNumber,
                remark: $remark,
            );
        }

        throw new ReceiptBookingException('accounting.receipt_proposal.error.payment_missing');
    }

    /** "1.234,56" and "1234.56" both become "1234.56"; anything else is left for the service to refuse. */
    private static function normalizeAmount(string $amount): string
    {
        $amount = str_replace(' ', '', $amount);
        if (str_contains($amount, ',')) {
            $amount = str_replace(['.', ','], ['', '.'], $amount);
        }

        return $amount;
    }

    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
