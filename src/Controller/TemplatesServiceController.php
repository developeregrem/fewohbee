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
use App\Entity\TemplateType;
use App\Service\CSRFProtectionService;
use App\Service\FileUploader;
use App\Service\EInvoice\EInvoiceExportService;
use App\Service\InvoiceService;
use App\Service\MailService;
use App\Service\ReservationService;
use App\Service\TemplatesService;
use App\Service\TemplatePreview\TemplatePreviewProviderRegistry;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[Route(path: '/settings/templates')]
class TemplatesServiceController extends AbstractController
{
    /**
     * Index-View.
     */
    #[Route('/', name: 'settings.templates.overview', methods: ['GET'])]
    public function indexAction(ManagerRegistry $doctrine, CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        $em = $doctrine->getManager();
        $templates = $em->getRepository(Template::class)->findAll();
        $operationsTemplates = $em->getRepository(Template::class)->loadByTypeName(['TEMPLATE_OPERATIONS_PDF']);
        $registrationTemplates = $em->getRepository(Template::class)->loadByTypeName(['TEMPLATE_REGISTRATION_PDF']);
        $operationsTemplatesMissing = empty($operationsTemplates) || empty($registrationTemplates);

        return $this->render('Templates/index.html.twig', [
            'templates' => $templates,
            'operationsTemplatesMissing' => $operationsTemplatesMissing,
            'importToken' => $csrfTokenManager->getToken('templates.import.operations')->getValue(),
        ]);
    }

    /**
     * Full-page template workspace with edit + preview tabs.
     */
    #[Route('/{id}/edit-page', name: 'settings.templates.edit.page', methods: ['GET'])]
    public function editPageAction(
        ManagerRegistry $doctrine,
        CSRFProtectionService $csrf,
        TemplatesService $templatesService,
        TemplatePreviewProviderRegistry $previewRegistry,
        Template $template
    ): Response {
        $em = $doctrine->getManager();
        $types = $em->getRepository(TemplateType::class)->findAll();
        $provider = $previewRegistry->getProvider($template);

        return $this->render('Templates/templates_workspace.html.twig', [
            'template' => $template,
            'types' => $types,
            'token' => $csrf->getCSRFTokenForForm(),
            'provider' => $provider,
            'contextDefinition' => $provider ? $provider->getPreviewContextDefinition() : [],
            'context' => $provider ? $provider->buildSampleContext() : [],
            'pdfParams' => $templatesService->parseTemplateParams($template->getParams()),
        ]);
    }

    /**
     * Full-page workspace for creating a new template.
     */
    #[Route('/new-page', name: 'settings.templates.new.page', methods: ['GET'])]
    public function newPageAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, TemplatesService $templatesService): Response
    {
        $em = $doctrine->getManager();
        $template = new Template();
        $template->setId('new');
        $types = $em->getRepository(TemplateType::class)->findAll();

        return $this->render('Templates/templates_workspace.html.twig', [
            'template' => $template,
            'types' => $types,
            'token' => $csrf->getCSRFTokenForForm(),
            'provider' => null,
            'contextDefinition' => [],
            'context' => [],
            'pdfParams' => $templatesService->parseTemplateParams($template->getParams()),
            'isNew' => true,
        ]);
    }

    /**
     * Create new entity.
     */
    #[Route('/create', name: 'settings.templates.create', methods: ['POST'])]
    public function createAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, TemplatesService $ts, Request $request): Response
    {
        $error = false;
        if ($csrf->validateCSRFToken($request)) {
            $template = $ts->getEntityFromForm($request, 'new');

            // check for mandatory fields
            if (0 == strlen($template->getName())) {
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
            } else {
                $em = $doctrine->getManager();
                $em->persist($template);
                $em->flush();

                // add succes message
                $this->addFlash('success', 'templates.flash.create.success');
            }
        }

        return $this->render('feedback.html.twig', [
            'error' => $error,
        ]);
    }

    /**
     * update entity end show update result.
     */
    #[Route('/{id}/edit', name: 'settings.templates.edit', methods: ['POST'], defaults: ['id' => '0'])]
    public function editAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, TemplatesService $ts, Request $request, $id): Response
    {
        $error = false;
        if ($csrf->validateCSRFToken($request)) {
            $template = $ts->getEntityFromForm($request, $id);
            $em = $doctrine->getManager();

            // check for mandatory fields
            if (0 == strlen($template->getName())) {
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

        return $this->render('feedback.html.twig', [
            'error' => $error,
        ]);
    }

    /**
     * delete entity.
     */
    #[Route('/{id}/delete', name: 'settings.templates.delete', methods: ['DELETE'])]
    public function deleteAction(TemplatesService $ts, Request $request, Template $template): Response
    {
        if ($this->isCsrfTokenValid('delete'.$template->getId(), $request->request->get('_token'))) {
            $countCor = $template->getCorrespondences()->count();

            if ($countCor > 0) {
                $this->addFlash('warning', 'templates.flash.delete.inuse.reservations');
            } else {
                $template = $ts->deleteEntity($template->getId());
                if ($template) {
                    $this->addFlash('success', 'templates.flash.delete.success');
                }
            }
        } else {
            $this->addFlash('warning', 'flash.invalidtoken');
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * Render the template preview form and output.
     */
    #[Route('/{id}/preview', name: 'settings.templates.preview', methods: ['GET', 'POST'])]
    public function previewAction(
        TemplatesService $templatesService,
        TemplatePreviewProviderRegistry $previewRegistry,
        Request $request,
        Template $template
    ): Response {

        $provider = $previewRegistry->getProvider($template);
        $contextDefinition = $provider ? $provider->getPreviewContextDefinition() : [];
        $context = $provider ? $provider->buildSampleContext() : [];

        if ($provider) {
            $submitted = $request->isMethod('POST')
                ? $request->request->all()
                : $request->query->all();
            if (!empty($submitted)) {
                $allowedKeys = array_map(static fn (array $field) => $field['name'] ?? '', $contextDefinition);
                $allowedKeys = array_filter($allowedKeys, static fn (string $key) => '' !== $key);
                $filtered = array_intersect_key($submitted, array_flip($allowedKeys));
                $context = array_merge($context, $filtered);
            }
            if ($request->isMethod('POST') && empty(array_filter($submitted, static fn ($value) => $value !== null && $value !== ''))) {
                $context = $provider->buildSampleContext();
            }
        }

        $previewHtml = null;
        $params = [];
        if ($provider) {
            $params = $provider->buildPreviewRenderParams($template, $context);
            $previewHtml = $templatesService->renderTemplateString($template->getText(), $params);
        }

        return $this->render('Templates/templates_preview_form.html.twig', [
            'template' => $template,
            'provider' => $provider,
            'contextDefinition' => $contextDefinition,
            'context' => $context,
            'previewHtml' => $previewHtml,
            'previewWarning' => $params['_previewWarning'] ?? null,
            'previewWarningVars' => $params['_previewWarningVars'] ?? [],
        ]);
    }

    /**
     * Render preview HTML from live editor content without page reload.
     */
    #[Route('/{id}/preview/render', name: 'settings.templates.preview.render', methods: ['POST'])]
    public function previewRenderAction(
        TemplatesService $templatesService,
        TemplatePreviewProviderRegistry $previewRegistry,
        Request $request,
        TranslatorInterface $translator,
        Template $template
    ): Response {
        $provider = $previewRegistry->getProvider($template);
        if (!$provider) {
            return $this->json([
                'html' => '',
                'warning' => 'templates.preview.noprovider',
                'warningText' => (string) $this->container->get('translator')->trans('templates.preview.noprovider'),
                'warningVars' => [],
            ]);
        }

        $contextDefinition = $provider->getPreviewContextDefinition();
        $sampleContext = $provider->buildSampleContext();
        $submittedContext = $request->request->all('previewContext');
        $allowedKeys = array_map(static fn (array $field) => $field['name'] ?? '', $contextDefinition);
        $allowedKeys = array_filter($allowedKeys, static fn (string $key) => '' !== $key);
        $filteredContext = array_intersect_key($submittedContext, array_flip($allowedKeys));
        $context = array_merge($sampleContext, $filteredContext);

        if (empty(array_filter($submittedContext, static fn ($value) => $value !== null && $value !== ''))) {
            $context = $sampleContext;
        }

        $params = $provider->buildPreviewRenderParams($template, $context);
        $templateText = trim((string) $request->request->get('previewText', ''));
        if ('' === $templateText) {
            $templateText = (string) $template->getText();
        }
        $html = $templatesService->renderTemplateString($templateText, $params);

        return $this->json([
            'html' => $html,
            'warning' => $params['_previewWarning'] ?? null,
            'warningText' => !empty($params['_previewWarning'])
                ? (string) $translator->trans(
                    (string) $params['_previewWarning'],
                    is_array($params['_previewWarningVars'] ?? null) ? $params['_previewWarningVars'] : []
                )
                : null,
            'warningVars' => $params['_previewWarningVars'] ?? [],
        ]);
    }

    /**
     * Generate a PDF preview stream for PDF-based templates.
     */
    #[Route('/{id}/preview/pdf', name: 'settings.templates.preview.pdf', methods: ['POST'])]
    public function previewPdfAction(
        TemplatesService $templatesService,
        TemplatePreviewProviderRegistry $previewRegistry,
        Request $request,
        Template $template
    ): Response {
        $provider = $previewRegistry->getProvider($template);
        if (!$provider) {
            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'error' => 'templates.preview.noprovider',
                ], Response::HTTP_BAD_REQUEST);
            }

            $this->addFlash('warning', 'templates.preview.noprovider');
            return $this->redirectToRoute('settings.templates.preview', ['id' => $template->getId()]);
        }

        $contextDefinition = $provider->getPreviewContextDefinition();
        $submittedContext = $request->request->all('previewContext');
        $allowedKeys = array_map(static fn (array $field) => $field['name'] ?? '', $contextDefinition);
        $allowedKeys = array_filter($allowedKeys, static fn (string $key) => '' !== $key);
        $filteredContext = array_intersect_key($submittedContext, array_flip($allowedKeys));
        $context = array_merge($provider->buildSampleContext(), $filteredContext);

        if (empty(array_filter($submittedContext, static fn ($value) => $value !== null && $value !== ''))) {
            $context = $provider->buildSampleContext();
        }

        $params = $provider->buildPreviewRenderParams($template, $context);
        $templateText = trim((string) $request->request->get('previewText', ''));
        if ('' === $templateText) {
            $templateText = (string) $template->getText();
        }
        $html = $templatesService->renderTemplateString($templateText, $params);
        $pdfOutput = $templatesService->getPDFOutput($html, 'Template-Preview-'.$template->getId(), $template, true, 'I');

        $response = new Response($pdfOutput);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'inline; filename=\"Template-Preview-'.$template->getId().'.pdf\"');

        return $response;
    }

    /**
     * Import default operations report templates from the examples repository.
     */
    #[Route('/import/operations', name: 'settings.templates.import.operations', methods: ['POST'])]
    public function importOperationsTemplatesAction(
        ManagerRegistry $doctrine,
        CsrfTokenManagerInterface $csrfTokenManager,
        TemplatesService $templatesService,
        Request $request
    ): Response {
        $token = new CsrfToken('templates.import.operations', (string) $request->request->get('_csrf_token'));
        if (!$csrfTokenManager->isTokenValid($token)) {
            $this->addFlash('warning', 'flash.invalidtoken');

            return $this->redirectToRoute('settings.templates.overview');
        }

        $em = $doctrine->getManager();
        $type = $em->getRepository(TemplateType::class)->findOneBy(['name' => 'TEMPLATE_OPERATIONS_PDF']);
        if (!$type instanceof TemplateType) {
            $this->addFlash('warning', 'templates.operations.import.missing_type');

            return $this->redirectToRoute('settings.templates.overview');
        }

        $existingOperations = $em->getRepository(Template::class)->loadByTypeName(['TEMPLATE_OPERATIONS_PDF']);
        $existingRegistration = $em->getRepository(Template::class)->loadByTypeName(['TEMPLATE_REGISTRATION_PDF']);
        $needsOperationsImport = empty($existingOperations);
        $needsRegistrationImport = empty($existingRegistration);
        if (!$needsOperationsImport && !$needsRegistrationImport) {
            $this->addFlash('info', 'templates.operations.import.already_present');

            return $this->redirectToRoute('settings.templates.overview');
        }

        $baseUrl = TemplatesService::EXAMPLES_BASE_URL;
        $imported = 0;
        if ($needsOperationsImport) {
            $entries = $templatesService->getOperationsTemplateDefinitions();
            $imported += $templatesService->importTemplates($type, $entries, $baseUrl);
        }
        if ($needsRegistrationImport) {
            $registrationType = $em->getRepository(TemplateType::class)->findOneBy(['name' => 'TEMPLATE_REGISTRATION_PDF']);
            if ($registrationType instanceof TemplateType) {
                $registrationEntries = $templatesService->getRegistrationTemplateDefinitions();
                $imported += $templatesService->importTemplates($registrationType, $registrationEntries, $baseUrl);
            }
        }

        if ($imported > 0) {
            $em->flush();
            $this->addFlash('success', 'templates.operations.import.success');
        } else {
            $this->addFlash('warning', 'templates.operations.import.failed');
        }

        return $this->redirectToRoute('settings.templates.overview');
    }



    /**
     * Called when clicking add conversation in the reservation overview.
     */
    #[Route('/select/reservation', name: 'settings.templates.select.reservation', methods: ['POST'])]
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
            'Templates/templates_form_show_selected_reservations.html.twig',
            [
                'reservations' => $reservations,
            ]
        );
    }

    #[Route('/get/reservations', name: 'settings.templates.get.reservations', methods: ['GET'])]
    public function getReservationsAction(ReservationService $reservationService, Request $request)
    {
        if ('true' == $request->query->get('createNew')) {
            $reservationService->resetSelectedReservations();
            // reset session variables
            // $requestStack->getSession()->remove("invoicePositionsMiscellaneous");
        }

        if (!$reservationService->hasSelectedReservations()) {
            $objectContainsReservations = 'false';
        } else {
            $objectContainsReservations = 'true';
        }

        return $this->render(
            'Templates/templates_form_select_reservation.html.twig',
            [
                'objectContainsReservations' => $objectContainsReservations,
            ]
        );
    }

    #[Route('/remove/reservation/from/selection', name: 'settings.templates.remove.reservation.from.selection', methods: ['POST'])]
    public function removeReservationFromSelectionAction(ReservationService $reservationService, Request $request)
    {
        if (null != $request->request->get('reservationkey')) {
            $reservationService->removeReservationFromSelection((int) $request->request->get('reservationkey'));
        }

        return $this->render(
            'Templates/templates_form_show_selected_reservations.html.twig',
            [
                'reservations' => $reservationService->getSelectedReservations(),
            ]
        );
    }

    #[Route('/email/send', name: 'settings.templates.email.send', methods: ['POST'])]
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

    #[Route('/file/save', name: 'settings.templates.file.save', methods: ['POST'])]
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
    #[Route('/attachment/remove', name: 'settings.templates.attachment.remove', methods: ['POST'])]
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
            // $correspondence = $em->getReference(Correspondence::class, $aId);
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

    #[Route('/correspondence/remove', name: 'settings.templates.correspondence.remove', methods: ['POST'])]
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
    #[Route('/attachment/add', name: 'settings.templates.attachment.add', methods: ['POST'])]
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

    #[Route('/correspondence/export/pdf/{id}/', name: 'settings.templates.correspondence.export.pdf', methods: ['GET'], defaults: ['id' => '0'])]
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

    #[Route('/correspondence/show/{id}', name: 'settings.templates.correspondence.show', methods: ['POST'], defaults: ['id' => '0'])]
    public function showMailCorrespondenceAction(ManagerRegistry $doctrine, Request $request, $id)
    {
        $em = $doctrine->getManager();
        $correspondence = $em->getRepository(Correspondence::class)->find($id);
        if ($correspondence instanceof MailCorrespondence) {
            return $this->render(
                'Templates/templates_show_mail.html.twig',
                [
                    'correspondence' => $correspondence,
                    'reservationId' => $request->request->get('reservationId'),
                ]
            );
        }

        return new Response('no mail');
    }

    #[Route('/editortemplate/{templateTypeId}', name: 'settings.templates.editor.template', methods: ['GET'], defaults: ['templateTypeId' => '1'])]
    public function getTemplatesForEditor(ManagerRegistry $doctrine, $templateTypeId)
    {
        $em = $doctrine->getManager();
        /* @var $type TemplateType */
        $type = $em->getRepository(TemplateType::class)->find($templateTypeId);
        if ($type instanceof TemplateType && !empty($type->getEditorTemplate())) {
            $response = $this->render('Templates/'.$type->getEditorTemplate());
            $response->headers->set('Content-Type', 'application/json');
        } else {
            $response = $this->json([]);
        }

        return $response;
    }

    /**
     * Return available snippets for the given template type.
     */
    #[Route('/snippets/{id}', name: 'settings.templates.preview.snippets', methods: ['GET'], defaults: ['id' => '1'])]
    public function getSnippetsForEditor(
        TemplatePreviewProviderRegistry $previewRegistry,
        TranslatorInterface $translator,
        Environment $twig,
        TemplateType $templateType
    ): Response
    {
        $template = new Template();
        $template->setTemplateType($templateType);
        $provider = $previewRegistry->getProvider($template);

        if (!$provider) {
            return $this->json([]);
        }

        $snippets = $provider->getAvailableSnippets();
        foreach ($snippets as &$snippet) {
            if (!empty($snippet['label'])) {
                $snippet['label'] = $translator->trans($snippet['label']);
            }
            if (!empty($snippet['content']) && is_string($snippet['content'])) {
                try {
                    // Render snippet labels/text (e.g. {{ '...'|trans }}) while
                    // keeping pseudo-twig placeholders ([[ ... ]]) untouched.
                    $snippet['content'] = $twig->createTemplate($snippet['content'])->render();
                } catch (\Throwable) {
                    // Keep original content if rendering fails for any snippet.
                }
            }
        }
        unset($snippet);

        return $this->json($snippets);
    }

    #[Route('/upload', name: 'templates.upload', methods: ['POST'])]
    public function uploadImage(Request $request, FileUploader $fos)
    {
        /** @var UploadedFile $imageFile */
        $imageFile = $request->files->get('file');
        if (!$fos->isValidImage($imageFile)) {
            return new Response('', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        try {
            $name = $fos->upload($imageFile);
        } catch (\Symfony\Component\HttpFoundation\File\Exception\FileException $ex) {
            return new Response('', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $path = rtrim($request->getBasePath(), '/').'/'.$fos->getPublicDirectory().'/'.$name;

        return $this->json([
            'location' => $path,
        ]);
    }
}
