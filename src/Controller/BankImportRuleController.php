<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BankImportRule;
use App\Form\BankImportRuleType;
use App\Repository\AccountingAccountRepository;
use App\Repository\BankImportRuleRepository;
use App\Repository\TaxRateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * CRUD over the user's saved bank-import rules.
 *
 * Authoring rules from scratch happens in the preview ("Als Regel speichern"),
 * because the context (a real line) is what makes the conditions/actions
 * meaningful. This controller exposes the resulting rules for review,
 * priority/scope tweaks, enable/disable, and deletion.
 */
#[Route('/journal/bank-import/rules')]
#[IsGranted('ROLE_CASHJOURNAL')]
class BankImportRuleController extends AbstractController
{
    #[Route('', name: 'bank_import.rules.index', methods: ['GET'])]
    public function index(BankImportRuleRepository $ruleRepo, AccountingAccountRepository $accountRepo, TaxRateRepository $taxRateRepo): Response
    {
        $accountsById = [];
        foreach ($accountRepo->findAll() as $account) {
            $accountsById[(int) $account->getId()] = $account;
        }

        return $this->render('BookingJournal/BankImport/rules_index.html.twig', [
            'rules' => $ruleRepo->findAllOrdered(),
            'accountsById' => $accountsById,
            'taxRatesById' => $this->mapTaxRatesById($taxRateRepo),
        ]);
    }

    #[Route('/{id}/edit', name: 'bank_import.rules.edit', methods: ['GET'])]
    public function edit(BankImportRule $rule, AccountingAccountRepository $accountRepo, TaxRateRepository $taxRateRepo): Response
    {
        $form = $this->createForm(BankImportRuleType::class, $rule, [
            'action' => $this->generateUrl('bank_import.rules.update', ['id' => $rule->getId()]),
        ]);

        return $this->render('BookingJournal/BankImport/rule_form.html.twig', [
            'form' => $form,
            'rule' => $rule,
            'accountsById' => $this->mapAccountsById($accountRepo),
            'taxRatesById' => $this->mapTaxRatesById($taxRateRepo),
        ]);
    }

    #[Route('/{id}/update', name: 'bank_import.rules.update', methods: ['POST'])]
    public function update(BankImportRule $rule, Request $request, EntityManagerInterface $em, AccountingAccountRepository $accountRepo, TaxRateRepository $taxRateRepo): Response
    {
        $form = $this->createForm(BankImportRuleType::class, $rule);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'accounting.bank_import.rules.flash.updated');

            return $this->redirectToRoute('bank_import.rules.index');
        }

        return $this->render('BookingJournal/BankImport/rule_form.html.twig', [
            'form' => $form,
            'rule' => $rule,
            'accountsById' => $this->mapAccountsById($accountRepo),
            'taxRatesById' => $this->mapTaxRatesById($taxRateRepo),
        ]);
    }

    #[Route('/{id}/toggle', name: 'bank_import.rules.toggle', methods: ['POST'])]
    public function toggle(BankImportRule $rule, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('toggle'.$rule->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'flash.invalidtoken');

            return $this->redirectToRoute('bank_import.rules.index');
        }

        $rule->setIsEnabled(!$rule->isEnabled());
        $em->flush();

        return $this->redirectToRoute('bank_import.rules.index');
    }

    #[Route('/{id}/delete', name: 'bank_import.rules.delete', methods: ['DELETE'])]
    public function delete(BankImportRule $rule, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete'.$rule->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'flash.invalidtoken');

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $em->remove($rule);
        $em->flush();
        $this->addFlash('success', 'accounting.bank_import.rules.flash.deleted');

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array<int, \App\Entity\AccountingAccount>
     */
    private function mapAccountsById(AccountingAccountRepository $accountRepo): array
    {
        $accounts = [];
        foreach ($accountRepo->findAll() as $account) {
            $accounts[(int) $account->getId()] = $account;
        }

        return $accounts;
    }

    /**
     * @return array<int, \App\Entity\TaxRate>
     */
    private function mapTaxRatesById(TaxRateRepository $taxRateRepo): array
    {
        $taxRates = [];
        foreach ($taxRateRepo->findAll() as $taxRate) {
            $taxRates[(int) $taxRate->getId()] = $taxRate;
        }

        return $taxRates;
    }
}
