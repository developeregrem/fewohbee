<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AccountingAccount;
use App\Entity\AccountingSettings;
use App\Entity\TaxRate;
use App\Form\AccountingAccountType;
use App\Form\AccountingSettingsType;
use App\Form\TaxRateType;
use App\Repository\AccountingAccountRepository;
use App\Repository\PriceRepository;
use App\Repository\TaxRateRepository;
use App\Service\AccountingPresetSeeder;
use App\Service\AccountingSettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/journal/settings')]
#[IsGranted('ROLE_CASHJOURNAL')]
class BookingJournalSettingsController extends AbstractController
{
    #[Route('', name: 'journal.settings.index', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        AccountingSettingsService $settingsService,
        AccountingAccountRepository $accountRepo,
        TaxRateRepository $taxRateRepo,
    ): Response {
        $settings = $settingsService->getSettings();
        $settingsForm = $this->createForm(AccountingSettingsType::class, $settings, [
            'action' => $this->generateUrl('journal.settings.index'),
        ]);

        $settingsForm->handleRequest($request);

        if ($settingsForm->isSubmitted() && $settingsForm->isValid()) {
            $settingsService->saveSettings($settings);
            $this->addFlash('success', 'accounting.settings.flash.saved');

            return $this->redirectToRoute('journal.settings.index');
        }

        $activePreset = $settings->getChartPreset();

        return $this->render('BookingJournal/settings.html.twig', [
            'settings' => $settings,
            'settingsForm' => $settingsForm,
            'accounts' => $accountRepo->findAllOrdered($activePreset),
            'taxRates' => $taxRateRepo->findAllOrdered($activePreset),
            'presets' => AccountingSettings::VALID_PRESETS,
        ]);
    }

    // ── Preset ───────────────────────────────────────────────────────

    #[Route('/preset/load', name: 'journal.settings.preset.load', methods: ['POST'])]
    public function loadPreset(
        Request $request,
        AccountingSettingsService $settingsService,
        AccountingPresetSeeder $seeder,
        TranslatorInterface $translator,
        PriceRepository $priceRepo,
    ): Response {
        $preset = $request->request->get('preset');
        if (!in_array($preset, AccountingSettings::VALID_PRESETS, true)) {
            $this->addFlash('warning', $translator->trans('accounting.settings.flash.preset_invalid'));

            return $this->redirectToRoute('journal.settings.index');
        }

        $accountsCreated = $seeder->seedAccounts($preset);
        $taxRatesCreated = $seeder->seedTaxRates($preset);

        $workflowsCreated = 0;
        if ($request->request->getBoolean('seedWorkflows')) {
            $workflowsCreated = $seeder->seedWorkflows($preset);
        }

        $settings = $settingsService->getSettings();
        $settings->setChartPreset($preset);
        $settingsService->saveSettings($settings);

        $this->addFlash('success', $translator->trans('accounting.settings.flash.preset_loaded', [
            '%accounts%' => $accountsCreated,
            '%taxRates%' => $taxRatesCreated,
            '%workflows%' => $workflowsCreated,
            '%preset%' => strtoupper($preset),
        ]));

        $staleRefs = $priceRepo->countStaleRevenueAccountRefs($preset);
        if ($staleRefs > 0) {
            $this->addFlash('warning', $translator->trans('accounting.settings.flash.preset_stale_account_refs', [
                '%count%' => $staleRefs,
            ]));
        }

        return $this->redirectToRoute('journal.settings.index');
    }

    // ── Accounts CRUD ────────────────────────────────────────────────

    #[Route('/accounts/new', name: 'journal.settings.accounts.new', methods: ['GET'])]
    public function newAccount(): Response
    {
        $form = $this->createForm(AccountingAccountType::class, new AccountingAccount(), [
            'action' => $this->generateUrl('journal.settings.accounts.create'),
        ]);

        return $this->renderAccountForm($form);
    }

    #[Route('/accounts/create', name: 'journal.settings.accounts.create', methods: ['POST'])]
    public function createAccount(
        Request $request,
        EntityManagerInterface $em,
        AccountingAccountRepository $accountRepo,
    ): Response {
        $account = new AccountingAccount();
        $form = $this->createForm(AccountingAccountType::class, $account);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->ensureExclusiveOpeningBalanceAccount($account, $accountRepo);

            $em->persist($account);
            $em->flush();

            $this->addFlash('success', 'accounting.accounts.flash.created');

            return $this->redirectToRoute('journal.settings.index');
        }

        return $this->renderAccountForm($form);
    }

    #[Route('/accounts/{id}/edit', name: 'journal.settings.accounts.edit', methods: ['GET'])]
    public function editAccount(AccountingAccount $account): Response
    {
        $form = $this->createForm(AccountingAccountType::class, $account, [
            'action' => $this->generateUrl('journal.settings.accounts.update', ['id' => $account->getId()]),
        ]);

        return $this->renderAccountForm($form);
    }

    #[Route('/accounts/{id}/update', name: 'journal.settings.accounts.update', methods: ['POST'])]
    public function updateAccount(
        AccountingAccount $account,
        Request $request,
        EntityManagerInterface $em,
        AccountingAccountRepository $accountRepo,
    ): Response {
        $form = $this->createForm(AccountingAccountType::class, $account);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->ensureExclusiveOpeningBalanceAccount($account, $accountRepo);

            $em->flush();

            $this->addFlash('success', 'accounting.accounts.flash.updated');

            return $this->redirectToRoute('journal.settings.index');
        }

        return $this->renderAccountForm($form);
    }

    #[Route('/accounts/{id}/delete', name: 'journal.settings.accounts.delete', methods: ['DELETE'])]
    public function deleteAccount(
        AccountingAccount $account,
        EntityManagerInterface $em,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid('delete'.$account->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'flash.invalidtoken');

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        if ($account->isSystemDefault()) {
            $this->addFlash('warning', 'accounting.accounts.flash.cannot_delete_system');

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $em->remove($account);
        $em->flush();

        $this->addFlash('success', 'accounting.accounts.flash.deleted');

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    // ── Tax Rates CRUD ───────────────────────────────────────────────

    #[Route('/tax-rates/new', name: 'journal.settings.taxrates.new', methods: ['GET'])]
    public function newTaxRate(): Response
    {
        $form = $this->createForm(TaxRateType::class, new TaxRate(), [
            'action' => $this->generateUrl('journal.settings.taxrates.create'),
        ]);

        return $this->renderTaxRateForm($form);
    }

    #[Route('/tax-rates/create', name: 'journal.settings.taxrates.create', methods: ['POST'])]
    public function createTaxRate(
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        $taxRate = new TaxRate();
        $form = $this->createForm(TaxRateType::class, $taxRate);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($taxRate);
            $em->flush();

            $this->addFlash('success', 'accounting.taxrates.flash.created');

            return $this->redirectToRoute('journal.settings.index');
        }

        return $this->renderTaxRateForm($form);
    }

    #[Route('/tax-rates/{id}/edit', name: 'journal.settings.taxrates.edit', methods: ['GET'])]
    public function editTaxRate(TaxRate $taxRate): Response
    {
        $form = $this->createForm(TaxRateType::class, $taxRate, [
            'action' => $this->generateUrl('journal.settings.taxrates.update', ['id' => $taxRate->getId()]),
        ]);

        return $this->renderTaxRateForm($form);
    }

    #[Route('/tax-rates/{id}/update', name: 'journal.settings.taxrates.update', methods: ['POST'])]
    public function updateTaxRate(
        TaxRate $taxRate,
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        $form = $this->createForm(TaxRateType::class, $taxRate);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            $this->addFlash('success', 'accounting.taxrates.flash.updated');

            return $this->redirectToRoute('journal.settings.index');
        }

        return $this->renderTaxRateForm($form);
    }

    #[Route('/tax-rates/{id}/delete', name: 'journal.settings.taxrates.delete', methods: ['DELETE'])]
    public function deleteTaxRate(
        TaxRate $taxRate,
        EntityManagerInterface $em,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid('delete'.$taxRate->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'flash.invalidtoken');

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $em->remove($taxRate);
        $em->flush();

        $this->addFlash('success', 'accounting.taxrates.flash.deleted');

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    // ── Private helpers ──────────────────────────────────────────────

    private function renderAccountForm($form): Response
    {
        return $this->render('BookingJournal/_account_form.html.twig', ['form' => $form]);
    }

    private function renderTaxRateForm($form): Response
    {
        return $this->render('BookingJournal/_tax_rate_form.html.twig', ['form' => $form]);
    }

    private function ensureExclusiveOpeningBalanceAccount(
        AccountingAccount $selectedAccount,
        AccountingAccountRepository $accountRepo,
    ): void {
        if (!$selectedAccount->isOpeningBalanceAccount()) {
            return;
        }

        foreach ($accountRepo->findBy(['isOpeningBalanceAccount' => true]) as $account) {
            if ($account === $selectedAccount || $account->getId() === $selectedAccount->getId()) {
                continue;
            }

            $account->setIsOpeningBalanceAccount(false);
        }
    }
}
