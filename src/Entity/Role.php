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
    private $id;
    #[ORM\Column(name: 'name', type: 'string', length: 30)]
    private $name;
    #[ORM\Column(name: 'role', type: 'string', length: 30, unique: true)]
    private $role;
    #[ORM\ManyToMany(targetEntity: User::class, mappedBy: 'roleEntities')]
    private Collection $users;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->users = new ArrayCollection();
    }

    public function getRole()
    {
        return $this->role;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getName()
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

    public function setId($id): void
    {
        $this->id = $id;
    }

    public function setName($name): void
    {
        $this->name = $name;
    }

    public function setRole($role): void
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

    public function addUser(User $user)
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
