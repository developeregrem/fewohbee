<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\GuestStatisticalGroup;
use App\Entity\GuestCategory;
use App\Repository\GuestCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Creates or updates the default set of GuestCategory entries.
 *
 * Idempotent: existing categories matched by systemCode are updated in their
 * translatable fields and structural defaults; the active flag and sortOrder
 * are only set on initial creation so user changes are preserved.
 */
class GuestCategorySeeder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GuestCategoryRepository $repository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function seedDefaults(): void
    {
        $t = fn (string $key): string => $this->translator->trans($key, [], 'GuestCategory');

        $this->createOrUpdate(
            systemCode: 'default_adult',
            name: $t('guest_category.default.adult.name'),
            acronym: $t('guest_category.default.adult.acronym'),
            statisticalGroup: GuestStatisticalGroup::ADULT,
            isCountedInOccupancy: true,
            minAge: 18,
            maxAge: null,
            sortOrder: 10,
        );

        $this->createOrUpdate(
            systemCode: 'default_child',
            name: $t('guest_category.default.child.name'),
            acronym: $t('guest_category.default.child.acronym'),
            statisticalGroup: GuestStatisticalGroup::CHILD,
            isCountedInOccupancy: true,
            minAge: 6,
            maxAge: 17,
            sortOrder: 20,
        );

        $this->createOrUpdate(
            systemCode: 'default_infant',
            name: $t('guest_category.default.infant.name'),
            acronym: $t('guest_category.default.infant.acronym'),
            statisticalGroup: GuestStatisticalGroup::INFANT,
            isCountedInOccupancy: false,
            minAge: 0,
            maxAge: 5,
            sortOrder: 30,
        );

        $this->createOrUpdate(
            systemCode: 'default_exempt',
            name: $t('guest_category.default.exempt.name'),
            acronym: $t('guest_category.default.exempt.acronym'),
            statisticalGroup: GuestStatisticalGroup::OTHER,
            isCountedInOccupancy: true,
            minAge: null,
            maxAge: null,
            sortOrder: 40,
        );

        $this->em->flush();
    }

    private function createOrUpdate(
        string $systemCode,
        string $name,
        string $acronym,
        GuestStatisticalGroup $statisticalGroup,
        bool $isCountedInOccupancy,
        ?int $minAge,
        ?int $maxAge,
        int $sortOrder,
    ): void {
        $existing = $this->repository->findBySystemCode($systemCode);

        if ($existing instanceof GuestCategory) {
            // Preserve user edits to name/acronym/active/sortOrder; only refresh
            // the structural defaults that drive system behaviour.
            $existing->setStatisticalGroup($statisticalGroup);
            $existing->setIsCountedInOccupancy($isCountedInOccupancy);
            $existing->setMinAge($minAge);
            $existing->setMaxAge($maxAge);

            return;
        }

        $category = new GuestCategory();
        $category->setSystemCode($systemCode);
        $category->setName($name);
        $category->setAcronym($acronym);
        $category->setStatisticalGroup($statisticalGroup);
        $category->setIsCountedInOccupancy($isCountedInOccupancy);
        $category->setMinAge($minAge);
        $category->setMaxAge($maxAge);
        $category->setSortOrder($sortOrder);
        $category->setActive(true);

        $this->em->persist($category);
    }
}
