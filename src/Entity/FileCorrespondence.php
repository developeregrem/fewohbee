<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class FileCorrespondence extends Correspondence
{
    #[ORM\Column(type: 'string', length: 100)]
    protected ?string $fileName = null;

    /** @var resource|string|null DBAL hydrates BLOBs as streams; PHP has no native resource property type. */
    #[ORM\Column(type: 'blob', nullable: true)]
    protected $binaryPayload = null;

    /** @return $this */
    public function setFileName(string $fileName): static
    {
        $this->fileName = $fileName;

        return $this;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    /** Reads stored bytes; reading a hydrated BLOB consumes its stream from the current position. */
    public function getBinaryPayload(): ?string
    {
        if (null === $this->binaryPayload) {
            return null;
        }
        if (is_resource($this->binaryPayload)) {
            return stream_get_contents($this->binaryPayload) ?: null;
        }

        return $this->binaryPayload;
    }

    /** @return $this */
    public function setBinaryPayload(?string $binaryPayload): static
    {
        $this->binaryPayload = $binaryPayload;

        return $this;
    }
}
