<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\ReservationStatus;
use App\Service\DisplayNameResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Exposes {@see DisplayNameResolver} to templates as `display_name`.
 *
 * Use `{{ status|display_name }}` instead of `{{ status.name }}` everywhere a
 * reservation status is rendered — including user-authored PDF and mail
 * templates, which are compiled by the same Twig environment.
 */
final class DisplayNameExtension extends AbstractExtension
{
    public function __construct(
        private readonly DisplayNameResolver $resolver,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('display_name', [$this, 'displayName']),
        ];
    }

    public function displayName(?ReservationStatus $status): string
    {
        return null === $status ? '' : $this->resolver->resolve($status);
    }
}
