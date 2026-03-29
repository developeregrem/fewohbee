<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\RoomCategory;
use App\Entity\RoomCategoryImage;
use App\Service\RoomCategoryImageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * AJAX endpoints for managing room category images (upload, delete, reorder, set primary).
 * All endpoints return JSON and are used by the room_category_images Stimulus controller.
 */
#[Route('/settings/category/{id}/images')]
class RoomCategoryImageController extends AbstractController
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    /**
     * Uploads one or more images for a room category.
     * Accepts multipart form data with 'files[]' parameter.
     * Returns JSON array of created image objects.
     */
    #[Route('/upload', name: 'room_category_image_upload', methods: ['POST'])]
    public function upload(
        RoomCategory $roomCategory,
        Request $request,
        RoomCategoryImageService $imageService,
    ): JsonResponse {
        $files = $request->files->get('files', []);
        if (!is_array($files)) {
            $files = [$files];
        }

        $results = [];
        foreach ($files as $file) {
            if (null === $file) {
                continue;
            }
            try {
                $image = $imageService->upload($roomCategory, $file);
                $results[] = $this->serializeImage($image, $imageService);
            } catch (\InvalidArgumentException $e) {
                return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
            }
        }

        return new JsonResponse($results);
    }

    /**
     * Deletes a single image from the room category.
     */
    #[Route('/{imageId}/delete', name: 'room_category_image_delete', methods: ['DELETE'])]
    public function delete(
        RoomCategory $roomCategory,
        int $imageId,
        Request $request,
        RoomCategoryImageService $imageService,
        EntityManagerInterface $em,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('delete' . $imageId, $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $image = $em->getRepository(RoomCategoryImage::class)->find($imageId);

        if (null === $image || $image->getRoomCategory()->getId() !== $roomCategory->getId()) {
            return new JsonResponse(['error' => 'Image not found'], Response::HTTP_NOT_FOUND);
        }

        $imageService->delete($image);

        return new JsonResponse(['success' => true]);
    }

    /**
     * Updates the display order of images.
     * Expects JSON body: { "order": [imageId1, imageId2, ...] }
     */
    #[Route('/reorder', name: 'room_category_image_reorder', methods: ['POST'])]
    public function reorder(
        RoomCategory $roomCategory,
        Request $request,
        EntityManagerInterface $em,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $order = $data['order'] ?? [];

        foreach ($order as $position => $imageId) {
            $image = $em->getRepository(RoomCategoryImage::class)->find($imageId);
            if (null !== $image && $image->getRoomCategory()->getId() === $roomCategory->getId()) {
                $image->setSortOrder($position);
            }
        }

        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    /**
     * Sets an image as the primary (hero) image for its room category.
     * Clears the primary flag from all other images in the same category.
     */
    #[Route('/{imageId}/primary', name: 'room_category_image_primary', methods: ['POST'])]
    public function setPrimary(
        RoomCategory $roomCategory,
        int $imageId,
        EntityManagerInterface $em,
    ): JsonResponse {
        $targetImage = $em->getRepository(RoomCategoryImage::class)->find($imageId);

        if (null === $targetImage || $targetImage->getRoomCategory()->getId() !== $roomCategory->getId()) {
            return new JsonResponse(['error' => 'Image not found'], Response::HTTP_NOT_FOUND);
        }

        // Clear primary flag from all images, then set the target
        foreach ($roomCategory->getImages() as $image) {
            $image->setIsPrimary($image->getId() === $targetImage->getId());
        }

        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    /**
     * Serializes a RoomCategoryImage entity to a JSON-friendly array.
     * Includes action URLs (delete, primary) built via the router so the
     * JS controller does not need to assemble paths manually.
     */
    private function serializeImage(RoomCategoryImage $image, RoomCategoryImageService $imageService): array
    {
        $basePath = rtrim($this->requestStack->getCurrentRequest()?->getBasePath() ?? '', '/');
        $categoryId = $image->getRoomCategory()->getId();
        $imageId = $image->getId();

        return [
            'id' => $imageId,
            'filename' => $image->getFilename(),
            'thumbnailUrl' => $basePath . $imageService->getPublicUrl($image, 'thumb'),
            'mediumUrl' => $basePath . $imageService->getPublicUrl($image, 'medium'),
            'sortOrder' => $image->getSortOrder(),
            'isPrimary' => $image->isPrimary(),
            'deleteUrl' => $this->generateUrl('room_category_image_delete', [
                'id' => $categoryId,
                'imageId' => $imageId,
            ]),
            'primaryUrl' => $this->generateUrl('room_category_image_primary', [
                'id' => $categoryId,
                'imageId' => $imageId,
            ]),
            'csrfToken' => $this->csrfTokenManager->getToken('delete' . $imageId)->getValue(),
        ];
    }
}
