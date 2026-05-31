<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\AppSettingsType;
use App\Repository\WorkflowRepository;
use App\Service\AppSettingsService;
use App\Service\MailService;
use App\Service\MailTransportFactory;
use App\Service\SmtpPasswordCrypto;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/settings/general')]
#[IsGranted('ROLE_ADMIN')]
class AppSettingsController extends AbstractController
{
    #[Route('', name: 'settings.general.index', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        AppSettingsService $settingsService,
        WorkflowRepository $workflowRepository,
        SmtpPasswordCrypto $smtpPasswordCrypto,
        MailService $mailService
    ): Response
    {
        $settings = $settingsService->getSettings();

        $form = $this->createForm(AppSettingsType::class, $settings);

        // populate the unmapped salutations field
        $form->get('customerSalutations')->setData(implode(', ', $settings->getCustomerSalutations()));

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // parse comma-separated salutations back into array
            $rawSalutations = (string) $form->get('customerSalutations')->getData();
            $settings->setCustomerSalutations(
                array_map('trim', explode(',', $rawSalutations))
            );

            $plainSmtpPassword = $form->get('smtpPassword')->getData();
            if (\is_string($plainSmtpPassword) && '' !== trim($plainSmtpPassword)) {
                $settings->setSmtpPasswordEncrypted($smtpPasswordCrypto->encrypt($plainSmtpPassword));
            }

            $settingsService->saveSettings($settings);
            $this->addFlash('success', 'app_settings.flash.saved');

            return $this->redirectToRoute('settings.general.index');
        }

        return $this->render('Settings/AppSettings/index.html.twig', [
            'form' => $form->createView(),
            'fallbackEmail' => $settingsService->getNotificationEmail($settings),
            'mailEnabled' => $mailService->isMailEnabled(),
            'notifyOnlineBookingWorkflow' => $workflowRepository->findBySystemCode('notify_online_booking'),
            'notifyCalendarImportWorkflow' => $workflowRepository->findBySystemCode('notify_calendar_import'),
        ]);
    }

    #[Route('/smtp-test', name: 'settings.general.smtp_test', methods: ['POST'])]
    public function testSmtp(
        Request $request,
        AppSettingsService $settingsService,
        SmtpPasswordCrypto $smtpPasswordCrypto,
        MailTransportFactory $mailTransportFactory,
        TranslatorInterface $translator
    ): JsonResponse {
        $settings = clone $settingsService->getSettings();
        $form = $this->createForm(AppSettingsType::class, $settings);
        $form->get('customerSalutations')->setData(implode(', ', $settings->getCustomerSalutations()));
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->json([
                'success' => false,
                'message' => $translator->trans('app_settings.smtp_test.invalid'),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $plainSmtpPassword = $form->get('smtpPassword')->getData();
        if (\is_string($plainSmtpPassword) && '' !== trim($plainSmtpPassword)) {
            $settings->setSmtpPasswordEncrypted($smtpPasswordCrypto->encrypt($plainSmtpPassword));
        }

        try {
            $mailTransportFactory->testSmtpConnection($settings);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => $translator->trans('app_settings.smtp_test.failure', ['%message%' => $e->getMessage()]),
            ], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'success' => true,
            'message' => $translator->trans('app_settings.smtp_test.success'),
        ]);
    }
}
