<?php

declare(strict_types=1);

namespace App\Mcp\Security;

use App\Security\Voter\ApiScopeVoter;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Answers tools/list with only the tools the access token may call, so an assistant does not see
 * (and try) what ScopedReferenceHandler would refuse anyway. A tool is listed when every scope of
 * its #[McpRequiresScope] is granted; tools without the attribute are never listed (fail closed).
 *
 * Registered through the bundle's "mcp.request_handler" tag, which puts it before the SDK's own
 * ListToolsHandler. The handful of tools is returned in one page.
 *
 * @implements RequestHandlerInterface<ListToolsResult>
 */
final class ScopedListToolsHandler implements RequestHandlerInterface
{
    public function __construct(
        #[Autowire(service: 'mcp.server.default.registry')]
        private readonly RegistryInterface $registry,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof ListToolsRequest;
    }

    /**
     * @return Response<ListToolsResult>
     */
    public function handle(Request $request, SessionInterface $session): Response
    {
        $tools = [];
        foreach ($this->registry->getTools()->references as $name => $tool) {
            $scopes = ScopedReferenceHandler::requiredScopes($this->registry->getTool((string) $name)->handler);
            if (null === $scopes) {
                continue;
            }
            foreach ($scopes as $scope) {
                if (!$this->authorizationChecker->isGranted(ApiScopeVoter::attributeFor($scope))) {
                    continue 2;
                }
            }
            $tools[] = $tool;
        }

        return new Response($request->getId(), new ListToolsResult($tools, null));
    }
}
