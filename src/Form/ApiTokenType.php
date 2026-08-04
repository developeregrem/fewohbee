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

namespace App\Form;

use App\Entity\Enum\ApiScope;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ApiTokenType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $scopeChoices = [];
        foreach (ApiScope::cases() as $scope) {
            $scopeChoices[$scope->labelKey()] = $scope->value;
        }

        $builder
            ->add('name', TextType::class, [
                'label' => 'profile.apitokens.name',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(max: 100),
                ],
            ])
            ->add('expiresIn', ChoiceType::class, [
                'label' => 'profile.apitokens.expiry.label',
                // "unlimited" maps to an empty value; required would make the browser
                // reject that choice as an unfilled mandatory field.
                'required' => false,
                'placeholder' => false,
                'choices' => [
                    'profile.apitokens.expiry.never' => '',
                    'profile.apitokens.expiry.days30' => '+30 days',
                    'profile.apitokens.expiry.days90' => '+90 days',
                    'profile.apitokens.expiry.year1' => '+1 year',
                ],
            ])
            ->add('scopes', ChoiceType::class, [
                'label' => 'profile.apitokens.scopes.label',
                'choices' => $scopeChoices,
                'expanded' => true,
                'multiple' => true,
                'constraints' => [
                    new Assert\Count(min: 1, minMessage: 'profile.apitokens.scopes.min'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'messages',
        ]);
    }
}
