<?php
namespace App\Entity;

use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Table(name="users",uniqueConstraints={@ORM\UniqueConstraint(name="u_username", columns={"username"})})
 * @ORM\Entity(repositoryClass="App\Repository\UserRepository")
 **/
class User implements UserInterface, \Serializable, EquatableInterface
{
    /** @ORM\Id @ORM\Column(type="integer") @ORM\GeneratedValue * */
    private $id;

    /** @ORM\Column(type="string", length=45)
     * **/
    private $username;

    /** @ORM\Column(type="string", length=45)
     * **/
    private $firstname;

    /** @ORM\Column(type="string", length=45)
     * **/
    private $lastname;

    /** @ORM\Column(type="string", length=100)
     * **/
    private $email;

    /** @ORM\Column(type="string", length=200)
     * **/
    private $password;

    /**
     * @ORM\ManyToOne(targetEntity="Role", inversedBy="users")
     * **/
    private $role;

    /** @ORM\Column(name="last_action", type="datetime", nullable=true) * */
    private $last_action;

    /** @ORM\Column(type="boolean") * */
    private $active;

    /**
     * @var string
     *
     * @ORM\Column(name="salt", type="string", length=40, nullable=true)
     */
    private $salt;

    /**
     * Konstruktor
     */
    public function __construct()
    {
		// not uses because of bcrypt handles this internaly
        //$this->salt = md5(uniqid(null, true));
    }

    public function getId()
    {
        return $this->id;
    }

    public function setUsername($username)
    {
        $this->username = $username;
    }

    public function getFirstname()
    {
        return $this->firstname;
    }

    public function getLastname()
    {
        return $this->lastname;
    }

    public function getEmail()
    {
        return $this->email;
    }

    public function getLastAction()
    {
        return $this->last_action;
    }

    public function getActive()
    {
        return $this->active;
    }

    public function setFirstname($firstname)
    {
        $this->firstname = $firstname;
    }

    public function setLastname($lastname)
    {
        $this->lastname = $lastname;
    }

    public function setEmail($email)
    {
        $this->email = $email;
    }

    public function setPassword($password)
    {
        $this->password = $password;
    }

    public function setLastAction($last_action)
    {
        $this->last_action = $last_action;
    }

    public function setActive($active)
    {
        $this->active = $active;
    }

    public function setSalt($salt)
    {
        $this->salt = $salt;
    }

    public function getRole()
    {

        return $this->role->getName();
    }

    public function setRole($role)
    {
        $this->role = $role;
    }

    // Interface methods
    public function getRoles()
    {
        return array($this->role->getRole());
    }

    public function getUsername()
    {
        return $this->username;
    }

    public function getPassword()
    {
        return $this->password;
    }

    public function getSalt()
    {
        return null;
    }

    public function eraseCredentials()
    {
    }
	
	public function isEqualTo(UserInterface $user)
    {
        if ($this->password !== $user->getPassword()) {
            return false;
        }

        if ($this->salt !== $user->getSalt()) {
            return false;
        }

        if ($this->username !== $user->getUsername()) {
            return false;
        }

        return true;
    }

    // Serialize interface
    /**
     * @ORM\see \Serializable::serialize()
     */
    public function serialize()
    {
        return serialize(array(
            $this->id,
			$this->username,
            $this->password,

        ));
    }

    /**
     * @ORM\see \Serializable::unserialize()
     */
    public function unserialize($serialized)
    {
        list (
            $this->id,
			$this->username,
            $this->password,

            ) = unserialize($serialized, ['allowed_classes' => false]);
    }
}
