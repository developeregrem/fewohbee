<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
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

    public function __construct()
    {
        $this->appartments = new ArrayCollection();
    }

    /**
     * Set id.
     *
     * @param int $id
     *
     * @return Subsidiary
     */
    public function setId($id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getAppartments(): ArrayCollection
    {
        return $this->appartments;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function setAppartments($appartments): void
    {
        $this->appartments = $appartments;
    }

    /**
     * Add appartments.
     *
     * @param Appartment $appartments
     *
     * @return Subsidiary
     */
    public function addAppartment(Appartment $appartments)
    {
        $this->appartments[] = $appartments;

        return $this;
    }

    /**
     * Remove appartments.
     *
     * @param Appartment $appartments
     */
    public function removeAppartment(Appartment $appartments): void
    {
        $this->appartments->removeElement($appartments);
    }
}
