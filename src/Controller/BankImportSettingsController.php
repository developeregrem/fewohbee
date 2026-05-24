<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\BankImportSettingsType;
use App\Repository\AccountingAccountRepository;
use App\Repository\BankCsvProfileRepository;
use App\Repository\BankImportRuleRepository;
use App\Repository\TaxRateRepository;
use App\Service\BookingJournal\AccountingSettingsService;
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

    #[Route('', name: 'bank_import.settings', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        AccountingSettingsService $settingsService,
        BankImportRuleRepository $ruleRepo,
        BankCsvProfileRepository $profileRepo,
        AccountingAccountRepository $accountRepo,
        TaxRateRepository $taxRateRepo,
    ): Response {
        $settings = $settingsService->getSettings();
        $form = $this->createForm(BankImportSettingsType::class, $settings, [
            'action' => $this->generateUrl('bank_import.settings', ['tab' => 'tab-invoice-matching']),
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $settingsService->saveSettings($settings);
            $this->addFlash('success', 'accounting.bank_import.settings.flash.saved');

            return $this->redirect($this->generateUrl('bank_import.settings', ['tab' => 'tab-invoice-matching']));
        }

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

        return $this->render('BookingJournal/BankImport/settings.html.twig', [
            'form' => $form,
            'activeTab' => $activeTab,
            'rules' => $ruleRepo->findAllOrdered(),
            'profiles' => $profileRepo->findAllOrdered(),
            'accountsById' => $accountsById,
            'taxRatesById' => $taxRatesById,
        ]);
    }
}
