<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SubsidiaryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SubsidiaryRepository::class)]
#[ORM\Table(name: 'objects')]
class Subsidiary
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;
    #[ORM\Column(type: 'string', length: 45)]
    private $name;
    #[ORM\Column(type: 'string', length: 255)]
    private $description;

    /**
     * Invoice number range of this branch, e.g. 'NORD-<year>-<number:4>'.
     * Null means the global default from AppSettings applies.
     */
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $invoiceNumberPattern = null;
    #[ORM\OneToMany(targetEntity: 'Appartment', mappedBy: 'object')]
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getDescription(): ?string
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

    public function getInvoiceNumberPattern(): ?string
    {
        return $this->invoiceNumberPattern;
    }

    /**
     * An empty string is stored as null so "not configured" has exactly one
     * representation and the fallback to the global pattern stays unambiguous.
     */
    public function setInvoiceNumberPattern(?string $invoiceNumberPattern): self
    {
        $invoiceNumberPattern = null === $invoiceNumberPattern ? null : trim($invoiceNumberPattern);
        $this->invoiceNumberPattern = '' === $invoiceNumberPattern ? null : $invoiceNumberPattern;

        return $this;
    }

    public function setAppartments($appartments): void
    {
        $this->appartments = $appartments;
    }

    /**
     * Add appartments.
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
     */
    public function removeAppartment(Appartment $appartments): void
    {
        $this->appartments->removeElement($appartments);
    }
}
