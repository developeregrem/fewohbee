<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'roles')]
class Role
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;
    #[ORM\Column(name: 'name', type: 'string', length: 30)]
    private ?string $name = null;
    #[ORM\Column(name: 'role', type: 'string', length: 30, unique: true)]
    private ?string $role = null;
    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class, mappedBy: 'roleEntities')]
    private Collection $users;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->users = new ArrayCollection();
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @return Collection<int, User>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setRole(string $role): void
    {
        $this->role = $role;
    }

    /**
     * Add users.
     *
     * @return Role
     */
    /**
     * @param iterable<User> $users
     */
    public function setUsers(iterable $users): void
    {
        $this->users = new ArrayCollection();
        foreach ($users as $user) {
            if ($user instanceof User) {
                $this->addUser($user);
            }
        }
    }

    /** @return $this */
    public function addUser(User $user): static
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
            $user->addRole($this);
        }

        return $this;
    }

    /**
     * Remove users.
     */
    public function removeUser(User $user): void
    {
        if ($this->users->removeElement($user)) {
            $user->removeRole($this);
        }
    }
}
