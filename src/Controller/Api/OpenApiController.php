<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the OpenAPI description of the REST API. The document is never public:
 * it is delivered through two authenticated routes because the API firewall is
 * stateless (token only) while the web firewall is session based.
 *
 * - /api/v1/openapi.yaml           token auth, for API tooling
 * - /profile/api-tokens/openapi.yaml   session auth, for signed-in users in the browser
 */
class OpenApiController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/api/v1/openapi.yaml', name: 'api.openapi', methods: ['GET'])]
    public function apiSpec(): Response
    {
        // Any valid token may read the description; no particular scope needed.
        return $this->specResponse(false);
    }

    #[Route('/profile/api-tokens/openapi.yaml', name: 'profile.apitokens.openapi', methods: ['GET'])]
    public function profileSpec(): Response
    {
        return $this->specResponse(true);
    }

    private function specResponse(bool $asDownload): Response
    {
        $path = $this->projectDir.'/docs/openapi.yaml';
        if (!is_readable($path)) {
            throw new NotFoundHttpException();
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/yaml; charset=utf-8');
        $response->setContentDisposition(
            $asDownload ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE,
            'openapi.yaml'
        );

        return $response;
    }
}
