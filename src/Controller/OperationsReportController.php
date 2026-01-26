<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Subsidiary;
use App\Entity\Template;
use App\Service\HousekeepingViewService;
use App\Service\OperationsReportService;
use App\Service\TemplatesService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Handles operations reports based on templates.
 */
#[IsGranted('ROLE_HOUSEKEEPING')]
#[Route('/operations/reports')]
class OperationsReportController extends AbstractController
{
    /**
     * Show the report filters and template selection.
     */
    #[Route('', name: 'operations.reports', methods: ['GET'])]
    public function indexAction(
        ManagerRegistry $doctrine,
        Request $request,
        RequestStack $requestStack,
        TemplatesService $templatesService,
        HousekeepingViewService $housekeepingViewService
    ): Response {
        $em = $doctrine->getManager();
        $subsidiaries = $em->getRepository(Subsidiary::class)->findAll();
        $subsidiaryId = (string) $request->query->get('subsidiary', 'all');
        $selectedSubsidiary = $this->resolveSubsidiary($em, $subsidiaryId);

        $startDate = $this->resolveStartDate($request->query->get('start'));
        $endDate = $this->resolveEndDate($request->query->get('end'), $startDate);
        $queryParams = $request->query->all();
        $selectedOccupancyTypes = $housekeepingViewService->normalizeOccupancyTypes($queryParams['occupancyTypes'] ?? null);

        $templates = $em->getRepository(Template::class)->loadByTypeName(['TEMPLATE_OPERATIONS_PDF']);
        $templateId = $templatesService->getTemplateId($doctrine, $requestStack, 'TEMPLATE_OPERATIONS_PDF', 'operations-template-id');
        $selectedTemplateId = (int) $request->query->get('templateId', $templateId);

        return $this->render('Operations/Reports/index.html.twig', [
            'subsidiaries' => $subsidiaries,
            'selectedSubsidiaryId' => $subsidiaryId,
            'selectedSubsidiary' => $selectedSubsidiary,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'occupancyTypes' => $housekeepingViewService->getAllowedOccupancyTypes(),
            'selectedOccupancyTypes' => $selectedOccupancyTypes,
            'occupancyLabels' => $housekeepingViewService->getOccupancyLabels(),
            'templates' => $templates,
            'templateId' => $selectedTemplateId,
        ]);
    }

    /**
     * Download the report as a PDF.
     */
    #[Route('/export/pdf', name: 'operations.reports.export.pdf', methods: ['GET'])]
    public function exportPdfAction(
        ManagerRegistry $doctrine,
        Request $request,
        RequestStack $requestStack,
        TemplatesService $templatesService,
        OperationsReportService $reportService,
        HousekeepingViewService $housekeepingViewService
    ): Response {
        $em = $doctrine->getManager();
        $subsidiaryId = (string) $request->query->get('subsidiary', 'all');
        $subsidiary = $this->resolveSubsidiary($em, $subsidiaryId);

        $startDate = $this->resolveStartDate($request->query->get('start'));
        $endDate = $this->resolveEndDate($request->query->get('end'), $startDate);
        $queryParams = $request->query->all();
        $selectedOccupancyTypes = $housekeepingViewService->normalizeOccupancyTypes($queryParams['occupancyTypes'] ?? null);

        $templateId = (int) $request->query->get('templateId', 0);
        if (0 === $templateId) {
            $this->addFlash('warning', 'operations.reports.template.missing');

            return $this->redirect($this->generateUrl('operations.reports'));
        }

        $template = $em->getRepository(Template::class)->find($templateId);
        if (!$template instanceof Template) {
            $this->addFlash('warning', 'templates.notfound');

            return $this->redirect($this->generateUrl('operations.reports'));
        }

        $requestStack->getSession()->set('operations-template-id', $templateId);

        $reportData = $reportService->buildReportData($startDate, $endDate, $subsidiary, $selectedOccupancyTypes);
        $reportData['filters']['template'] = $template;
        $reportData['filters']['subsidiaryId'] = $subsidiaryId;

        $templateOutput = $templatesService->renderTemplate($templateId, $reportData, $reportService);
        dump($reportData);
        $isPreview = $request->query->getBoolean('preview');
        $pdfOutput = $templatesService->getPDFOutput(
            $templateOutput,
            'Operations-Report-'.$startDate->format('Y-m-d'),
            $template,
            false,
            $isPreview ? 'I' : null
        );

        $response = new Response($pdfOutput);
        $response->headers->set('Content-Type', 'application/pdf');
        if ($isPreview) {
            $response->headers->set('Content-Disposition', 'inline; filename="Operations-Report-'.$startDate->format('Y-m-d').'.pdf"');
        }

        return $response;
    }

    /**
     * Render the PDF preview container.
     */
    #[Route('/preview', name: 'operations.reports.preview', methods: ['GET'])]
    public function previewAction(Request $request): Response
    {
        $templateId = (int) $request->query->get('templateId', 0);
        if (0 === $templateId) {
            return $this->render('Operations/Reports/_preview.html.twig', [
                'previewUrl' => null,
                'message' => 'operations.reports.template.missing',
            ]);
        }

        $params = $request->query->all();
        $params['preview'] = 1;
        $previewUrl = $this->generateUrl('operations.reports.export.pdf', $params);

        return $this->render('Operations/Reports/_preview.html.twig', [
            'previewUrl' => $previewUrl,
            'message' => null,
        ]);
    }

    /**
     * Resolve the requested subsidiary entity, if any.
     */
    private function resolveSubsidiary(EntityManagerInterface $em, string $subsidiaryId): ?Subsidiary
    {
        if ('all' === $subsidiaryId || '' === $subsidiaryId) {
            return null;
        }

        $subsidiary = $em->getRepository(Subsidiary::class)->find($subsidiaryId);

        return $subsidiary instanceof Subsidiary ? $subsidiary : null;
    }

    /**
     * Resolve the start date, defaulting to Monday of the current week.
     */
    private function resolveStartDate(?string $dateParam): \DateTimeImmutable
    {
        $timezone = new \DateTimeZone('UTC');
        if ($dateParam) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $dateParam, $timezone);
            if ($parsed instanceof \DateTimeImmutable) {
                return $parsed->setTime(0, 0, 0);
            }
        }

        return (new \DateTimeImmutable('today', $timezone))->modify('monday this week')->setTime(0, 0, 0);
    }

    /**
     * Resolve the end date, defaulting to Sunday of the start week.
     */
    private function resolveEndDate(?string $dateParam, \DateTimeImmutable $start): \DateTimeImmutable
    {
        $timezone = new \DateTimeZone('UTC');
        if ($dateParam) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $dateParam, $timezone);
            if ($parsed instanceof \DateTimeImmutable) {
                $end = $parsed->setTime(0, 0, 0);

                return $end < $start ? $start : $end;
            }
        }

        return $start->modify('+6 days')->setTime(0, 0, 0);
    }
}
