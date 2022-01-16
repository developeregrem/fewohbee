<?php

namespace App\Entity;

use App\Repository\PostalCodeDataRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=PostalCodeDataRepository::class)
 * @ORM\Table(name="postal_code_data",indexes={@ORM\Index(name="search_zip_idx", columns={"country_code", "postal_code"})})
 */
class PostalCodeData
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=2)
     */
    private $countryCode;

    /**
     * @ORM\Column(type="string", length=20)
     */
    private $postalCode;

    /**
     * @ORM\Column(type="string", length=180)
     */
    private $placeName;

    /**
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    private $stateName;

    /**
     * @ORM\Column(type="string", length=20, nullable=true)
     */
    private $stateNameShort;

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
