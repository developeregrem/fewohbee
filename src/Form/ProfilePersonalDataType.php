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

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ProfilePersonalDataType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstname', TextType::class, [
                'label' => 'user.firstname',
                'constraints' => [
                    new Assert\NotBlank(),
                ],
            ])
            ->add('lastname', TextType::class, [
                'label' => 'user.lastname',
                'constraints' => [
                    new Assert\NotBlank(),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'user.email',
                'constraints' => [
                    new Assert\NotBlank(message: 'form.email.notblank'),
                    new Assert\Email(message: 'form.email.invalid'),
                ],
            ])
            ->add('themePreference', ChoiceType::class, [
                'label' => 'profile.theme.label',
                'choices' => [
                    'profile.theme.auto' => 'auto',
                    'profile.theme.dark' => 'dark',
                    'profile.theme.light' => 'light',
                ],
                'help' => 'profile.theme.help',
                'constraints' => [
                    new Assert\Choice(['auto', 'dark', 'light']),
                ],
            ])
            ->add('password', PasswordType::class, [
                'label' => 'user.password',
                'required' => false,
                'mapped' => false,
                'empty_data' => '',
                'attr' => [
                    'autocomplete' => 'new-password',
                ],
                'help' => 'profile.personal_data.password_help',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
