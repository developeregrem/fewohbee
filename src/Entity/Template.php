<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: 'App\Repository\TemplateRepository')]
#[ORM\Table(name: 'templates')]
class Template
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;
    #[ORM\Column(type: 'string', length: 100)]
    private $name;
    #[ORM\Column(type: 'text')]
    private $text;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private $params;
    #[ORM\Column(type: 'boolean', nullable: false)]
    private $isDefault;
    #[ORM\ManyToOne(targetEntity: 'TemplateType', inversedBy: 'templates')]
    private $templateType;
    #[ORM\OneToMany(targetEntity: 'Correspondence', mappedBy: 'template')]
    private $correspondences;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->correspondences = new ArrayCollection();
        $this->isDefault = false;
    }

    /**
     * Set id.
     *
     * @param int $id
     *
     * @return Template
     */
    public function setId($id): static
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Get id.
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set name.
     *
     * @return Template
     */
    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get name.
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Set text.
     *
     * @return Template
     */
    public function setText(string $text): static
    {
        $this->text = $text;

        return $this;
    }

    /**
     * Get text.
     */
    public function getText(): ?string
    {
        return $this->text;
    }

    /**
     * Set templateType.
     *
     * @return Template
     */
    public function setTemplateType(TemplateType $templateType = null): static
    {
        $this->templateType = $templateType;

        return $this;
    }

    /**
     * Get templateType.
     */
    public function getTemplateType(): ?TemplateType
    {
        return $this->templateType;
    }

    /**
     * Add correspondence.
     *
     * @return Template
     */
    public function addCorrespondence(Correspondence $correspondence): static
    {
        $this->correspondences[] = $correspondence;

        return $this;
    }

    /**
     * Remove correspondence.
     */
    public function removeCorrespondence(Correspondence $correspondence): void
    {
        $this->correspondences->removeElement($correspondence);
    }

    /**
     * Get correspondences.
     *
     * @return Collection
     */
    public function getCorrespondences(): ArrayCollection|Collection
    {
        return $this->correspondences;
    }

    /**
     * Set params.
     *
     * @return Template
     */
    public function setParams(string $params): static
    {
        $this->params = $params;

        return $this;
    }

    /**
     * Get params.
     */
    public function getParams(): ?string
    {
        return $this->params;
    }

    /**
     * Set isDefault.
     *
     * @param bool $isDefault
     *
     * @return Template
     */
    public function setIsDefault($isDefault): static
    {
        $this->isDefault = $isDefault;

        return $this;
    }

    /**
     * Get isDefault.
     */
    public function getIsDefault(): bool
    {
        return $this->isDefault;
    }
}
