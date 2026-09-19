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

use App\Dto\Api\SubsidiaryDto;
use App\Repository\SubsidiaryRepository;
use App\Security\Voter\ApiScopeVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Token-authenticated branch master data, including opening hours.
 *
 * Opening hours are part of a branch rather than an endpoint of their own: they are one
 * property among several, and whoever prints them on a website usually wants the branch
 * name next to them anyway.
 *
 * Only stored data is returned. Whether a branch is open at this very moment is
 * deliberately not computed — that depends on the caller's timezone, and the rest of this
 * API returns locale- and timezone-independent data.
 */
#[Route('/api/v1/subsidiaries')]
#[IsGranted(ApiScopeVoter::SUBSIDIARIES_READ)]
class SubsidiaryApiController extends AbstractController
{
    public function __construct(
        private readonly SubsidiaryRepository $subsidiaryRepository,
    ) {
    }

    /**
     * The configured branches with their master data and weekly opening hours.
     */
    #[Route('', name: 'api.subsidiaries.list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $subsidiaries = $this->subsidiaryRepository->findAllOrdered();
        $data = array_map(static fn ($subsidiary): SubsidiaryDto => SubsidiaryDto::fromEntity($subsidiary), $subsidiaries);

        return new JsonResponse([
            'data' => $data,
            'meta' => ['count' => \count($data)],
        ]);
    }
}
