<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TemplateTypeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TemplateTypeRepository::class)]
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
    #[ORM\OneToMany(mappedBy: 'templateType', targetEntity: 'Template')]
    private $templates;

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
     * Add template.
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

}
