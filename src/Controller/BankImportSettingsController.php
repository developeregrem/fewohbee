<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AccountingAccountRepository;
use App\Repository\BankCsvProfileRepository;
use App\Repository\BankImportRuleRepository;
use App\Repository\InvoiceRepository;
use App\Repository\SubsidiaryRepository;
use App\Repository\TaxRateRepository;
use App\Service\AppSettingsService;
use App\Service\InvoiceNumberPatternService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/journal/bank-import/settings')]
#[IsGranted('ROLE_CASHJOURNAL')]
class BankImportSettingsController extends AbstractController
{
    public const VALID_TABS = ['tab-rules', 'tab-profiles', 'tab-invoice-matching'];
    public const DEFAULT_TAB = 'tab-rules';

    public static function urlForTab(string $tab): string
    {
        $tab = in_array($tab, self::VALID_TABS, true) ? $tab : self::DEFAULT_TAB;

        return $tab;
    }

    #[Route('', name: 'bank_import.settings', methods: ['GET'])]
    public function index(
        Request $request,
        BankImportRuleRepository $ruleRepo,
        BankCsvProfileRepository $profileRepo,
        AccountingAccountRepository $accountRepo,
        TaxRateRepository $taxRateRepo,
        SubsidiaryRepository $subsidiaryRepo,
        InvoiceRepository $invoiceRepo,
        AppSettingsService $appSettingsService,
        InvoiceNumberPatternService $patternService,
    ): Response {
        $requestedTab = (string) $request->query->get('tab', self::DEFAULT_TAB);
        $activeTab = in_array($requestedTab, self::VALID_TABS, true) ? $requestedTab : self::DEFAULT_TAB;

        $accountsById = [];
        foreach ($accountRepo->findAll() as $account) {
            $accountsById[(int) $account->getId()] = $account;
        }
        $taxRatesById = [];
        foreach ($taxRateRepo->findAll() as $taxRate) {
            $taxRatesById[(int) $taxRate->getId()] = $taxRate;
        }

        $ranges = $this->buildInvoiceNumberRanges($subsidiaryRepo, $appSettingsService, $patternService);

        return $this->render('BookingJournal/BankImport/settings.html.twig', [
            'activeTab' => $activeTab,
            'rules' => $ruleRepo->findAllOrdered(),
            'profiles' => $profileRepo->findAllOrdered(),
            'accountsById' => $accountsById,
            'taxRatesById' => $taxRatesById,
            'invoiceNumberRanges' => $ranges,
            // Only needed when nothing is configured: the numbers issued so far are the best
            // orientation for writing a matching pattern.
            'recentInvoiceNumbers' => [] === $ranges ? $invoiceRepo->findRecentNumbers() : [],
        ]);
    }

    /**
     * The number ranges the bank import recognises, one row per configured pattern.
     *
     * Read-only on purpose: the ranges are configured where they are used — globally under
     * the app settings, per branch in the branch administration — and duplicating that here
     * would let the two drift apart.
     *
     * @return list<array{scope: string, isGlobal: bool, pattern: string, example: string}>
     */
    private function buildInvoiceNumberRanges(
        SubsidiaryRepository $subsidiaryRepo,
        AppSettingsService $appSettingsService,
        InvoiceNumberPatternService $patternService,
    ): array {
        $today = new \DateTimeImmutable();
        $ranges = [];

        $global = $appSettingsService->getSettings()->getInvoiceNumberPattern();
        $compiledGlobal = $patternService->tryCompile($global);
        if (null !== $compiledGlobal) {
            $ranges[] = [
                'scope' => '',
                'isGlobal' => true,
                'pattern' => $compiledGlobal->pattern,
                'example' => $compiledGlobal->example($today),
            ];
        }

        foreach ($subsidiaryRepo->findWithInvoiceNumberPattern() as $subsidiary) {
            $compiled = $patternService->tryCompile($subsidiary->getInvoiceNumberPattern());
            if (null === $compiled) {
                continue;
            }
            $ranges[] = [
                'scope' => (string) $subsidiary->getName(),
                'isGlobal' => false,
                'pattern' => $compiled->pattern,
                'example' => $compiled->example($today),
            ];
        }

        return $ranges;
    }
}
