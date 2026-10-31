<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\Localization\GuestLocaleService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Translation inputs for the operator texts of a {@see \App\Entity\Translation\ContentTranslatable}.
 *
 * Add it once per form as `translations`; it holds one sub-form per additional guest language
 * with an input per translatable field. Render the inputs below each base field with the macro
 * in templates/common/content_translations.html.twig. Languages that are not offered right now
 * keep their stored translations.
 *
 * Option `fields`: field => ['type' => FormType class, 'max_length' => ?int, 'constraints' => list,
 * 'options' => array]. Give the base field's validation as `constraints`, so a translation
 * meets the same rules (the length check is added from `max_length`).
 */
final class ContentTranslationsType extends AbstractType
{
    public function __construct(
        private readonly GuestLocaleService $guestLocales,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach ($this->guestLocales->extraLocales() as $locale) {
            $localeForm = $builder->create($locale, FormType::class, ['label' => false, 'required' => false]);
            foreach ($options['fields'] as $field => $config) {
                $maxLength = $config['max_length'] ?? null;
                $fieldOptions = array_replace_recursive([
                    'label' => $this->guestLocales->languageName($locale, false),
                    'translation_domain' => false,
                    'required' => false,
                    'attr' => null !== $maxLength ? ['maxlength' => $maxLength, 'lang' => $locale] : ['lang' => $locale],
                ], $config['options'] ?? []);
                $fieldOptions['constraints'] = [
                    ...(null !== $maxLength ? [new Length(max: $maxLength)] : []),
                    ...($config['constraints'] ?? []),
                ];
                $localeForm->add($field, $config['type'] ?? TextType::class, $fieldOptions);
            }
            $builder->add($localeForm);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('fields');
        $resolver->setAllowedTypes('fields', 'array');
        $resolver->setDefaults([
            'label' => false,
            'required' => false,
            'property_path' => 'contentTranslations',
        ]);
    }
}
