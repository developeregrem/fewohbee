<?php
namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\TemplateRepository")
 * @ORM\Table(name="templates")
 **/
class Template
{
    /** @ORM\Id @ORM\Column(type="integer") @ORM\GeneratedValue * */
    private $id;

    /** @ORM\Column(type="string", length=100) * */
    private $name;

    /**
     * @ORM\Column(type="text")
     */
    private $text;
    
    /** @ORM\Column(type="string", length=255, nullable=true) * */
    private $params;
    
    /** @ORM\Column(type="boolean", nullable=false) * */
    private $isDefault;
    
    /**
     * @ORM\ManyToOne(targetEntity="TemplateType", inversedBy="templates")
     */
    private $templateType;
    
    /**
     * @ORM\OneToMany(targetEntity="Correspondence", mappedBy="template")
     */
    private $correspondences;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->correspondences = new \Doctrine\Common\Collections\ArrayCollection();
        $this->isDefault = false;
    }

    /**
     * Set id
     *
     * @param int $id
     * @return ReservationOrigin
     */
    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Get id
     *
     * @return integer 
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set name
     *
     * @param string $name
     * @return ReservationOrigin
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get name
     *
     * @return string 
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set text
     *
     * @param string $text
     *
     * @return Template
     */
    public function setText($text)
    {
        $this->text = $text;

        return $this;
    }

    /**
     * Get text
     *
     * @return string
     */
    public function getText()
    {
        return $this->text;
    }

    /**
     * Set templateType
     *
     * @param \App\Entity\TemplateType $templateType
     *
     * @return Template
     */
    public function setTemplateType(\App\Entity\TemplateType $templateType = null)
    {
        $this->templateType = $templateType;

        return $this;
    }

    /**
     * Get templateType
     *
     * @return \App\Entity\TemplateType
     */
    public function getTemplateType()
    {
        return $this->templateType;
    }
    
    /**
     * Add correspondence
     *
     * @param \App\Entity\Correspondence $correspondence
     *
     * @return TemplateType
     */
    public function addCorrespondence(\App\Entity\Correspondence $correspondence)
    {
        $this->correspondences[] = $correspondence;

        return $this;
    }

    /**
     * Remove correspondence
     *
     * @param \App\Entity\Correspondence $correspondence
     */
    public function removeCorrespondence(\App\Entity\Correspondence $correspondence)
    {
        $this->correspondences->removeElement($correspondence);
    }

    /**
     * Get correspondences
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getCorrespondences()
    {
        return $this->correspondences;
    }

    /**
     * Set params
     *
     * @param string $params
     *
     * @return Template
     */
    public function setParams($params)
    {
        $this->params = $params;

        return $this;
    }

    /**
     * Get params
     *
     * @return string
     */
    public function getParams()
    {
        return $this->params;
    }
    
    /**
     * Set isDefault
     *
     * @param boolean $isDefault
     * @return Template
     */
    public function setIsDefault($isDefault)
    {
        $this->isDefault = $isDefault;

        return $this;
    }

    /**
     * Get isDefault
     *
     * @return boolean
     */
    public function getIsDefault()
    {
        return $this->isDefault;
    }
}
