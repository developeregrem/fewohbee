<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <alex.pensionsverwaltung>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use App\Entity\Correspondence;
use App\Entity\FileCorrespondence;
use App\Entity\Invoice;
use App\Entity\InvoiceSettingsData;
use App\Entity\MailCorrespondence;
use App\Entity\Reservation;
use App\Entity\Template;
use App\Service\CSRFProtectionService;
use App\Service\EInvoice\EInvoiceExportService;
use App\Service\InvoiceService;
use App\Service\MailService;
use App\Service\ReservationService;
use App\Service\TemplatesService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\ExpressionLanguage\Expression;

#[Route(path: '/correspondence')]
class CorrespondenceController extends AbstractController
{
    /**
     * Called when clicking add conversation in the reservation overview.
     */
    #[IsGranted('ROLE_RESERVATIONS')]
    #[Route('/select/reservation', name: 'correspondence.select.reservation', methods: ['POST'])]
    public function selectReservationAction(ReservationService $reservationService, Request $request): Response
    {
        if ('true' == $request->request->get('createNew')) {
            $reservationService->resetSelectedReservations();
        }

        if (null != $request->request->get('reservationid')) {
            $reservationService->addReservationToSelection((int) $request->request->get('reservationid'));
        }

        $reservations = $reservationService->getSelectedReservations();

        return $this->render(
            'Reservations/Correspondence/selected_reservations.html.twig',
            [
                'reservations' => $reservations,
            ]
        );
    }

    #[IsGranted('ROLE_RESERVATIONS')]
    #[Route('/get/reservations', name: 'correspondence.get.reservations', methods: ['GET'])]
    public function getReservationsAction(ReservationService $reservationService, Request $request)
    {
        if ('true' == $request->query->get('createNew')) {
            $reservationService->resetSelectedReservations();
        }

        if (!$reservationService->hasSelectedReservations()) {
            $objectContainsReservations = 'false';
        } else {
            $objectContainsReservations = 'true';
        }

        return $this->render(
            'Reservations/Correspondence/select_reservation.html.twig',
            [
                'objectContainsReservations' => $objectContainsReservations,
            ]
        );
    }

    #[IsGranted('ROLE_RESERVATIONS')]
    #[Route('/remove/reservation/from/selection', name: 'correspondence.remove.reservation.from.selection', methods: ['POST'])]
    public function removeReservationFromSelectionAction(ReservationService $reservationService, Request $request)
    {
        if (null != $request->request->get('reservationkey')) {
            $reservationService->removeReservationFromSelection((int) $request->request->get('reservationkey'));
        }

        return $this->render(
            'Reservations/Correspondence/selected_reservations.html.twig',
            [
                'reservations' => $reservationService->getSelectedReservations(),
            ]
        );
    }

    #[IsGranted('ROLE_RESERVATIONS')]
    #[Route('/email/send', name: 'correspondence.email.send', methods: ['POST'])]
    public function sendEmailAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, TemplatesService $ts, RequestStack $requestStack, MailService $mailer, Request $request)
    {
        $em = $doctrine->getManager();

        $error = false;
        if ($csrf->validateCSRFToken($request)) {
            $to = $request->request->get('to');
            $subject = $request->request->get('subject');
            $msg = $request->request->get('msg');
            $templateId = $request->request->get('templateId');
            $attachmentIds = $requestStack->getSession()->get('templateAttachmentIds', []);

            // todo add email validation http://silex.sensiolabs.org/doc/providers/validator.html
            if (0 == strlen($to) || 0 == strlen($subject) || 0 == strlen($msg)) {
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
            } else {
                $attachments = [];
                // add attachments
                foreach ($attachmentIds as $attId) {
                    $id = reset($attId); // first element of array
                    $mailAttachment = $ts->getMailAttachment($id);
                    if (null !== $mailAttachment) {
                        $attachments[] = $mailAttachment;
                    }
                }

                $mailer->sendHTMLMail($to, $subject, $msg, $attachments);

                // now save correspondence to db
                $template = $em->getReference(Template::class, $templateId);

                // associate with reservations
                $reservations = $ts->getReferencedReservationsInSession();

                // save correspondence for each reservation
                foreach ($reservations as $reservation) {
                    $mail = new MailCorrespondence();
                    $mail->setRecipient($to)
                         ->setName($subject)
                         ->setSubject($subject)
                         ->setText($msg)
                         ->setTemplate($template)
                        ->setReservation($reservation);

                    // add connection to attachments
                    foreach ($attachmentIds as $attId) {
                        $child = $em->getReference(Correspondence::class, $attId[$reservation->getId()]);
                        $mail->addChild($child);
                    }
                    $em->persist($mail);
                    $em->flush();
                }

                $this->addFlash('success', 'templates.sendemail.success');
            }
        } else {
            $this->addFlash('warning', 'flash.invalidtoken');
            $error = true;
        }

        return $this->render('feedback.html.twig', [
            'error' => $error,
        ]);
    }

    #[IsGranted('ROLE_RESERVATIONS')]
    #[Route('/file/save', name: 'correspondence.file.save', methods: ['POST'])]
    public function saveFileAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, TemplatesService $ts, RequestStack $requestStack, Request $request)
    {
        $em = $doctrine->getManager();

        $error = false;
        $isAttachment = false;
        if ($csrf->validateCSRFToken($request)) {
            $subject = $request->request->get('subject');
            $msg = $request->request->get('msg');
            $templateId = $request->request->get('templateId');

            if (0 == strlen($subject) || 0 == strlen($msg)) {
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
            } else {
                // todo
                // if this file is an attachment for email,
                $attachmentForId = $requestStack->getSession()->get('selectedTemplateId', null);

                // now save correspondence to db
                $template = $em->getReference(Template::class, $templateId);

                // associate with reservations
                $reservations = $ts->getReferencedReservationsInSession();

                // save correspondence for each reservation
                foreach ($reservations as $reservation) {
                    $file = new FileCorrespondence();
                    $file->setFileName($subject)
                         ->setName($subject)
                         ->setText($msg)
                         ->setTemplate($template)
                         ->setReservation($reservation);
                    $em->persist($file);
                    $em->flush();
                }

                if (null != $attachmentForId) {
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

        return $this->render('feedback.html.twig', [
            'error' => $error,
            'attachment' => $isAttachment,
        ]);
    }

    /**
     * Softly delete attachment, doesn't delete file from db.
     */
    #[IsGranted('ROLE_RESERVATIONS')]
    #[Route('/attachment/remove', name: 'correspondence.attachment.remove', methods: ['POST'])]
    public function deleteAttachmentAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, RequestStack $requestStack, Request $request): Response
    {
        $em = $doctrine->getManager();

        $error = false;
        if ($csrf->validateCSRFToken($request)) {
            $aId = $request->request->get('id');
            $attachments = $requestStack->getSession()->get('templateAttachmentIds');
            $isAttachment = false;
            // loop through all reservations
            foreach ($attachments as $key => $attachment) {
                // search for attachment id and delte entry if it exists
                $rId = array_search($aId, $attachment);
                if (false !== $key) {
                    unset($attachments[$key][$rId]);
                    $isAttachment = true;
                }
                // just remove empty arrays
                if (0 == count($attachments[$key])) {
                    unset($attachments[$key]);
                }
            }

            if ($isAttachment) {
                $requestStack->getSession()->set('templateAttachmentIds', $attachments);
            } else {
                $this->addFlash('warning', 'templates.attachment.notfound');
                $error = true;
            }
        } else {
            $this->addFlash('warning', 'flash.invalidtoken');
            $error = true;
        }

        return $this->render('feedback.html.twig', [
            'error' => $error,
        ]);
    }

    #[IsGranted('ROLE_RESERVATIONS')]
    #[Route('/remove', name: 'correspondence.remove', methods: ['POST'])]
    public function deleteCorrespondenceAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, Request $request)
    {
        $em = $doctrine->getManager();

        $error = false;
        if ($csrf->validateCSRFToken($request, true)) {
            $cId = $request->request->get('id');
            $correspondence = $em->getRepository(Correspondence::class)->find($cId);

            if ($correspondence instanceof Correspondence) {
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

        return $this->render('feedback.html.twig', [
            'error' => $error,
        ]);
    }

    /**
     * Adds an already added file (correspondence) as attachment of the current mail.
     */
    #[IsGranted('ROLE_RESERVATIONS')]
    #[Route('/attachment/add', name: 'correspondence.attachment.add', methods: ['POST'])]
    public function addAttachmentAction(ManagerRegistry $doctrine, TemplatesService $ts, Request $request, InvoiceService $is, EInvoiceExportService $einvoice): Response
    {
        $em = $doctrine->getManager();
        $error = false;
        $isInvoice = $request->request->get('isInvoice', 'false');
        $isEInvoice = $request->request->get('isEInvoice', 'false');
        $cId = $request->request->get('id');
        if ('false' != $isInvoice) {
            $binaryPayload = null;
            if ('false' != $isEInvoice) {
                $invoice = $cId ? $em->getRepository(Invoice::class)->find($cId) : null;
                if (!($invoice instanceof Invoice)) {
                    $this->addFlash('warning', 'templates.attachment.notfound');
                    $error = true;
                } else {
                    $invoiceSettings = $em->getRepository(InvoiceSettingsData::class)->findOneBy(['isActive' => true]);
                    if (!($invoiceSettings instanceof InvoiceSettingsData)) {
                        $this->addFlash('danger', 'invoice.settings.active.error');
                        $error = true;
                    } else {
                        $templates = $em->getRepository(Template::class)->loadByTypeName(['TEMPLATE_INVOICE_PDF']);
                        $defaultTemplate = $ts->getDefaultTemplate($templates);
                        if (null === $defaultTemplate) {
                            $this->addFlash('warning', 'templates.notfound');
                            $error = true;
                        } else {
                            try {
                                $binaryPayload = $is->generateInvoicePdfXml($ts, $einvoice, $invoice, $defaultTemplate, $invoiceSettings);
                            } catch (\InvalidArgumentException $e) {
                                $this->addFlash('warning', $e->getMessage());
                                $error = true;
                            } catch (\Throwable $e) {
                                $this->addFlash('warning', $e->getMessage());
                                $error = true;
                            }
                        }
                    }
                }
            }
            if (!$error) {
                $cId = $ts->makeCorespondenceOfInvoice($cId, $is, $binaryPayload, 'false' != $isEInvoice);
            }
        }

        if (!$error) {
            $reservations = $ts->getReferencedReservationsInSession();
            $ts->addFileAsAttachment($cId, $reservations);
        }

        return $this->render('feedback.html.twig', [
            'error' => $error,
        ]);
    }

    #[IsGranted('ROLE_RESERVATIONS')]
    #[Route('/export/pdf/{id}/', name: 'correspondence.export.pdf', methods: ['GET'], defaults: ['id' => '0'])]
    public function exportPDFCorrespondenceAction(ManagerRegistry $doctrine, TemplatesService $ts, InvoiceService $is, $id)
    {
        $em = $doctrine->getManager();
        $correspondence = $em->getRepository(Correspondence::class)->find($id);
        if ($correspondence instanceof FileCorrespondence) {
            $safeName = $is->sanitizeFilename($correspondence->getName());
            $binaryPayload = $correspondence->getBinaryPayload();
            $output = $binaryPayload ?: $ts->getPDFOutput(
                $correspondence->getText(),
                $safeName,
                $correspondence->getTemplate()
            );
            $response = new Response($output);
            $response->headers->set('Content-Type', 'application/pdf');
            $disposition = HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $safeName.'.pdf'
            );
            $response->headers->set('Content-Disposition', $disposition);

            return $response;
        }

        return new Response('no file');
    }

    #[IsGranted('ROLE_RESERVATIONS')]
    #[Route('/show/{id}', name: 'correspondence.show', methods: ['POST'], defaults: ['id' => '0'])]
    public function showMailCorrespondenceAction(ManagerRegistry $doctrine, Request $request, $id)
    {
        $em = $doctrine->getManager();
        $correspondence = $em->getRepository(Correspondence::class)->find($id);
        if ($correspondence instanceof MailCorrespondence) {
            return $this->render(
                'Reservations/Correspondence/show_mail.html.twig',
                [
                    'correspondence' => $correspondence,
                    'reservationId' => $request->request->get('reservationId'),
                ]
            );
        }

        return new Response('no mail');
    }

    /**
     * Download a registration form PDF for a given reservation.
     */
    #[IsGranted(new Expression("is_granted('ROLE_RESERVATIONS') or is_granted('ROLE_OPERATIONS')"))]
    #[Route('/registration/download/{id}', name: 'correspondence.registration.download', methods: ['GET'])]
    public function downloadRegistrationTemplateAction(
        ManagerRegistry $doctrine,
        TemplatesService $templatesService,
        Reservation $reservation,
    ): Response {
        $em = $doctrine->getManager();

        $templates = $em->getRepository(Template::class)->loadByTypeName(['TEMPLATE_REGISTRATION_PDF']);
        $template = $templatesService->getDefaultTemplate($templates);

        if (!$template instanceof Template) {
            $this->addFlash('warning', 'operations.frontdesk.registration.missing');

            return $this->redirect($this->generateUrl('reservations.overview'));
        }

        $templateOutput = $templatesService->renderTemplate(
            $template->getId(),
            [$reservation]
        );
        $pdfOutput = $templatesService->getPDFOutput(
            $templateOutput,
            'Registration-'.$reservation->getId(),
            $template,
            false,
            'I'
        );

        $response = new Response($pdfOutput);
        $response->headers->set('Content-Type', 'application/pdf');

        return $response;
    }
}
