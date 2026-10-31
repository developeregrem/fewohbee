<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Amenity;
use App\Entity\Enum\RoomCategoryKind;
use App\Entity\RoomCategory;
use App\Repository\AmenityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

class RoomCategoryType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $translator = $this->translator;

        $builder
            ->add('name', TextType::class, ['empty_data' => ''])
            ->add('kind', EnumType::class, [
                'class' => RoomCategoryKind::class,
                'label' => 'category.kind',
                'expanded' => true,
                'choice_label' => static fn (RoomCategoryKind $kind): string => $kind->labelKey(),
            ])
            ->add('acronym', TextType::class, ['label' => 'category.acronym'])
            ->add('details', TextareaType::class, [
                'label' => 'category.details',
                'required' => false,
            ])
            ->add('translations', ContentTranslationsType::class, [
                'fields' => [
                    'name' => ['max_length' => 255],
                    'details' => ['type' => TextareaType::class],
                ],
            ])
            ->add('minFullPayers', IntegerType::class, [
                'label' => 'category.min_full_payers',
                'help' => 'category.min_full_payers.help',
                'required' => false,
                'empty_data' => '0',
                'attr' => ['min' => 0],
            ])
            ->add('amenities', EntityType::class, [
                'class' => Amenity::class,
                'query_builder' => static fn (AmenityRepository $repo) => $repo
                    ->createQueryBuilder('a')
                    ->orderBy('a.category', \SortDirection::Ascending)
                    ->addOrderBy('a.sortOrder', \SortDirection::Ascending),
                'choice_label' => static fn (Amenity $amenity) => $translator->trans('amenity.' . $amenity->getSlug()),
                'choice_attr' => static fn (Amenity $amenity) => [
                    'data-icon' => $amenity->getIconFaClass(),
                    'data-category' => $amenity->getCategory(),
                ],
                'group_by' => static fn (Amenity $amenity) => $translator->trans('category.amenity_group.' . $amenity->getCategory()),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'label' => false,
                'by_reference' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RoomCategory::class,
        ]);
    }
}
