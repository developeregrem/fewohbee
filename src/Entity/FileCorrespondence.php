<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class FileCorrespondence extends Correspondence
{
    #[ORM\Column(type: 'string', length: 100)]
    protected $fileName;

    #[ORM\Column(type: 'blob', nullable: true)]
    protected $binaryPayload;

    /**
     * Set fileName.
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
     * Get fileName.
     *
     * @return string
     */
    public function getFileName()
    {
        return $this->fileName;
    }

    /**
     * @return string|null
     */
    public function getBinaryPayload()
    {
        if (null === $this->binaryPayload) {
            return null;
        }
        if (is_resource($this->binaryPayload)) {
            return stream_get_contents($this->binaryPayload) ?: null;
        }

        return $this->binaryPayload;
    }

    /**
     * @param string|null $binaryPayload
     *
     * @return FileCorrespondence
     */
    public function setBinaryPayload($binaryPayload)
    {
        $this->binaryPayload = $binaryPayload;

        return $this;
    }
}
