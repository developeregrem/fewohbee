<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TemplateTypeRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TemplateTypeRepository::class)]
#[ORM\Table(name: 'template_types')]
class TemplateType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;
    #[ORM\Column(type: 'string', length: 50)]
    private ?string $name = null;
    #[ORM\Column(type: 'string', length: 50)]
    private ?string $icon = null;
    /** @var \Doctrine\Common\Collections\Collection<int, Template> */
    #[ORM\OneToMany(mappedBy: 'templateType', targetEntity: Template::class)]
    private Collection $templates;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->templates = new \Doctrine\Common\Collections\ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setIcon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function addTemplate(Template $template): static
    {
        $this->templates[] = $template;

        return $this;
    }

    /**
     * Remove template.
     */
    public function removeTemplate(Template $template): void
    {
        $this->templates->removeElement($template);
    }

    /**
     * Get templates.
     *
     * @return \Doctrine\Common\Collections\Collection<int, Template>
     */
    public function getTemplates(): Collection
    {
        return $this->templates;
    }
}
