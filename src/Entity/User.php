<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uniq_users_oidc_identity', columns: ['oidc_issuer', 'oidc_subject'])]
#[ORM\Entity(repositoryClass: UserRepository::class)]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;
    #[ORM\Column(type: 'string', length: 180, unique: true)]
    private $username;
    #[ORM\ManyToMany(targetEntity: Role::class, inversedBy: 'users')]
    #[ORM\JoinTable(name: 'user_roles')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'role_id', referencedColumnName: 'id')]
    private Collection $roleEntities;
    /**
     * @var string The hashed password
     */
    #[ORM\Column(type: 'string')]
    private $password;
    #[ORM\Column(type: 'string', length: 45)]
    private $firstname;
    #[ORM\Column(type: 'string', length: 45)]
    private $lastname;
    #[ORM\Column(type: 'string', length: 100)]
    private $email;
    #[ORM\Column(type: 'string', length: 10, options: ['default' => 'light'])]
    private $themePreference = 'light';
    /**
     * Last application version whose release notes this user has seen.
     * Null means "never announced" — the notes for the current version are shown once.
     */
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $lastSeenVersion = null;
    #[ORM\Column(type: 'datetime', nullable: true)]
    private $lastAction;
    #[ORM\Column(type: 'boolean')]
    private $active;
    /**
     * Issuer URL of the identity provider this account is bound to, once the
     * user has signed in via OIDC at least once. Null for accounts that have
     * never used single sign-on.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $oidcIssuer = null;
    /**
     * The "sub" claim from the identity provider — stable per user and issuer,
     * and the only identifier trusted after the initial linking. Matching by
     * e-mail happens exactly once; from then on this binding decides.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $oidcSubject = null;
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $oidcLinkedAt = null;

    public function __construct()
    {
        $this->roleEntities = new ArrayCollection();
    }

    public function getId()
    {
        return $this->id;
    }

    public function setId($id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->username;
    }

    public function getUsername(): string
    {
        return (string) $this->username;
    }

    public function setUsername(string $username): self
    {
        $this->username = $username;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = [];
        foreach ($this->roleEntities as $role) {
            $roles[] = $role->getRole();
        }

        return array_values(array_unique($roles));
    }

    /**
     * @see UserInterface
     */
    public function getPassword(): string
    {
        return (string) $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }

    public function getFirstname(): ?string
    {
        return $this->firstname;
    }

    public function setFirstname(string $firstname): self
    {
        $this->firstname = $firstname;

        return $this;
    }

    public function getLastname(): ?string
    {
        return $this->lastname;
    }

    public function setLastname(string $lastname): self
    {
        $this->lastname = $lastname;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getThemePreference(): string
    {
        return $this->themePreference;
    }

    public function setThemePreference(string $themePreference): self
    {
        $this->themePreference = $themePreference;

        return $this;
    }

    public function getLastSeenVersion(): ?string
    {
        return $this->lastSeenVersion;
    }

    public function setLastSeenVersion(?string $lastSeenVersion): self
    {
        $this->lastSeenVersion = $lastSeenVersion;

        return $this;
    }

    public function getLastAction(): ?\DateTime
    {
        return $this->lastAction;
    }

    public function setLastAction(?\DateTime $lastAction): self
    {
        $this->lastAction = $lastAction;

        return $this;
    }

    public function getActive(): ?bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    /**
     * @return Collection<int, Role>
     */
    public function getRoleEntities(): Collection
    {
        return $this->roleEntities;
    }

    public function addRole(Role $role): self
    {
        if (!$this->roleEntities->contains($role)) {
            $this->roleEntities->add($role);
        }

        return $this;
    }

    public function removeRole(Role $role): self
    {
        $this->roleEntities->removeElement($role);

        return $this;
    }

    public function setRole(?Role $role): self
    {
        $this->roleEntities->clear();
        if (null !== $role) {
            $this->addRole($role);
        }

        return $this;
    }

    /**
     * @param iterable<Role> $roles
     */
    public function setRoleEntities(iterable $roles): self
    {
        $this->roleEntities->clear();
        foreach ($roles as $role) {
            if ($role instanceof Role) {
                $this->addRole($role);
            }
        }

        return $this;
    }

    public function getOidcIssuer(): ?string
    {
        return $this->oidcIssuer;
    }

    public function getOidcSubject(): ?string
    {
        return $this->oidcSubject;
    }

    public function getOidcLinkedAt(): ?\DateTimeImmutable
    {
        return $this->oidcLinkedAt;
    }

    public function isLinkedToOidc(): bool
    {
        return null !== $this->oidcIssuer && null !== $this->oidcSubject;
    }

    /**
     * Bind this account to an identity provider subject. Called once, on the
     * first successful single sign-on; afterwards the binding is what identifies
     * the user, so it must never be overwritten silently — callers check
     * isLinkedToOidc() first and refuse a conflicting subject.
     */
    public function linkOidcIdentity(string $issuer, string $subject): self
    {
        $this->oidcIssuer = $issuer;
        $this->oidcSubject = $subject;
        $this->oidcLinkedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Drop the identity provider binding so the account can be linked to a
     * different subject on the next single sign-on (staff turnover, a rebuilt
     * identity provider, a mistaken first link).
     */
    public function unlinkOidcIdentity(): self
    {
        $this->oidcIssuer = null;
        $this->oidcSubject = null;
        $this->oidcLinkedAt = null;

        return $this;
    }
}
