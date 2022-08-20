<?php

declare(strict_types=1);

namespace App\Entity;

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
    #[ORM\Column(name: 'role', type: 'string', length: 20, unique: true)]
    private $role;
    #[ORM\OneToMany(targetEntity: 'User', mappedBy: 'role')]
    private $users;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->users = new \Doctrine\Common\Collections\ArrayCollection();
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

    public function getUsers()
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

    public function setUsers($users): void
    {
        $this->users = $users;
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
    public function addUser(User $users)
    {
        $this->users[] = $users;

        return $this;
    }

    /**
     * Remove users.
     */
    public function removeUser(User $users): void
    {
        $this->users->removeElement($users);
    }
}
