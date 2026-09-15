<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BankImportRule;
use App\Form\BankImportRuleType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * CRUD over the user's saved bank-import rules.
 *
 * Rules are born in the preview ("Als Regel speichern"), because the context
 * of a real line is what makes the conditions and the action meaningful. This
 * controller exposes them for review, for enabling and disabling, for deletion
 * and for editing the values they carry — see {@see BankImportRuleType} for
 * what editing covers.
 */
#[Route('/journal/bank-import/rules')]
#[IsGranted('ROLE_CASHJOURNAL')]
class BankImportRuleController extends AbstractController
{
    #[Route('', name: 'bank_import.rules.index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToSettings();
    }

    private function redirectToSettings(): Response
    {
        return $this->redirect($this->generateUrl('bank_import.settings', ['tab' => 'tab-rules']));
    }

    #[Route('/{id}/edit', name: 'bank_import.rules.edit', methods: ['GET'])]
    public function edit(BankImportRule $rule): Response
    {
        $form = $this->createForm(BankImportRuleType::class, $rule, [
            'action' => $this->generateUrl('bank_import.rules.update', ['id' => $rule->getId()]),
        ]);

        return $this->render('BookingJournal/BankImport/rule_form.html.twig', [
            'form' => $form,
            'rule' => $rule,
        ]);
    }

    #[Route('/{id}/update', name: 'bank_import.rules.update', methods: ['POST'])]
    public function update(BankImportRule $rule, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(BankImportRuleType::class, $rule);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'accounting.bank_import.rules.flash.updated');

            return $this->redirectToSettings();
        }

        return $this->render('BookingJournal/BankImport/rule_form.html.twig', [
            'form' => $form,
            'rule' => $rule,
        ]);
    }

    #[Route('/{id}/toggle', name: 'bank_import.rules.toggle', methods: ['POST'])]
    public function toggle(BankImportRule $rule, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('toggle'.$rule->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'flash.invalidtoken');

            return $this->redirectToSettings();
        }

        $rule->setIsEnabled(!$rule->isEnabled());
        $em->flush();

        return $this->redirectToSettings();
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
}
