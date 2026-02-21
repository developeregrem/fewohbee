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

use App\Entity\Template;
use App\Entity\TemplateType;
use App\Service\CSRFProtectionService;
use App\Service\FileUploader;
use App\Service\TemplateSchemaService;
use App\Service\TemplatesService;
use App\Service\TemplatePreview\TemplatePreviewProviderRegistry;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
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
        try {
            $html = $templatesService->renderTemplateString($templateText, $params);
        } catch (\Throwable $e) {
            return $this->json([
                'html' => '',
                'warning' => 'templates.preview.render.error.generic',
                'warningText' => $e->getMessage(),
                'warningVars' => [],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

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
            return $this->redirectToRoute('settings.templates.edit.page', ['id' => $template->getId()]);
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
        try {
            $html = $templatesService->renderTemplateString($templateText, $params);
        } catch (\Throwable $e) {
            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'error' => $e->getMessage(),
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $this->addFlash('warning', $e->getMessage());
            return $this->redirectToRoute('settings.templates.edit.page', ['id' => $template->getId()]);
        }
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
     * Return available snippets for the given template type.
     */
    #[Route('/snippets/{id}', name: 'settings.templates.preview.snippets', methods: ['GET'], defaults: ['id' => '1'])]
    public function getSnippetsForEditor(
        TemplatePreviewProviderRegistry $previewRegistry,
        TranslatorInterface $translator,
        Environment $twig,
        TemplateType $id
    ): Response
    {
        $template = new Template();
        $template->setTemplateType($id);
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

    /**
     * Return the autocomplete schema for the given template type.
     *
     * The schema is a recursive property tree built via PHP Reflection
     * so the code-mode editor can offer context-aware suggestions.
     */
    #[Route('/schema/{id}', name: 'settings.templates.schema', methods: ['GET'], defaults: ['id' => '1'])]
    public function getSchemaForEditor(
        TemplatePreviewProviderRegistry $previewRegistry,
        TemplateSchemaService $schemaService,
        TemplateType $id
    ): Response {
        $template = new Template();
        $template->setTemplateType($id);
        $provider = $previewRegistry->getProvider($template);

        if (!$provider) {
            return $this->json([]);
        }

        $variableMap = $provider->getRenderParamsSchema();
        $schema = $schemaService->buildSchema($variableMap);

        return $this->json($schema);
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
