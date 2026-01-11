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
    #[ORM\Column(type: 'datetime', nullable: true)]
    private $lastAction;
    #[ORM\Column(type: 'boolean')]
    private $active;

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
}
