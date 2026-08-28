<?php

declare(strict_types=1);

namespace App\Service\Oidc;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/**
 * Maps validated ID token claims onto a local user account.
 *
 * Accounts are never created here. An operator provisions users under
 * /settings/users and assigns their roles there; the identity provider only
 * decides *whether* someone may sign in, never *what* they may do. That keeps
 * the permission model in one place and stops everyone in a company-wide
 * directory from gaining access to the property management system.
 *
 * Matching happens exactly once. The first successful sign-in binds the
 * provider's "sub" to the account, and every later sign-in resolves through
 * that binding — so a changed e-mail address no longer breaks or, worse,
 * redirects a login.
 */
final class OidcUserResolver
{
    public function __construct(
        private readonly OidcConfiguration $config,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Whether the ID token alone carries enough to identify a user, or the
     * UserInfo endpoint still has to be consulted.
     *
     * Plenty of providers (Authelia and Okta among them) keep the ID token to a
     * minimal claim set and serve profile and e-mail claims from UserInfo, so
     * the claim we match on is simply absent after the token exchange.
     *
     * @param array<string, mixed> $claims
     */
    public function needsUserInfo(array $claims): bool
    {
        $matching = $this->config->getUserMatching();

        $value = $claims[$matching->claim()] ?? null;
        if (!is_string($value) || '' === trim($value)) {
            return true;
        }

        // The address may be there while its confirmation flag is not.
        return OidcUserMatching::Email === $matching
            && $this->config->requiresVerifiedEmail()
            && !array_key_exists('email_verified', $claims);
    }

    /**
     * @param array<string, mixed> $claims validated ID token claims
     *
     * @throws CustomUserMessageAuthenticationException when no single, eligible account matches
     */
    public function resolve(array $claims): User
    {
        // Stored verbatim: iss is part of the identity key and is compared by
        // exact string match, so it must not be reshaped on the way in.
        $issuer = is_string($claims['iss'] ?? null) ? $claims['iss'] : '';
        $subject = is_string($claims['sub'] ?? null) ? $claims['sub'] : '';

        if ('' === $issuer || '' === $subject) {
            throw new CustomUserMessageAuthenticationException('login.oidc.error.token');
        }

        $linked = $this->userRepository->findOneByOidcIdentity($issuer, $subject);
        if (null !== $linked) {
            return $this->assertActive($linked);
        }

        $user = $this->matchExistingUser($claims);
        $this->assertActive($user);

        // Someone else's binding on this account means the e-mail or username
        // now points at a different provider identity than it used to. Refuse
        // rather than silently re-point the account; an admin unlinks it first.
        if ($user->isLinkedToOidc()) {
            $this->logger->warning('OIDC login refused: account is already linked to a different subject.', [
                'user_id' => $user->getId(),
            ]);

            throw new CustomUserMessageAuthenticationException('login.oidc.error.already_linked');
        }

        $user->linkOidcIdentity($issuer, $subject);
        $this->entityManager->flush();

        // Logged at warning level on purpose: this is the one moment the binding
        // rests on a claim that OIDC does not guarantee to be unique (Core 5.7),
        // so it should be visible in an audit trail rather than buried.
        $this->logger->warning('OIDC identity linked to an existing account on first sign-in via the "{claim}" claim.', [
            'user_id' => $user->getId(),
            'claim' => $this->config->getUserMatching()->claim(),
        ]);

        return $user;
    }

    /**
     * Find the one local account this identity belongs to, by e-mail or by
     * username depending on OIDC_USER_MATCHING.
     *
     * @param array<string, mixed> $claims
     */
    private function matchExistingUser(array $claims): User
    {
        $matching = $this->config->getUserMatching();
        $value = $claims[$matching->claim()] ?? null;

        if (!is_string($value) || '' === trim($value)) {
            // Claim names only — the values are personal data and must not be logged.
            $this->logger->error('OIDC login refused: the provider sent no "{claim}" claim. Received claims: {received}.', [
                'claim' => $matching->claim(),
                'received' => implode(', ', array_keys($claims)),
            ]);

            throw new CustomUserMessageAuthenticationException('login.oidc.error.no_match');
        }
        $value = trim($value);

        if (OidcUserMatching::Email === $matching) {
            $this->assertEmailVerified($claims);

            $candidates = $this->userRepository->findByEmailAddress($value);
            if (count($candidates) > 1) {
                // users.email has no unique constraint, so this is reachable.
                // Picking one would hand the account to whoever the provider
                // happens to sort first.
                $this->logger->error('OIDC login refused: e-mail address matches more than one account.', [
                    'match_count' => count($candidates),
                ]);

                throw new CustomUserMessageAuthenticationException('login.oidc.error.ambiguous');
            }
            $user = $candidates[0] ?? null;
        } else {
            $user = $this->userRepository->findOneBy(['username' => $value]);
        }

        if (null === $user) {
            $this->logger->info('OIDC login refused: no local account matches the identity.', [
                'matching' => $matching->value,
            ]);

            throw new CustomUserMessageAuthenticationException('login.oidc.error.no_match');
        }

        return $user;
    }

    /**
     * An unverified e-mail address is attacker-controlled at many providers:
     * anyone who can set their own address to a staff member's could take over
     * that account on first sign-in.
     *
     * @param array<string, mixed> $claims
     */
    private function assertEmailVerified(array $claims): void
    {
        if (!$this->config->requiresVerifiedEmail()) {
            return;
        }

        // Providers are inconsistent here and some send the string "true".
        $verified = filter_var($claims['email_verified'] ?? null, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);
        if (true !== $verified) {
            $this->logger->warning('OIDC login refused: the identity provider did not confirm the e-mail address.');

            throw new CustomUserMessageAuthenticationException('login.oidc.error.email_unverified');
        }
    }

    /**
     * Deactivated accounts are blocked for single sign-on exactly as they are
     * for password login, where the user provider filters on active = true.
     */
    private function assertActive(User $user): User
    {
        if (true !== $user->getActive()) {
            $this->logger->info('OIDC login refused: the account is deactivated.', ['user_id' => $user->getId()]);

            throw new CustomUserMessageAuthenticationException('login.oidc.error.inactive');
        }

        return $user;
    }
}
