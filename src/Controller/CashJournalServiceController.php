<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use App\Entity\CashJournal;
use App\Entity\CashJournalEntry;
use App\Entity\Template;
use App\Service\CashJournalService;
use App\Service\CSRFProtectionService;
use App\Service\TemplatesService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[Route('/cashjournal')]
class CashJournalServiceController extends AbstractController
{
    private $perPage = 20;

    #[Route('/', name: 'cashjournal.overview', methods: ['GET'])]
    public function indexAction(ManagerRegistry $doctrine, RequestStack $requestStack, TemplatesService $ts, Request $request)
    {
        $em = $doctrine->getManager();

        //        $journal = $em->getRepository(CashJournal::class)->find(6);
        //        for($i=0; $i<100;$i++) {
        //            $entry = new CashJournalEntry();
        //
        //            if($i % 2 == 0) {
        //                $entry->setIncomes(rand(1, 500));
        //            } else {
        //                $entry->setExpenses(rand(1, 500));
        //            }
        //            $entry->setCounterAccount('');
        //            $entry->setInvoiceNumber($i);
        //            $entry->setDocumentNumber($i);
        //            $entry->setDate(new \DateTime());
        //            $entry->setRemark('Tolle Bemerkung');
        //
        //            $entry->setCashJournal($journal);
        //            $em->persist($entry);
        //        }
        //        $em->flush();
        //
        //        $cjs->recalculateCashEnd($journal);

        $journalYears = $em->getRepository(CashJournal::class)->getJournalYears();

        $templates = $em->getRepository(Template::class)->loadByTypeName(['TEMPLATE_CASHJOURNAL_PDF']);
        $defaultTemplate = $ts->getDefaultTemplate($templates);

        $templateId = 1;
        if (null != $defaultTemplate) {
            $templateId = $defaultTemplate->getId();
        }

        $templateId = $requestStack->getSession()->get('cashjournal-template-id', $templateId); // get previously selected id
        //
        // initialy select the joungest year available
        if (count($journalYears) > 0) {
            $search = $journalYears[0]['cashYear'];
        } else {
            $search = '';
        }

        return $this->render(
            'CashJournal/index.html.twig',
            [
                'journalYears' => $journalYears,
                'search' => $search,
                'templates' => $templates,
                'templateId' => $templateId,
            ]
        );
    }

    /**
     * Gets the reservation overview as a table.
     */
    #[Route('/journal/list', name: 'cashjournal.table.get', methods: ['GET'])]
    public function getJournalTableAction(ManagerRegistry $doctrine, Request $request)
    {
        $em = $doctrine->getManager();

        $page = $request->query->get('page', 1);
        $search = $request->query->get('search', date('Y'));

        $journals = $em->getRepository(CashJournal::class)->findByFilter($search, $page, $this->perPage);

        // calculate the number of pages for pagination
        $pages = ceil($journals->count() / $this->perPage);

        return $this->render('CashJournal/journal_journal_table.html.twig', [
            'journals' => $journals,
            'page' => $page,
            'pages' => $pages,
            'search' => $search,
        ]);
    }

    #[Route('/journal/new', name: 'cashjournal.journal.new', methods: ['GET'])]
    public function newJournalAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, Request $request)
    {
        $em = $doctrine->getManager();

        $journal = new CashJournal();
        $youngestJ = $em->getRepository(CashJournal::class)->getYoungestJournal();

        $lastCashEnd = 0;
        if ($youngestJ instanceof CashJournal) {
            // set last cash end as new cash start
            $journal->setCashStart($youngestJ->getCashEnd());
            // set new year and month based on the the last entry
            if (12 == $youngestJ->getCashMonth()) {
                $journal->setCashYear($youngestJ->getCashYear() + 1);
                $journal->setCashMonth(1);
            } else {
                $journal->setCashYear($youngestJ->getCashYear());
                $journal->setCashMonth($youngestJ->getCashMonth() + 1);
            }
        }

        return $this->render('CashJournal/journal_journal_form_create.html.twig', [
            'journal' => $journal,
            'token' => $csrf->getCSRFTokenForForm(),
        ]);
    }

    #[Route('/journal/create', name: 'cashjournal.journal.create', methods: ['POST'])]
    public function createJournalAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, CashJournalService $cjs, Request $request)
    {
        $error = false;
        if ($csrf->validateCSRFToken($request)) {
            /* @var $journal \App\Entity\CashJournal */
            $journal = $cjs->getJournalFromForm($request);

            // check for mandatory fields
            if (0 == strlen($journal->getCashYear()) || 0 == strlen($journal->getCashMonth())) {
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
            } else {
                $em = $doctrine->getManager();
                $em->persist($journal);
                $em->flush();

                // add succes message
                $this->addFlash('success', 'journal.flash.create.success');
            }
        }

        return $this->render('feedback.html.twig', [
            'error' => $error,
        ]);
    }

    #[Route('/journal/{id}', name: 'cashjournal.journal', methods: ['GET'])]
    public function getJournalAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, Request $request, $id)
    {
        $em = $doctrine->getManager();

        $journal = $em->getRepository(CashJournal::class)->find($id);

        return $this->render('CashJournal/journal_journal_form_edit.html.twig', [
            'journal' => $journal,
            'token' => $csrf->getCSRFTokenForForm(),
        ]);
    }

    #[Route('/journal/{id}/edit', name: 'cashjournal.journal.edit', methods: ['POST'], defaults: ['id' => '0'])]
    public function editJournalAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, CashJournalService $cjs, Request $request, $id)
    {
        $em = $doctrine->getManager();
        $error = false;
        if ($csrf->validateCSRFToken($request)) {
            $journal = $cjs->getJournalFromForm($request, $id);
            // check for mandatory fields
            if (0 == strlen($journal->getCashYear()) || 0 == strlen($journal->getCashMonth())) {
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
            } else {
                // update cash end of journal
                $cjs->recalculateCashEnd($journal);

                $em->persist($journal);
                $em->flush();

                // add succes message
                $this->addFlash('success', 'journal.flash.edit.success');
            }
        }

        return $this->render('feedback.html.twig', [
            'error' => $error,
        ]);
    }

    #[Route('/journal/{id}/edit/status', name: 'cashjournal.journal.edit.status', methods: ['POST'], defaults: ['id' => '0'])]
    public function editJournalStatusAction(ManagerRegistry $doctrine, AuthorizationCheckerInterface $authChecker, CashJournalService $cjs, Request $request, $id)
    {
        $em = $doctrine->getManager();

        $validStatus = ['closed', 'booked'];
        $status = $request->request->get('status');

        if (in_array($status, $validStatus)) {
            $journal = $em->getRepository(CashJournal::class)->find($id);

            if ('closed' === $status) {
                if ($journal->getIsClosed() && !$authChecker->isGranted('ROLE_ADMIN')) {
                    $this->addFlash('warning', 'flash.access.denied');

                    return new Response('ok');
                }

                $journal->setIsClosed(!$journal->getIsClosed());
                // update cash end of journal
                $cjs->recalculateCashEnd($journal);
            } elseif ('booked' === $status) {
                $journal->setIsBooked(!$journal->getIsBooked());
            }
            $em->persist($journal);
            $em->flush();
            $this->addFlash('success', 'journal.flash.edit.status.success');
        } else {
            $this->addFlash('warning', 'journal.flash.edit.status.warning');
        }

        return new Response('ok');
    }

    /**
     * Will be called when clicking on the delete button in the show/edit modal.
     *
     * @return string
     */
    #[Route('/journal/{id}/delete', name: 'cashjournal.journal.delete', methods: ['POST'], defaults: ['id' => '0'])]
    public function deleteJournalAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, AuthorizationCheckerInterface $authChecker, Request $request, $id)
    {
        if ($authChecker->isGranted('ROLE_ADMIN')) {
            $em = $doctrine->getManager();
            if ($csrf->validateCSRFToken($request, true)) {
                $journal = $em->getRepository(CashJournal::class)->find($id);

                // check if journal is closed
                if ($journal->getIsClosed()) {
                    $this->addFlash('warning', 'flash.access.denied');
                } else {
                    $em->remove($journal);
                    $em->flush();

                    $this->addFlash('success', 'journal.entry.flash.delete.success');
                }
            } else {
                $this->addFlash('warning', 'flash.invalidtoken');
            }
        }

        return new Response('ok');
    }

    #[Route('/journal/entry/new', name: 'cashjournal.journal.entry.new', methods: ['GET'])]
    public function newJournalEntryAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, Request $request)
    {
        $em = $doctrine->getManager();

        $journal = $em->getRepository(CashJournal::class)->find($request->query->get('id'));
        $docNumber = $em->getRepository(CashJournalEntry::class)->getLastDocumentNumber($journal) + 1;

        $entry = new CashJournalEntry();
        $entry->setDate(new \DateTime());

        return $this->render('CashJournal/journal_entry_form_create.html.twig', [
            'entry' => $entry,
            'token' => $csrf->getCSRFTokenForForm(),
            'id' => $journal->getId(),
            'newDocumentNumber' => $docNumber,
        ]);
    }

    #[Route('/journal/entry/create', name: 'cashjournal.journal.entry.create', methods: ['POST'])]
    public function createJournalEntryAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, CashJournalService $cjs, Request $request)
    {
        $em = $doctrine->getManager();
        $error = false;
        if ($csrf->validateCSRFToken($request)) {
            /* @var $entry CashJournalEntry */
            $entry = $cjs->getJournalEntryFromForm($request);
            $journal = $em->getRepository(CashJournal::class)->find($request->request->get('id'));

            // check if journal is closed
            if ($journal->getIsClosed()) {
                $error = true;
                $this->addFlash('warning', 'flash.access.denied');
            } elseif (0 === $entry->getDocumentNumber() || null == $entry->getDate() || !($journal instanceof CashJournal)) {
                // check for mandatory fields
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
            } else {
                $entry->setCashJournal($journal);
                $em->persist($entry);
                $em->flush();

                // update cash end of journal
                $cjs->recalculateCashEnd($journal);

                // add succes message
                $this->addFlash('success', 'journal.entry.flash.create.success');
            }
        }

        return $this->render('feedback.html.twig', [
            'error' => $error,
        ]);
    }

    #[Route('/journal/overview/{id}', name: 'cashjournal.journal.entry.index', methods: ['GET'])]
    public function indexEntryAction(ManagerRegistry $doctrine, Request $request, $id)
    {
        $em = $doctrine->getManager();

        $page = $request->query->get('page', 1);
        $search = $request->query->get('search', '');

        $journal = $em->getRepository(CashJournal::class)->find($id);
        $entries = $em->getRepository(CashJournalEntry::class)->findByFilter($journal, $search, $page, $this->perPage);

        // calculate the number of pages for pagination
        $pages = ceil($entries->count() / $this->perPage);
        $startIndex = ($page - 1) * $this->perPage;

        return $this->render('CashJournal/journal_entry_index.html.twig', [
            'journal' => $journal,
            'entries' => $entries,
            'page' => $page,
            'pages' => $pages,
            'search' => $search,
            'startIdx' => $startIndex,
        ]);
    }

    #[Route('/journal/entry/{id}', name: 'cashjournal.journal.entry', methods: ['GET'])]
    public function getEntryAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, Request $request, $id)
    {
        $em = $doctrine->getManager();

        $entry = $em->getRepository(CashJournalEntry::class)->find($id);

        return $this->render('CashJournal/journal_entry_form_edit.html.twig', [
            'entry' => $entry,
            'token' => $csrf->getCSRFTokenForForm(),
        ]);
    }

    #[Route('/journal/entry/{id}/edit', name: 'cashjournal.journal.entry.edit', methods: ['POST'], defaults: ['id' => '0'])]
    public function editEntryAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, CashJournalService $cjs, Request $request, int $id)
    {
        $em = $doctrine->getManager();
        $error = false;
        if ($csrf->validateCSRFToken($request)) {
            /* @var $entry CashJournalEntry */
            $entry = $cjs->getJournalEntryFromForm($request, $id);

            // check if journal is closed
            if ($entry->getCashJournal()->getIsClosed()) {
                $error = true;
                $this->addFlash('warning', 'flash.access.denied');
            } elseif (0 === $entry->getDocumentNumber() || null == $entry->getDate()) {
                // check for mandatory fields
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
            } else {
                $em->persist($entry);
                $em->flush();

                // update cash end of journal
                $cjs->recalculateCashEnd($entry->getCashJournal());

                // add success message
                $this->addFlash('success', 'journal.entry.flash.edit.success');
            }
        }

        return $this->render('feedback.html.twig', [
            'error' => $error,
        ]);
    }

    /**
     * Will be called when clicking on the delete button in the show/edit modal.
     *
     * @return string
     */
    #[Route('/journal/entry/{id}/delete', name: 'cashjournal.journal.entry.delete', methods: ['POST'], defaults: ['id' => '0'])]
    public function deleteEntryAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, AuthorizationCheckerInterface $authChecker, CashJournalService $cjs, Request $request, $id)
    {
        if ($authChecker->isGranted('ROLE_ADMIN')) {
            $em = $doctrine->getManager();
            if ($csrf->validateCSRFToken($request, true)) {
                $entry = $em->getRepository(CashJournalEntry::class)->find($id);

                // check if journal is closed
                if ($entry->getCashJournal()->getIsClosed()) {
                    $this->addFlash('warning', 'flash.access.denied');
                } else {
                    $em->remove($entry);
                    $journal = $entry->getCashJournal();
                    $em->flush();

                    // update cash end of journal
                    $cjs->recalculateCashEnd($journal);

                    $this->addFlash('success', 'journal.entry.flash.delete.success');
                }
            } else {
                $this->addFlash('warning', 'flash.invalidtoken');
            }
        }

        return new Response('ok');
    }

    #[Route('/journal/{id}/export/pdf/{templateId}', name: 'cashjournal.journal.export.pdf', methods: ['GET'])]
    public function exportJournalToPdfAction(ManagerRegistry $doctrine, RequestStack $requestStack, int $id, TemplatesService $ts, CashJournalService $cjs, int $templateId)
    {
        $em = $doctrine->getManager();
        // save id, after page reload template will be preselected in dropdown
        $requestStack->getSession()->set('cashjournal-template-id', $templateId);

        $templateOutput = $ts->renderTemplate($templateId, $id, $cjs);
        $template = $em->getRepository(Template::class)->find($templateId);
        $journal = $em->getRepository(CashJournal::class)->find($id);

        $pdfOutput = $ts->getPDFOutput($templateOutput, 'Kassenblatt-'.$journal->getCashYear().'-'.$journal->getCashMonth(), $template);
        $response = new Response($pdfOutput);
        $response->headers->set('Content-Type', 'application/pdf');

        return $response;
    }
}
