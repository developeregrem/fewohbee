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

use App\Repository\AppartmentRepository;
use App\Repository\SubsidiaryRepository;
use App\Security\Voter\ApiScopeVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ApartmentApiController
{
    public function __construct(
        private readonly AppartmentRepository $apartmentRepository,
        private readonly SubsidiaryRepository $subsidiaryRepository,
    ) {
    }

    #[Route('/api/v1/apartments', name: 'api.apartments.list', methods: ['GET'])]
    #[IsGranted(ApiScopeVoter::APARTMENTS_READ)]
    public function list(Request $request): JsonResponse
    {
        $parameter = $request->query->get('objectId');
        $objectId = null;
        if (null !== $parameter) {
            $objectId = filter_var($parameter, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (false === $objectId) {
                throw new BadRequestHttpException("Invalid parameter 'objectId': expected a positive integer.");
            }
            if (null === $this->subsidiaryRepository->find($objectId)) {
                throw new BadRequestHttpException("Unknown 'objectId'.");
            }
        }

        $data = $this->apartmentRepository->findRoomMasterData($objectId);

        return new JsonResponse([
            'data' => $data,
            'meta' => ['count' => \count($data)],
        ]);
    }
}
