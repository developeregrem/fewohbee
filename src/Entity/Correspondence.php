<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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
    protected ?int $id = null;
    #[ORM\Column(type: 'string', length: 100)]
    protected ?string $name = null;
    #[ORM\Column(type: 'text')]
    protected ?string $text = null;
    #[ORM\Column(name: 'created', type: 'datetime')]
    protected ?\DateTime $created = null;
    #[ORM\ManyToOne(targetEntity: Template::class, inversedBy: 'correspondences')]
    protected ?Template $template = null;
    #[ORM\ManyToOne(targetEntity: Reservation::class, inversedBy: 'correspondences')]
    protected ?Reservation $reservation = null;
    /** @var Collection<int, Correspondence> */
    #[ORM\ManyToMany(targetEntity: Correspondence::class, inversedBy: 'parents')]
    protected Collection $children;
    /** @var Collection<int, Correspondence> */
    #[ORM\ManyToMany(targetEntity: Correspondence::class, mappedBy: 'children')]
    protected Collection $parents;

    public function __construct()
    {
        $this->created = new \DateTime();
        $this->children = new ArrayCollection();
        $this->parents = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** @return $this */
    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /** @return $this */
    public function setText(string $text): static
    {
        $this->text = $text;

        return $this;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    /** @return $this */
    public function setCreated(\DateTime $created): static
    {
        $this->created = $created;

        return $this;
    }

    public function getCreated(): \DateTime
    {
        return $this->created;
    }

    /** @return $this */
    public function setTemplate(?Template $template = null): static
    {
        $this->template = $template;

        return $this;
    }

    public function getTemplate(): ?Template
    {
        return $this->template;
    }

    /** @return $this */
    public function setReservation(?Reservation $reservation = null): static
    {
        $this->reservation = $reservation;

        return $this;
    }

    public function getReservation(): ?Reservation
    {
        return $this->reservation;
    }

    /** @return $this */
    public function addChild(Correspondence $child): static
    {
        $this->children[] = $child;

        return $this;
    }

    public function removeChild(Correspondence $child): void
    {
        $this->children->removeElement($child);
    }

    /** @return Collection<int, Correspondence> */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    /** @return $this */
    public function addParent(Correspondence $parent): static
    {
        $this->parents[] = $parent;

        return $this;
    }

    public function removeParent(Correspondence $parent): void
    {
        $this->parents->removeElement($parent);
    }

    /** @return Collection<int, Correspondence> */
    public function getParents(): Collection
    {
        return $this->parents;
    }
}
