<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\RoomCategoryImage;
use App\Service\Storage\ImageUrlGenerator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ImageUrlExtension extends AbstractExtension
{
    public function __construct(private readonly ImageUrlGenerator $urlGenerator)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('room_category_image_url', $this->roomCategoryImageUrl(...)),
        ];
    }

    public function roomCategoryImageUrl(RoomCategoryImage $image, string $variant = 'medium'): string
    {
        return $this->urlGenerator->roomCategoryUrl(
            $image->getRoomCategory()->getId(),
            $image->getFilename(),
            $variant,
        );
    }
}
