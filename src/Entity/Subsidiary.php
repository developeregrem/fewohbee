<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity @ORM\Table(name="objects")
 **/

class Subsidiary
{
    /** @ORM\Id @ORM\Column(type="integer") @ORM\GeneratedValue * */
    private $id;

    /** @ORM\Column(type="string", length=45)
     * **/
    private $name;

    /** @ORM\Column(type="string", length=255)
     * **/
    private $description;

    /**
     * @ORM\OneToMany(targetEntity="Appartment", mappedBy="object")
     */
    private $appartments;
    
    /**
     * Set id
     *
     * @param int $id
     * @return Subsidiary
     */
    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function getAppartments()
    {
        return $this->appartments;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function setDescription($description)
    {
        $this->description = $description;
    }

    public function setAppartments($appartments)
    {
        $this->appartments = $appartments;
    }


    /**
     * Constructor
     */
    public function __construct()
    {
        $this->appartments = new \Doctrine\Common\Collections\ArrayCollection();
    }

    /**
     * Add appartments
     *
     * @param \App\Entity\Appartment $appartments
     * @return Subsidiary
     */
    public function addAppartment(\App\Entity\Appartment $appartments)
    {
        $this->appartments[] = $appartments;

        return $this;
    }

    /**
     * Remove appartments
     *
     * @param \App\Entity\Appartment $appartments
     */
    public function removeAppartment(\App\Entity\Appartment $appartments)
    {
        $this->appartments->removeElement($appartments);
    }
}
