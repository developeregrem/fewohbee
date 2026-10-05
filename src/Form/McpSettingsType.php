<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AppSettings;
use App\Service\Mcp\McpSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Administrator switches for AI assistants (MCP).
 */
class McpSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('mcpEnabled', CheckboxType::class, [
                'label' => 'mcp.settings.enabled.label',
                'help' => 'mcp.settings.enabled.help',
                'required' => false,
            ])
            ->add('mcpWriteEnabled', CheckboxType::class, [
                'label' => 'mcp.settings.write_enabled.label',
                'help' => 'mcp.settings.write_enabled.help',
                'required' => false,
            ])
            // Unmapped: the controller stores the parsed list, see McpSettings::parseHosts().
            ->add('allowedHosts', TextareaType::class, [
                'label' => 'mcp.settings.allowed_hosts.label',
                'help' => 'mcp.settings.allowed_hosts.help',
                'mapped' => false,
                'required' => false,
                'attr' => ['rows' => 3, 'class' => 'font-monospace', 'placeholder' => 'fewohbee.example.com'],
                'constraints' => [
                    new Assert\Length(max: 2000),
                    new Assert\Callback(static function (?string $value, ExecutionContextInterface $context): void {
                        $invalid = McpSettings::parseHosts((string) $value)['invalid'];
                        if ([] !== $invalid) {
                            $context->buildViolation('mcp.settings.allowed_hosts.invalid')
                                ->setParameter('%hosts%', implode(', ', $invalid))
                                ->setTranslationDomain('messages')
                                ->addViolation();
                        }
                    }),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AppSettings::class,
            'translation_domain' => 'messages',
        ]);
    }
}
