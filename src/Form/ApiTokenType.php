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

    /** Order of the scopes within their group in the token form. */
    private const SCOPE_ORDER = [
        ApiScope::RESERVATIONS_READ,
        ApiScope::AVAILABILITY_READ,
        ApiScope::PRICES_READ,
        ApiScope::STATISTICS_READ,
        ApiScope::INVOICES_READ,
        ApiScope::TOURIST_TAX_READ,
        ApiScope::OPERATIONS_READ,
        ApiScope::CALENDAR_READ,
        ApiScope::SUBSIDIARIES_READ,
        ApiScope::GUESTS_READ,
        ApiScope::RESERVATIONS_WRITE,
        ApiScope::PRICES_WRITE,
        ApiScope::BANK_IMPORT_WRITE,
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
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

        // Only what the user can actually grant is a valid choice; the rest is shown greyed out
        // with its reason (see finishView()).
        $choices = [];
        foreach ($this->scopeAvailability() as ['scope' => $scope, 'reason' => $reason]) {
            if (null === $reason) {
                $choices[$scope->labelKey()] = $scope->value;
            }
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
                'choices' => $choices,
                'expanded' => true,
                'multiple' => true,
                'required' => false,
                'choice_attr' => static fn (): array => ['data-action' => 'change->api-token-form#update'],
            ])
        ;
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $children = [];
        foreach ($view['scopes']->children as $child) {
            $children[$child->vars['value']] = $child;
        }

        // The template renders the scopes grouped by the question they answer instead of as one list.
        $groups = [];
        foreach ($this->scopeAvailability() as ['scope' => $scope, 'reason' => $reason]) {
            $groups[$scope->group()->value][] = [
                'scope' => $scope,
                'field' => $children[$scope->value] ?? null,
                'reason' => $reason,
            ];
        }
        $view->vars['scope_groups'] = $groups;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'messages',
            'constraints' => [new Assert\Callback(self::validateScopes(...))],
        ]);
    }

    /**
     * @param array{kind?: ?string, scopes?: list<string>, expiresIn?: ?string}|null $data
     */
    public static function validateScopes(?array $data, ExecutionContextInterface $context): void
    {
        if ([] === array_diff(self::collectScopes($data ?? []), [ApiScope::MCP_ACCESS->value])) {
            $context->buildViolation('profile.apitokens.scopes.min')->atPath('[scopes]')->addViolation();
        }

        if (self::KIND_MCP === ($data['kind'] ?? self::KIND_API) && '' === (string) ($data['expiresIn'] ?? '')) {
            $context->buildViolation('profile.apitokens.mcp.expiry_required')->atPath('[expiresIn]')->addViolation();
        }
    }

    /**
     * The scopes stored on the token: those of the chosen ones that its kind evaluates (a REST
     * token never gets AI scopes and vice versa), plus mcp:access for an AI token.
     *
     * @param array{kind?: ?string, scopes?: list<string>} $data
     *
     * @return list<string>
     */
    public static function collectScopes(array $data): array
    {
        $isMcp = self::KIND_MCP === ($data['kind'] ?? self::KIND_API);

        $scopes = [];
        foreach ($data['scopes'] ?? [] as $value) {
            $scope = ApiScope::tryFrom($value);
            if (null !== $scope && ($isMcp ? $scope->isForMcp() : $scope->isForRest())) {
                $scopes[] = $scope->value;
            }
        }
        if ($isMcp) {
            $scopes[] = ApiScope::MCP_ACCESS->value;
        }

        return array_values(array_unique($scopes));
    }

    /**
     * Every scope the form shows, in display order, with the reason the user cannot grant it:
     * "role" when their roles do not back it, "switch" when creating reservations is switched off
     * for all users, null when it can be chosen. AI-only scopes are left out while MCP is off.
     *
     * @return list<array{scope: ApiScope, reason: 'role'|'switch'|null}>
     */
    private function scopeAvailability(): array
    {
        $mcpActive = $this->mcpSettings->isActive();
        $grantable = ApiTokenService::grantableScopes(self::SCOPE_ORDER, $this->reachableRoles());

        $result = [];
        foreach (self::SCOPE_ORDER as $scope) {
            if (!$mcpActive && !$scope->isForRest()) {
                continue;
            }
            $reason = match (true) {
                !\in_array($scope, $grantable, true) => 'role',
                ApiScope::RESERVATIONS_WRITE === $scope && !$this->mcpSettings->isWriteAllowed() => 'switch',
                default => null,
            };
            $result[] = ['scope' => $scope, 'reason' => $reason];
        }

        return $result;
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
