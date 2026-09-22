<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ApiToken;
use App\Entity\Enum\ApiScope;
use App\Form\McpSettingsType;
use App\Repository\ApiTokenRepository;
use App\Repository\McpToolCallLogRepository;
use App\Service\AppSettingsService;
use App\Service\Mcp\McpSettings;
use App\Workflow\WorkflowSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Settings page for AI assistants (MCP). Only reachable when the operator enabled MCP through
 * MCP_ENABLED; administrators switch it on here, see who has access and what assistants did.
 */
#[Route('/settings/mcp')]
#[IsGranted('ROLE_ADMIN')]
final class McpSettingsController extends AbstractController
{
    private const LOG_LIMIT = 50;

    public function __construct(
        private readonly McpSettings $mcpSettings,
        private readonly AppSettingsService $appSettingsService,
    ) {
    }

    #[Route('', name: 'settings.mcp.index', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        WorkflowSeeder $workflowSeeder,
        ApiTokenRepository $apiTokenRepository,
        McpToolCallLogRepository $logRepository,
    ): Response {
        if (!$this->mcpSettings->isAvailable()) {
            throw $this->createNotFoundException();
        }

        $settings = $this->appSettingsService->getSettings();
        $wasEnabled = $settings->isMcpEnabled();

        $form = $this->createForm(McpSettingsType::class, $settings);
        // Nothing configured yet: suggest the host the administrator is using right now.
        $configuredHosts = $settings->getMcpAllowedHosts();
        $form->get('allowedHosts')->setData(implode("\n", [] !== $configuredHosts ? $configuredHosts : $this->suggestedHosts($request)));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Write access without MCP itself would be a dormant setting nobody can see the effect of.
            if (!$settings->isMcpEnabled()) {
                $settings->setMcpWriteEnabled(false);
            }
            $settings->setMcpAllowedHosts(McpSettings::parseHosts((string) $form->get('allowedHosts')->getData())['hosts']);
            $this->appSettingsService->saveSettings($settings);

            if (!$wasEnabled && $settings->isMcpEnabled()) {
                // The notification workflow only appears once MCP is actually in use.
                $workflowSeeder->seedAssistantWorkflows();
            }

            $this->addFlash('success', 'mcp.settings.flash.saved');

            return $this->redirectToRoute('settings.mcp.index');
        }

        $mcpTokens = array_values(array_filter(
            $apiTokenRepository->findBy([], ['createdAt' => 'DESC']),
            static fn (ApiToken $token): bool => $token->hasScope(ApiScope::MCP_ACCESS),
        ));

        // Passing the form (not its view) answers an invalid submission with 422, which Turbo needs to render it.
        return $this->render('Settings/Mcp/index.html.twig', [
            'form' => $form,
            'active' => $this->mcpSettings->isActive(),
            'hostAllowed' => $this->mcpSettings->isHostAllowed($request->getHost()),
            'currentHost' => $request->getHost(),
            'configuredHosts' => $this->mcpSettings->getConfiguredHosts(),
            'secure' => $request->isSecure(),
            'insecureHttpAllowed' => $this->mcpSettings->isInsecureHttpAllowed(),
            'mcpTokens' => $mcpTokens,
            'logs' => $settings->isMcpEnabled() ? $logRepository->findLatest(self::LOG_LIMIT) : [],
        ]);
    }

    /**
     * The current request host, unless it is a loopback name (always allowed anyway).
     *
     * @return list<string>
     */
    private function suggestedHosts(Request $request): array
    {
        $host = McpSettings::normalizeHost($request->getHost());

        return null === $host || \in_array($host, McpSettings::LOOPBACK_HOSTS, true) ? [] : [$host];
    }

    /**
     * Lets administrators withdraw any user's AI assistant access, e.g. when a device is lost.
     */
    #[Route('/tokens/{id}/revoke', name: 'settings.mcp.token_revoke', methods: ['GET', 'DELETE'], requirements: ['id' => '\d+'])]
    public function revokeToken(Request $request, ApiTokenRepository $apiTokenRepository, EntityManagerInterface $em, int $id): Response
    {
        if (!$this->mcpSettings->isAvailable()) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('delete'.$id, (string) $request->request->get('_token'))) {
            $token = $apiTokenRepository->find($id);
            if ($token instanceof ApiToken && $token->hasScope(ApiScope::MCP_ACCESS)) {
                $em->remove($token);
                $em->flush();
                $this->addFlash('success', 'mcp.settings.tokens.revoked');
            }
        } else {
            $this->addFlash('warning', 'flash.invalidtoken');
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
