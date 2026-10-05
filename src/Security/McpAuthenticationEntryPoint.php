<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Answers unauthenticated requests to the MCP endpoint with a bearer challenge.
 * Bearer only: offering Basic would make browsers prompt for and cache credentials.
 */
final class McpAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $response = new JsonResponse(
            ['error' => ['code' => Response::HTTP_UNAUTHORIZED, 'message' => 'Authentication required.']],
            Response::HTTP_UNAUTHORIZED
        );
        $response->headers->set('WWW-Authenticate', 'Bearer realm="fewohbee-mcp"');

        return $response;
    }
}
