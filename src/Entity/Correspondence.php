<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'discr', type: 'string')]
#[ORM\DiscriminatorMap(['correspondence' => 'Correspondence', 'mail' => 'MailCorrespondence', 'file' => 'FileCorrespondence'])]
class Correspondence
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;
    #[ORM\Column(type: 'string', length: 100)]
    protected $name;
    #[ORM\Column(type: 'text')]
    protected $text;
    #[ORM\Column(name: 'created', type: 'datetime')]
    protected $created;
    #[ORM\ManyToOne(targetEntity: 'Template', inversedBy: 'correspondences')]
    protected $template;
    #[ORM\ManyToOne(targetEntity: 'Reservation', inversedBy: 'correspondences')]
    protected $reservation;
    #[ORM\ManyToMany(targetEntity: 'Correspondence', inversedBy: 'parents')]
    protected $children;
    #[ORM\ManyToMany(targetEntity: 'Correspondence', mappedBy: 'children')]
    protected $parents;

    public function __construct()
    {
        $this->created = new \DateTime();
        $this->children = new ArrayCollection();
        $this->parents = new ArrayCollection();
    }

    /**
     * Get id.
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set name.
     *
     * @param string $name
     *
     * @return Correspondence
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get name.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set text.
     *
     * @param string $text
     *
     * @return Correspondence
     */
    public function setText($text)
    {
        $this->text = $text;

        return $this;
    }

    /**
     * Get text.
     *
     * @return string
     */
    public function getText()
    {
        return $this->text;
    }

    /**
     * Set created.
     *
     * @param \DateTime $created
     *
     * @return Correspondence
     */
    public function setCreated($created)
    {
        $this->created = $created;

        return $this;
    }

    /**
     * Get created.
     *
     * @return \DateTime
     */
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * Set template.
     *
     * @return Correspondence
     */
    public function setTemplate(?Template $template = null)
    {
        $this->template = $template;

        return $this;
    }

    /**
     * Get template.
     *
     * @return Template
     */
    public function getTemplate()
    {
        return $this->template;
    }

    /**
     * Set reservation.
     *
     * @return Correspondence
     */
    public function setReservation(?Reservation $reservation = null)
    {
        $this->reservation = $reservation;

        return $this;
    }

    /**
     * Get reservation.
     *
     * @return Reservation
     */
    public function getReservation()
    {
        return $this->reservation;
    }

    /**
     * Add child.
     *
     * @return Correspondence
     */
    public function addChild(Correspondence $child)
    {
        $this->children[] = $child;

        return $this;
    }

    /**
     * Remove child.
     */
    public function removeChild(Correspondence $child): void
    {
        $this->children->removeElement($child);
    }

    /**
     * Get children.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getChildren()
    {
        return $this->children;
    }

    /**
     * Add parent.
     *
     * @return Correspondence
     */
    public function addParent(Correspondence $parent)
    {
        $this->parents[] = $parent;

        return $this;
    }

    /**
     * Remove parent.
     */
    public function removeParent(Correspondence $parent): void
    {
        $this->parents->removeElement($parent);
    }

    /**
     * Get parents.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getParents()
    {
        return $this->parents;
    }
}
