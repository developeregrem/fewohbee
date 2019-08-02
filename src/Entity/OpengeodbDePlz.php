<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * OpengeodbDePlz
 *
 * @ORM\Table(name="opengeodb_de_plz", uniqueConstraints={@ORM\UniqueConstraint(name="loc_id", columns={"loc_id"})})
 * @ORM\Entity
 */
class OpengeodbDePlz
{
    /**
     * @var integer
     *
     * @ORM\Column(name="loc_id", type="integer", nullable=false)
     */
    private $locId;

    /**
     * @var float
     *
     * @ORM\Column(name="lon", type="float", precision=15, scale=13, nullable=false)
     */
    private $lon;

    /**
     * @var float
     *
     * @ORM\Column(name="lat", type="float", precision=15, scale=13, nullable=false)
     */
    private $lat;

    /**
     * @var string
     *
     * @ORM\Column(name="ort", type="string", length=30, nullable=false)
     */
    private $ort;

    /**
     * @var string
     *
     * @ORM\Column(name="plz", type="string", length=5)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private $plz;



    /**
     * Set locId
     *
     * @param integer $locId
     * @return OpengeodbDePlz
     */
    public function setLocId($locId)
    {
        $this->locId = $locId;

        return $this;
    }

    /**
     * Get locId
     *
     * @return integer 
     */
    public function getLocId()
    {
        return $this->locId;
    }

    /**
     * Set lon
     *
     * @param float $lon
     * @return OpengeodbDePlz
     */
    public function setLon($lon)
    {
        $this->lon = $lon;

        return $this;
    }

    /**
     * Get lon
     *
     * @return float 
     */
    public function getLon()
    {
        return $this->lon;
    }

    /**
     * Set lat
     *
     * @param float $lat
     * @return OpengeodbDePlz
     */
    public function setLat($lat)
    {
        $this->lat = $lat;

        return $this;
    }

    /**
     * Get lat
     *
     * @return float 
     */
    public function getLat()
    {
        return $this->lat;
    }

    /**
     * Set ort
     *
     * @param string $ort
     * @return OpengeodbDePlz
     */
    public function setOrt($ort)
    {
        $this->ort = $ort;

        return $this;
    }

    /**
     * Get ort
     *
     * @return string 
     */
    public function getOrt()
    {
        return $this->ort;
    }

    /**
     * Get plz
     *
     * @return string 
     */
    public function getPlz()
    {
        return $this->plz;
    }
}
