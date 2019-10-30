<?php

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <alex.pensionsverwaltung@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

use App\Service\CSRFProtectionService;
use App\Service\CashJournalService;
use App\Service\TemplatesService;
use App\Entity\CashJournal;
use App\Entity\CashJournalEntry;
use App\Entity\Template;

class CashJournalServiceController extends AbstractController
{
    private $perPage = 20;

    public function __construct()
    {

    }

    public function indexAction(SessionInterface $session, TemplatesService $ts, Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        
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
        
        $templates = $em->getRepository(Template::class)->loadByTypeName(array('TEMPLATE_CASHJOURNAL_PDF'));
        $defaultTemplate = $ts->getDefaultTemplate($templates);

        $templateId = 1;
        if($defaultTemplate != null) {
            $templateId = $defaultTemplate->getId();
        }

        $templateId = $session->get("cashjournal-template-id", $templateId); // get previously selected id
        //
        // initialy select the joungest year available
        if(count($journalYears) > 0) {
            $search = $journalYears[0]['cashYear'];
        } else {
            $search = '';
        }

        return $this->render(
            'CashJournal/index.html.twig',
            array(
                'journalYears' => $journalYears,
                'search' => $search,
                'templates' => $templates,
                'templateId' => $templateId,
            )
        );
    }
    
    /**
     * Gets the reservation overview as a table
     *
     * @param Request $request
     * @return mixed
     */
    public function getJournalTableAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        
        $page = $request->get('page', 1);
        $search = $request->get('search', date("Y"));

        $journals = $em->getRepository(CashJournal::class)->findByFilter($search, $page, $this->perPage);

        // calculate the number of pages for pagination
        $pages = ceil($journals->count() / $this->perPage);

        return $this->render('CashJournal/journal_journal_table.html.twig', array(
            'journals' => $journals,
            'page' => $page,
            'pages' => $pages,
            'search' => $search
        ));
    }

    public function newJournalAction(CSRFProtectionService $csrf, Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        
        $journal = new CashJournal();
        $youngestJ = $em->getRepository(CashJournal::class)->getYoungestJournal();
        
        $lastCashEnd = 0;
        if($youngestJ instanceof CashJournal) {
            // set last cash end as new cash start
            $journal->setCashStart($youngestJ->getCashEnd());
            // set new year and month based on the the last entry
            if($youngestJ->getCashMonth() == 12) {
                $journal->setCashYear($youngestJ->getCashYear() + 1);
                $journal->setCashMonth(1);
            } else {
                $journal->setCashYear($youngestJ->getCashYear());
                $journal->setCashMonth($youngestJ->getCashMonth() + 1);
            }
            
        }
        
        

        return $this->render('CashJournal/journal_journal_form_create.html.twig', array(
            'journal' => $journal,
            'token' => $csrf->getCSRFTokenForForm(),
        ));
    }

    public function createJournalAction(CSRFProtectionService $csrf, CashJournalService $cjs, Request $request)
    {
        $error = false;
        if (($csrf->validateCSRFToken($request))) {
            /* @var $journal \App\Entity\CashJournal */
            $journal = $cjs->getJournalFromForm($request);

            // check for mandatory fields
            if (strlen($journal->getCashYear()) == 0 || strlen($journal->getCashMonth()) == 0) {
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
            } else {
                $em = $this->getDoctrine()->getManager();
                $em->persist($journal);
                $em->flush();

                // add succes message
                $this->addFlash('success', 'journal.flash.create.success');
            }
        }

        return $this->render('feedback.html.twig', array(
            "error" => $error
        ));
    }
    
    public function getJournalAction(CSRFProtectionService $csrf, Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $journal = $em->getRepository(CashJournal::class)->find($id);

        return $this->render('CashJournal/journal_journal_form_edit.html.twig', array(
            'journal' => $journal,
            'token' => $csrf->getCSRFTokenForForm(),       
        ));
    }
    
    public function editJournalAction(CSRFProtectionService $csrf, CashJournalService $cjs, Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();
        $error = false;
        if (($csrf->validateCSRFToken($request))) {
            /* @var $entry \Pensionsverwaltung\Database\Entity\CashJournalEntry */
            $journal = $cjs->getJournalFromForm($request, $id);
            // check for mandatory fields
            if (strlen($journal->getCashYear()) == 0 || strlen($journal->getCashMonth()) == 0) {
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

        return $this->render('feedback.html.twig', array(
            "error" => $error
        ));
    }
    
    public function editJournalStatusAction(AuthorizationCheckerInterface $authChecker, CashJournalService $cjs, Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();
        
        $validStatus = Array('closed', 'booked');
        $status = $request->get('status');
        
        if(in_array($status, $validStatus)) {
            /* @var $journal \Pensionsverwaltung\Database\Entity\CashJournal */
             $journal = $em->getRepository(CashJournal::class)->find($id);
            
            if($status === 'closed') {
                if($journal->getIsClosed() && !$authChecker->isGranted('ROLE_ADMIN')) {
                    $this->addFlash('warning', 'flash.access.denied');
                    return new Response("ok");
                }
                
                $journal->setIsClosed(!$journal->getIsClosed());
                // update cash end of journal
                $cjs->recalculateCashEnd($journal);
            } else if($status === 'booked') {
                $journal->setIsBooked(!$journal->getIsBooked());
            }
            $em->persist($journal);
            $em->flush();
            $this->addFlash('success', 'journal.flash.edit.status.success');
        } else {
            $this->addFlash('warning', 'journal.flash.edit.status.warning');
        }

        return new Response("ok");
    }
    
    /**
     * Will be called when clicking on the delete button in the show/edit modal
     * @param Request $request
     * @return string
     */
    public function deleteJournalAction(CSRFProtectionService $csrf, AuthorizationCheckerInterface $authChecker, Request $request, $id)
    {
        if ($authChecker->isGranted('ROLE_ADMIN')) {
            $em = $this->getDoctrine()->getManager();
            if (($csrf->validateCSRFToken($request, true))) {
                $journal = $em->getRepository(CashJournal::class)->find($id);  
                
                // check if journal is closed
                if($journal->getIsClosed()) {
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
        return new Response("ok");
    }
    
    public function newJournalEntryAction(CSRFProtectionService $csrf, Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        
        $journal = $em->getRepository(CashJournal::class)->find($request->get('id'));
        $docNumber = $em->getRepository(CashJournalEntry::class)->getLastDocumentNumber($journal) + 1;

        $entry = new CashJournalEntry();
        $entry->setDate(new \DateTime());

        return $this->render('CashJournal/journal_entry_form_create.html.twig', array(
            'entry' => $entry,
            'token' => $csrf->getCSRFTokenForForm(),
            'id' => $journal->getId(),
            'newDocumentNumber' => $docNumber
        ));
    }

    public function createJournalEntryAction(CSRFProtectionService $csrf, CashJournalService $cjs, Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $error = false;
        if (($csrf->validateCSRFToken($request))) {
            /* @var $entry CashJournalEntry */
            $entry = $cjs->getJournalEntryFromForm($request);
            $journal = $em->getRepository(CashJournal::class)->find($request->get('id'));
            
            // check if journal is closed
            if($journal->getIsClosed()) {
                $error = true;
                $this->addFlash('warning', 'flash.access.denied');
            } else if (strlen($entry->getDocumentNumber()) == 0 || $entry->getDate() == null || !($journal instanceof CashJournal)) {
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

        return $this->render('feedback.html.twig', array(
            "error" => $error
        ));
    }
    
    public function indexEntryAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $page = $request->get('page', 1);
        $search = $request->get('search', '');

        $journal = $em->getRepository(CashJournal::class)->find($id);
        $entries = $em->getRepository(CashJournalEntry::class)->findByFilter($journal, $search, $page, $this->perPage);
     
        // calculate the number of pages for pagination
        $pages = ceil($entries->count() / $this->perPage);
        $startIndex = ($page - 1) * $this->perPage;

        return $this->render('CashJournal/journal_entry_index.html.twig', array(
            'journal' => $journal,
            'entries' => $entries,
            'page' => $page,
            'pages' => $pages,
            'search' => $search,
            'startIdx' => $startIndex
        ));
    }
    
    public function getEntryAction(CSRFProtectionService $csrf, Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entry = $em->getRepository(CashJournalEntry::class)->find($id);

        return $this->render('CashJournal/journal_entry_form_edit.html.twig', array(
            'entry' => $entry,
            'token' => $csrf->getCSRFTokenForForm(),         
        ));
    }
    
    public function editEntryAction(CSRFProtectionService $csrf, CashJournalService $cjs, Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();
        $error = false;
        if (($csrf->validateCSRFToken($request))) {
            /* @var $entry CashJournalEntry */
            $entry = $cjs->getJournalEntryFromForm($request, $id);
            
            // check if journal is closed
            if($entry->getCashJournal()->getIsClosed()) {
                $error = true;
                $this->addFlash('warning', 'flash.access.denied');
            } else if (strlen($entry->getDocumentNumber()) == 0 || $entry->getDate() == null) {
                // check for mandatory fields
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
            } else {
                $em->persist($entry);
                $em->flush();
                
                // update cash end of journal
                $cjs->recalculateCashEnd($entry->getCashJournal());

                // add succes message
                $this->addFlash('success', 'journal.entry.flash.edit.success');
            }
        }

        return $this->render('feedback.html.twig', array(
            "error" => $error
        ));
    }

    /**
     * Will be called when clicking on the delete button in the show/edit modal
     * @param Request $request
     * @return string
     */
    public function deleteEntryAction(CSRFProtectionService $csrf, AuthorizationCheckerInterface $authChecker, CashJournalService $cjs, Request $request, $id)
    {
        if ($authChecker->isGranted('ROLE_ADMIN')) {
            $em = $this->getDoctrine()->getManager();
            if (($csrf->validateCSRFToken($request, true))) {
                $entry = $em->getRepository(CashJournalEntry::class)->find($id);  
                
                // check if journal is closed
                if($entry->getCashJournal()->getIsClosed()) {
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
        return new Response("ok");
    }
    
    public function exportJournalToPdfAction(SessionInterface $session, $id, TemplatesService $ts, CashJournalService $cjs, $templateId)
    {
        $em = $this->getDoctrine()->getManager();
        // save id, after page reload template will be preselected in dropdown
        $session->set("cashjournal-template-id", $templateId);
        
        $templateOutput = $ts->renderTemplate($templateId, $id, $cjs);
        $template = $em->getRepository(Template::class)->find($templateId);
        $journal = $em->getRepository(CashJournal::class)->find($id);

        $pdfOutput = $ts->getPDFOutput($templateOutput, "Kassenblatt-".$journal->getCashYear()."-".$journal->getCashMonth(), $template);
        $response = new Response($pdfOutput);
        $response->headers->set('Content-Type', 'application/pdf');

        return $response;
    }
}
