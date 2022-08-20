<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'template_types')]
class TemplateType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;
    #[ORM\Column(type: 'string', length: 50)]
    private $name;
    #[ORM\Column(type: 'string', length: 50)]
    private $icon;
    #[ORM\Column(type: 'string', length: 150)]
    private $service;
    #[ORM\OneToMany(targetEntity: 'Template', mappedBy: 'templateType')]
    private $templates;
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private $editorTemplate;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->templates = new \Doctrine\Common\Collections\ArrayCollection();
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
     * @return TemplateType
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
     * Set icon.
     *
     * @param string $icon
     *
     * @return TemplateType
     */
    public function setIcon($icon)
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * Get icon.
     *
     * @return string
     */
    public function getIcon()
    {
        return $this->icon;
    }

    /**
     * Set service.
     *
     * @param string $service
     *
     * @return TemplateType
     */
    public function setService($service)
    {
        $this->service = $service;

        return $this;
    }

    /**
     * Get service.
     *
     * @return string
     */
    public function getService()
    {
        return $this->service;
    }

    /**
     * Add template.
     *
     * @param \App\Entity\Template $template
     *
     * @return TemplateType
     */
    public function addTemplate(Template $template)
    {
        $this->templates[] = $template;

        return $this;
    }

    /**
     * Remove template.
     *
     * @param \App\Entity\Template $template
     */
    public function removeTemplate(Template $template): void
    {
        $this->templates->removeElement($template);
    }

    /**
     * Get templates.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getTemplates()
    {
        return $this->templates;
    }

    public function getEditorTemplate(): ?string
    {
        return $this->editorTemplate;
    }

    public function setEditorTemplate(?string $editorTemplate): self
    {
        $this->editorTemplate = $editorTemplate;

        return $this;
    }
}
