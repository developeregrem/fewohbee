<?php

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <alex.pensionsverwaltung>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

use App\Service\CSRFProtectionService;
use App\Service\TemplatesService;
use App\Entity\Template;
use App\Entity\TemplateType;
use App\Entity\Reservation;
use App\Entity\Customer;
use App\Entity\Correspondence;
use App\Entity\FileCorrespondence;
use App\Entity\MailCorrespondence;

class TemplatesServiceController extends AbstractController
{
    private $fromMail;
    private $fromName;
    private $mailHost;
    private $returnPath;
    public function __construct(string $fromMail, string $fromName, string $mailHost, string $returnPath)
    {
        $this->fromMail = $fromMail;
        $this->fromName = $fromName;
        $this->mailHost = $mailHost;
        $this->returnPath = $returnPath;
    }

    /**
     * Index-View
     * @return mixed
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();
        $templates = $em->getRepository(Template::class)->findAll();

        return $this->render('Templates/index.html.twig', array(
            "templates" => $templates
        ));
    }

    /**
     * Show single entity
     * @param $id
     * @return mixed
     */
    public function getAction(CSRFProtectionService $csrf, $id)
    {
        $em = $this->getDoctrine()->getManager();
        $template = $em->getRepository(Template::class)->find($id);
        
        $types = $em->getRepository(TemplateType::class)->findAll();

        return $this->render('Templates/templates_form_edit.html.twig', array(
            'template' => $template,
            'token' => $csrf->getCSRFTokenForForm(),
            'types' => $types
        ));
    }

    /**
     * Show form for new entity
     * @return mixed
     */
    public function newAction(CSRFProtectionService $csrf)
    {
        $em = $this->getDoctrine()->getManager();

        $template = new Template();
        $template->setId("new");
        
        $types = $em->getRepository(TemplateType::class)->findAll();

        return $this->render('Templates/templates_form_create.html.twig', array(
            'template' => $template,
            'token' => $csrf->getCSRFTokenForForm(),
            'types' => $types
        ));
    }

    /**
     * Create new entity
     * @param Request $request
     * @return mixed
     */
    public function createAction(CSRFProtectionService $csrf, TemplatesService $ts, Request $request)
    {
        $error = false;
        if (($csrf->validateCSRFToken($request))) {
            $template = $ts->getEntityFromForm($request, "new");

            // check for mandatory fields
            if (strlen($template->getName()) == 0) {
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
            } else {
                $em = $this->getDoctrine()->getManager();
                $em->persist($template);
                $em->flush();

                // add succes message
                $this->addFlash('success', 'templates.flash.create.success');
            }
        }

        return $this->render('feedback.html.twig', array(
            "error" => $error
        ));
    }

    /**
     * update entity end show update result
     * @param Request $request
     * @param $id
     * @return mixed
     */
    public function editAction(CSRFProtectionService $csrf, TemplatesService $ts, Request $request, $id)
    {
        $error = false;
        if (($csrf->validateCSRFToken($request))) {
            $template = $ts->getEntityFromForm($request, $id);
            $em = $this->getDoctrine()->getManager();

            // check for mandatory fields
            if (strlen($template->getName()) == 0) {
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
                // stop auto commit of doctrine with invalid field values
               $em->clear(Template::class);
            } else {                
                $em->persist($template);
                $em->flush();

                // add succes message           
                $this->addFlash('success', 'templates.flash.edit.success');
            }
        }

        return $this->render('feedback.html.twig', array(
            "error" => $error
        ));
    }

    /**
     * delete entity
     * @param Request $request
     * @param $id
     * @return string
     */
    public function deleteAction(CSRFProtectionService $csrf, TemplatesService $ts, Request $request, $id)
    {
        if ($request->getMethod() == 'POST') {
            if (($csrf->validateCSRFToken($request, true))) {
                $template = $ts->deleteEntity($id);
                if($template) {
                    $this->addFlash('success', 'templates.flash.delete.success');
                }
            }
            return new Response('', Response::HTTP_NO_CONTENT);
        } else {
            // initial get load (ask for deleting)           
            return $this->render('common/form_delete_entry.html.twig', array(
                "id" => $id,
                'token' => $csrf->getCSRFTokenForForm()
            ));
        }
    }
    
    /**
     * Preview single entity
     * @param $id
     * @return mixed
     */
    public function previewAction(TemplatesService $ts, $id)
    {
        $em = $this->getDoctrine()->getManager();
        $reservation = $em->getRepository(Reservation::class)->find(172);
        
        $template = $ts->renderTemplateForReservations($id, Array($reservation));

        return $this->render('Templates/templates_preview.html.twig', array(
            'template' => $template
        ));
    }
    
    public function selectReservationAction(SessionInterface $session, Request $request)
    {
        $em = $this->getDoctrine()->getManager();

        if ($request->get('createNew') == "true") {
            $selectedReservationIds = array();
            $session->set("selectedReservationIds", $selectedReservationIds);
            // reset session variables
            //$session->remove("invoicePositionsMiscellaneous");
            
        } else {
            $selectedReservationIds = $session->get("selectedReservationIds");
        }

        if ($request->get("reservationid") != null) {
            $selectedReservationIds[] = $request->get("reservationid");
            $session->set("selectedReservationIds", $selectedReservationIds);
        }

        $reservations = Array();
        foreach ($selectedReservationIds as $reservationId) {
            $reservations[] = $em->getRepository(Reservation::class)->find($reservationId);
        }
        
        return $this->render(
            'Templates/templates_form_show_selected_reservations.html.twig',
            array(
                'reservations' => $reservations
            )
        );
    }
    
    public function getReservationsAction(CSRFProtectionService $csrf, SessionInterface $session, Request $request)
    {
        $em = $this->getDoctrine()->getManager();

        if ($request->get('createNew') == "true") {
            $selectedReservationIds = array();
            $session->set("selectedReservationIds", $selectedReservationIds);
            // reset session variables
            // $session->remove("invoicePositionsMiscellaneous");
        } else {
            $selectedReservationIds = $session->get("selectedReservationIds");
        }

        if (count($selectedReservationIds) == 0) {
            $objectContainsReservations = "false";
        } else {
            $objectContainsReservations = "true";
        }

        return $this->render(
            'Templates/templates_form_select_reservation.html.twig',
            array(
                'objectContainsReservations' => $objectContainsReservations
            )
        );
    }

    public function removeReservationFromSelectionAction(SessionInterface $session, Request $request)
    {
        $em = $this->getDoctrine()->getManager();

        $selectedReservationIds = $session->get("selectedReservationIds");

        if ($request->get("reservationkey") != null) {
            unset($selectedReservationIds[$request->get("reservationkey")]);
            $session->set("selectedReservationIds", $selectedReservationIds);
        }
        
        return $this->selectReservationAction($session, $request);
    }
    
    public function getReservationsInPeriodAction(SessionInterface $session, Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $reservations = Array();
        $selectedReservationIds = $session->get("selectedReservationIds");
        $potentialReservations = $em->getRepository(
                Reservation::class
            )->loadReservationsForPeriod($request->get('from'), $request->get('end'));

        foreach ($potentialReservations as $reservation) {
            // make sure that already selected reservation can not be choosen twice
            if (!in_array($reservation->getId(), $selectedReservationIds)) {                
                $reservations[] = $reservation;
            }
        }

        return $this->render(
            'Reservations/reservation_matching_reservations.html.twig',
            array(
                'reservations' => $reservations
            )
        );
    }

    public function getReservationsForCustomerAction(SessionInterface $session, Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $reservations = Array();
        $selectedReservationIds = $session->get("selectedReservationIds");

        $customer = $em->getRepository(Customer::class)->findOneByLastname(
            $request->get("lastname")
        );

        if ($customer instanceof Customer) {
            $potentialReservations = $em->getRepository(
                Reservation::class
            )->loadReservationsWithoutInvoiceForCustomer($customer);

            foreach ($potentialReservations as $reservation) {
                if (!in_array($reservation->getId(), $selectedReservationIds)) {                
                    $reservations[] = $reservation;
                }
            }
        }

        return $this->render(
            'Reservations/reservation_matching_reservations.html.twig',
            array(
                'reservations' => $reservations
            )
        );
    }
    
    public function sendEmailAction(CSRFProtectionService $csrf, TemplatesService $ts, SessionInterface $session, \Swift_Mailer $mailer, Request $request)
    {
        $em = $this->getDoctrine()->getManager();

        $error = false;
        if (($csrf->validateCSRFToken($request))) {            
            $to = $request->get("to");
            $subject = $request->get("subject");
            $msg = $request->get("msg");
            $templateId = $request->get("templateId");
            $attachmentIds = $session->get("templateAttachmentIds", Array()); 

            // todo add email validation http://silex.sensiolabs.org/doc/providers/validator.html
            if (strlen($to) == 0 || strlen($subject) == 0 || strlen($msg) == 0) {
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
            } else {
                $message = (new \Swift_Message())
                    ->setSubject($subject)
                    ->setFrom(array($this->fromMail => $this->fromName))
                    ->setTo(array($to))
                    ->setBcc(array($this->fromMail => $this->fromName))
                    ->setBody($msg, 'text/html')
                    ->addPart(strip_tags($msg), 'text/plain')
                    ->setId(uniqid()."@".$this->mailHost)
                    //->setEncoder((Swift_Encoding::get8BitEncoding())
                    ->setReturnPath($this->returnPath);
                $headers = $message->getHeaders();
                $headers->addTextHeader('X-Mailer', 'PHP/'.  phpversion());
                //$headers->addTextHeader('X-MSMail-Priority', 'High');
                
                // add attachments
                foreach($attachmentIds as $attId) {
                    $id = reset($attId); // first element of array 
                    $ts->attachToMail($id, $message);                        
                }
                //$logger = new \Swift_Plugins_Loggers_EchoLogger();
                //$this->app['mailer']->registerPlugin(new \Swift_Plugins_LoggerPlugin($logger));
                //$failures;
                $res = $mailer->send($message);
                //var_dump($failures);
                //var_dump($res);

                // now save correspondence to db
                $template = $em->getReference(Template::class, $templateId);
                $mail = new MailCorrespondence();
                $mail->setRecipient($to)
                     ->setName($subject)
                     ->setSubject($subject)
                     ->setText($msg)
                     ->setTemplate($template);
                
                // associate with reservations
                $reservations = $ts->getReferencedReservationsInSession();  
                
                // save correspondence for each reservation
                foreach($reservations as $reservation) {                    
                    $mail->setReservation($reservation);                   
                    
                    // add connection to attachments
                    foreach($attachmentIds as $attId) {
                        $child = $em->getReference(Correspondence::class, $attId[$reservation->getId()]);
                        $mail->addChild($child);                        
                    }
                    $em->persist($mail);
                    $em->flush();
                    $em->clear(Correspondence::class);                     // to make sure we always insert new entry and no update
                }                
                
                $this->addFlash('success', 'templates.sendemail.success');
            }
        } else {
            $this->addFlash('warning', 'flash.invalidtoken');
            $error = true;
        }

        return $this->render('feedback.html.twig', array(
            'error' => $error,
        ));
    }
    
    public function saveFileAction(CSRFProtectionService $csrf, TemplatesService $ts, SessionInterface $session, Request $request)
    {
        $em = $this->getDoctrine()->getManager();

        $error = false;
        if (($csrf->validateCSRFToken($request))) {            
            $subject = $request->get("subject");
            $msg = $request->get("msg");
            $templateId = $request->get("templateId");

            if (strlen($subject) == 0 || strlen($msg) == 0) {
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
            } else {
                // todo
                // if this file is an attachment for email, 
                $attachmentForId = $session->get("selectedTemplateId", null);
                
                // now save correspondence to db
                $template = $em->getReference(Template::class, $templateId);
                $file = new FileCorrespondence();
                $file->setFileName($subject)
                     ->setName($subject)
                     ->setText($msg)
                     ->setTemplate($template);
                
                
                // associate with reservations
                $reservations = $ts->getReferencedReservationsInSession();  
                $fileIds = Array();
                
                // save correspondence for each reservation
                foreach($reservations as $reservation) {
                    $file->setReservation($reservation);                    
                    $em->persist($file);
                    $em->flush();
                    $em->clear(Correspondence::class);                     // to make sure we always insert new entry and no update
                }
                
                $isAttachment = false;
                if($attachmentForId != null) {
                    $ts->addFileAsAttachment($file->getId(), $reservations);  
                    $isAttachment = true;
                    $error = true;  // just to enable flash message in modal
                }

                $this->addFlash('success', 'templates.savefile.success');
            }
        } else {
            $this->addFlash('warning', 'flash.invalidtoken');
            $error = true;
        }

        return $this->render('feedback.html.twig', array(
            'error' => $error,
            'attachment' => $isAttachment
        ));
    }
    
    /**
     * Softly delete attachment, doesn't delete file from db
     * @param Request $request
     * @return type
     */
    public function deleteAttachmentAction(CSRFProtectionService $csrf, SessionInterface $session, Request $request)
    {
        $em = $this->getDoctrine()->getManager();

        $error = false;
        if (($csrf->validateCSRFToken($request))) {            
            $aId = $request->get("id");
            $attachments = $session->get("templateAttachmentIds");
            $isAttachment = false;
            // loop through all reservations
            foreach($attachments as $key=>$attachment) {
                // search for attachment id and delte entry if it exists
                $rId = array_search($aId, $attachment);
                if (false !== $key) {
                    unset($attachments[$key][$rId]);
                    $isAttachment = true;
                }
                // just remove empty arrays
                if(count($attachments[$key]) == 0) {
                    unset($attachments[$key]);
                }
            }
            
            if($isAttachment) {
                $session->set("templateAttachmentIds", $attachments);
                //$correspondence = $em->getReference(Correspondence::class, $aId);
            } else {
                $this->addFlash('warning', 'templates.attachment.notfound');
                $error = true;
            }
            
        } else {
            $this->addFlash('warning', 'flash.invalidtoken');
            $error = true;
        }

        return $this->render('feedback.html.twig', array(
            'error' => $error
        ));
    }
    
    public function deleteCorrespondenceAction(CSRFProtectionService $csrf, Request $request)
    {
        $em = $this->getDoctrine()->getManager();

        $error = false;
        if (($csrf->validateCSRFToken($request, true))) {            
            $cId = $request->get("id");
            $correspondence = $em->getRepository(Correspondence::class)->find($cId);
            
            if($correspondence instanceof Correspondence) {
                $em->remove($correspondence);
                $em->flush();
                $this->addFlash('success', 'templates.correspondence.delete.ok');
            } else {
                $this->addFlash('warning', 'templates.correspondence.notfound');
                $error = true;
            }
            
        } else {
            $this->addFlash('warning', 'flash.invalidtoken');
            $error = true;
        }

        return $this->render('feedback.html.twig', array(
            'error' => $error
        ));
    }
    
    /**
     * Adds an already added file (correspondence) as attachment of the current mail
     * @param Request $request
     * @return type
     */
    public function addAttachmentAction(TemplatesService $ts, Request $request)
    {
        $error = false;           
        $cId = $request->get("id");
        $reservations = $ts->getReferencedReservationsInSession(); 
        $ts->addFileAsAttachment($cId, $reservations);


        return $this->render('feedback.html.twig', array(
            'error' => $error
        ));
    }
    
    public function exportPDFCorrespondenceAction(TemplatesService $ts, Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();
        $correspondence = $em->getRepository(Correspondence::class)->find($id);
        if($correspondence instanceof FileCorrespondence) {
            
            $output = $ts->getPDFOutput($correspondence->getText(), 
                                                                   $correspondence->getName(), 
                                                                   $correspondence->getTemplate());
            $response = new Response($output);
            $response->headers->set('Content-Type', 'application/pdf');

            return $response;
        }
        return new Response("no file");
    }
    
    public function showMailCorrespondenceAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();
        $correspondence = $em->getRepository(Correspondence::class)->find($id);
        if($correspondence instanceof MailCorrespondence) {
            
            return $this->render(
                'Templates/templates_show_mail.html.twig',
                array(
                    'correspondence' => $correspondence,
                    'reservationId' => $request->get("reservationId")
                )
             );
        }
        return new Response("no mail");
    }
    
    public function getTemplatesForEditor($templateTypeId) {
        $em = $this->getDoctrine()->getManager();
        /* @var $type TemplateType */
        $type = $em->getRepository(TemplateType::class)->find($templateTypeId);
        if($type instanceof TemplateType && !empty($type->getEditorTemplate())) {
            $response = $this->render('Templates/' . $type->getEditorTemplate());
            $response->headers->set('Content-Type', 'application/json');
        } else {
            $response = $this->json([]);
        }

        return $response;
    }
}