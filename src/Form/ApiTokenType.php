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
use App\Service\ApiTokenService;
use App\Service\Mcp\McpSettings;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class ApiTokenType extends AbstractType
{
    /** Token for scripts, integrations and calendar clients (REST API). */
    public const KIND_API = 'api';
    /** Token for AI assistants; only accepted by the MCP endpoint. */
    public const KIND_MCP = 'mcp';

    public function __construct(
        private readonly McpSettings $mcpSettings,
        private readonly Security $security,
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $scopeChoices = [];
        foreach (ApiScope::cases() as $scope) {
            if (!$scope->isMcpScope()) {
                $scopeChoices[$scope->labelKey()] = $scope->value;
            }
        }

        $builder->add('name', TextType::class, [
            'label' => 'profile.apitokens.name',
            'constraints' => [
                new Assert\NotBlank(),
                new Assert\Length(max: 100),
            ],
        ]);

        // The kind choice only exists while MCP is switched on; otherwise every token is a REST token.
        if ($this->mcpSettings->isActive()) {
            $builder->add('kind', ChoiceType::class, [
                'label' => 'profile.apitokens.kind.label',
                'help' => 'profile.apitokens.kind.help',
                'choices' => [
                    'profile.apitokens.kind.api' => self::KIND_API,
                    'profile.apitokens.kind.mcp' => self::KIND_MCP,
                ],
                'data' => self::KIND_API,
                'expanded' => true,
                'choice_attr' => static fn (): array => ['data-action' => 'change->api-token-form#update'],
            ]);
        }

        $builder
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
                // AI tokens must expire; the form controller disables "unlimited" for them.
                'choice_attr' => static fn (string $value): array => '' === $value ? ['data-api-token-form-target' => 'unlimited'] : [],
                'attr' => ['data-api-token-form-target' => 'expiry'],
            ])
            ->add('scopes', ChoiceType::class, [
                'label' => 'profile.apitokens.scopes.label',
                'choices' => $scopeChoices,
                'expanded' => true,
                'multiple' => true,
                'required' => false,
            ])
        ;

        $mcpChoices = [];
        foreach ($this->grantableMcpScopes() as $scope) {
            $mcpChoices[$scope->labelKey()] = $scope->value;
        }
        if ([] !== $mcpChoices) {
            $builder->add('mcpScopes', ChoiceType::class, [
                'label' => 'profile.apitokens.mcp.label',
                'choices' => $mcpChoices,
                'expanded' => true,
                'multiple' => true,
                'required' => false,
            ]);
        }
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        // Explains a missing "Create reservations" option: switched off for all users, not a
        // missing role of this user.
        $view->vars['mcp_write_globally_disabled'] = $this->mcpSettings->isActive()
            && !$this->mcpSettings->isWriteAllowed()
            && [] !== ApiTokenService::grantableScopes([ApiScope::RESERVATIONS_WRITE], $this->reachableRoles());
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'messages',
            'constraints' => [new Assert\Callback(self::validateScopes(...))],
        ]);
    }

    /**
     * @param array{kind?: ?string, scopes?: list<string>, mcpScopes?: list<string>, expiresIn?: ?string}|null $data
     */
    public static function validateScopes(?array $data, ExecutionContextInterface $context): void
    {
        if ([] === ($data['scopes'] ?? [])) {
            $context->buildViolation('profile.apitokens.scopes.min')->atPath('[scopes]')->addViolation();
        }

        if (self::KIND_MCP === ($data['kind'] ?? self::KIND_API) && '' === (string) ($data['expiresIn'] ?? '')) {
            $context->buildViolation('profile.apitokens.mcp.expiry_required')->atPath('[expiresIn]')->addViolation();
        }
    }

    /**
     * The scopes stored on the token: an AI token gets mcp:access plus its AI options, a REST
     * token only the read scopes (AI options submitted for it are ignored).
     *
     * @param array{kind?: ?string, scopes?: list<string>, mcpScopes?: list<string>} $data
     *
     * @return list<string>
     */
    public static function collectScopes(array $data): array
    {
        $scopes = $data['scopes'] ?? [];
        if (self::KIND_MCP === ($data['kind'] ?? self::KIND_API)) {
            $scopes = [...$scopes, ApiScope::MCP_ACCESS->value, ...($data['mcpScopes'] ?? [])];
        }

        return array_values(array_unique($scopes));
    }

    /**
     * AI options the user may grant: only while MCP is on, only scopes their roles back, and
     * "Create reservations" only while it is allowed for all users.
     *
     * @return list<ApiScope>
     */
    private function grantableMcpScopes(): array
    {
        if (!$this->mcpSettings->isActive()) {
            return [];
        }

        $candidates = [ApiScope::GUESTS_READ];
        if ($this->mcpSettings->isWriteAllowed()) {
            $candidates[] = ApiScope::RESERVATIONS_WRITE;
        }

        return ApiTokenService::grantableScopes($candidates, $this->reachableRoles());
    }

    /**
     * @return list<string>
     */
    private function reachableRoles(): array
    {
        $user = $this->security->getUser();

        return null !== $user ? array_values($this->roleHierarchy->getReachableRoleNames($user->getRoles())) : [];
    }
}
