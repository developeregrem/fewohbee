<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\Localization\GuestLocaleService;
use App\Service\Localization\GuestSalutations;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;

/**
 * One row of the salutation table in the general settings: the stored salutation and its
 * wording in each further guest language. Read back with GuestSalutations::applySettingsRows().
 */
final class CustomerSalutationType extends AbstractType
{
    public function __construct(
        private readonly GuestLocaleService $guestLocales,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('salutation', TextType::class, [
            'label' => false,
            'required' => false,
            'attr' => ['maxlength' => GuestSalutations::MAX_LENGTH],
            'constraints' => [new Length(max: GuestSalutations::MAX_LENGTH)],
        ]);
        foreach ($this->guestLocales->extraLocales() as $locale) {
            $builder->add($locale, TextType::class, [
                'label' => false,
                'required' => false,
                'attr' => ['maxlength' => 50, 'lang' => $locale],
                'constraints' => [new Length(max: 50)],
            ]);
        }
    }
}
