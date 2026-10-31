<?php

declare(strict_types=1);

namespace App\GeoEntity;

use App\Repository\PostalCodeDataRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PostalCodeDataRepository::class)]
#[ORM\Table(name: 'postal_code_data')]
#[ORM\Index(name: 'search_zip_idx', columns: ['country_code', 'postal_code'])]
class PostalCodeData
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;
    #[ORM\Column(type: 'string', length: 2)]
    private ?string $countryCode = null;
    #[ORM\Column(type: 'string', length: 20)]
    private ?string $postalCode = null;
    #[ORM\Column(type: 'string', length: 180)]
    private ?string $placeName = null;
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $stateName = null;
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $stateNameShort = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCountryCode(): ?string
    {
        return $this->countryCode;
    }

    public function setCountryCode(string $countryCode): self
    {
        $this->countryCode = $countryCode;

        return $this;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function setPostalCode(string $postalCode): self
    {
        $this->postalCode = $postalCode;

        return $this;
    }

    public function getPlaceName(): ?string
    {
        return $this->placeName;
    }

    public function setPlaceName(string $placeName): self
    {
        $this->placeName = $placeName;

        return $this;
    }

    public function getStateName(): ?string
    {
        return $this->stateName;
    }

    public function setStateName(?string $stateName): self
    {
        $this->stateName = $stateName;

        return $this;
    }

    public function getStateNameShort(): ?string
    {
        return $this->stateNameShort;
    }

    public function setStateNameShort(?string $stateNameShort): self
    {
        $this->stateNameShort = $stateNameShort;

        return $this;
    }
}
