<?php
namespace App\Entity;

use App\Entity\Correspondence;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 */
class FileCorrespondence extends Correspondence 
{
    /** 
     * @ORM\Column(type="string", length=100) 
     */
    protected $fileName;

    /**
     * Set fileName
     *
     * @param string $fileName
     *
     * @return FileCorrespondence
     */
    public function setFileName($fileName)
    {
        $this->fileName = $fileName;

        return $this;
    }

    /**
     * Get fileName
     *
     * @return string
     */
    public function getFileName()
    {
        return $this->fileName;
    }
}
