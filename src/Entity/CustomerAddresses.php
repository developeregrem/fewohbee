<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'customer_addresses')]
class CustomerAddresses
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;
    #[ORM\Column(type: 'string', length: 50)]
    private $type;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private $company;
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private $address;
    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private $zip;
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private $city;
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private $country;
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private $phone;
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private $fax;
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private $mobile_phone;
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private $email;
    #[ORM\ManyToMany(targetEntity: 'Customer', mappedBy: 'customerAddresses')]
    private $customers;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->customers = new ArrayCollection();
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
     * Set type.
     *
     * @param string $type
     *
     * @return CustomerAddresses
     */
    public function setType($type)
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Get type.
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Set company.
     *
     * @param string $company
     *
     * @return CustomerAddresses
     */
    public function setCompany($company)
    {
        $this->company = $company;

        return $this;
    }

    /**
     * Get company.
     *
     * @return string
     */
    public function getCompany()
    {
        return $this->company;
    }

    /**
     * Set address.
     *
     * @param string $address
     *
     * @return CustomerAddresses
     */
    public function setAddress($address)
    {
        $this->address = $address;

        return $this;
    }

    /**
     * Get address.
     *
     * @return string
     */
    public function getAddress()
    {
        return $this->address;
    }

    /**
     * Set zip.
     *
     * @param string $zip
     *
     * @return CustomerAddresses
     */
    public function setZip($zip)
    {
        $this->zip = $zip;

        return $this;
    }

    /**
     * Get zip.
     *
     * @return string
     */
    public function getZip()
    {
        return $this->zip;
    }

    /**
     * Set city.
     *
     * @param string $city
     *
     * @return CustomerAddresses
     */
    public function setCity($city)
    {
        $this->city = $city;

        return $this;
    }

    /**
     * Get city.
     *
     * @return string
     */
    public function getCity()
    {
        return $this->city;
    }

    /**
     * Set country.
     *
     * @param string $country
     *
     * @return CustomerAddresses
     */
    public function setCountry($country)
    {
        $this->country = $country;

        return $this;
    }

    /**
     * Get country.
     *
     * @return string
     */
    public function getCountry()
    {
        return $this->country;
    }

    /**
     * Set phone.
     *
     * @param string $phone
     *
     * @return CustomerAddresses
     */
    public function setPhone($phone)
    {
        $this->phone = $phone;

        return $this;
    }

    /**
     * Get phone.
     *
     * @return string
     */
    public function getPhone()
    {
        return $this->phone;
    }

    /**
     * Set fax.
     *
     * @param string $fax
     *
     * @return CustomerAddresses
     */
    public function setFax($fax)
    {
        $this->fax = $fax;

        return $this;
    }

    /**
     * Get fax.
     *
     * @return string
     */
    public function getFax()
    {
        return $this->fax;
    }

    /**
     * Set mobilePhone.
     *
     * @param string $mobilePhone
     *
     * @return CustomerAddresses
     */
    public function setMobilePhone($mobilePhone)
    {
        $this->mobile_phone = $mobilePhone;

        return $this;
    }

    /**
     * Get mobilePhone.
     *
     * @return string
     */
    public function getMobilePhone()
    {
        return $this->mobile_phone;
    }

    /**
     * Set email.
     *
     * @param string $email
     *
     * @return CustomerAddresses
     */
    public function setEmail($email)
    {
        $this->email = $email;

        return $this;
    }

    /**
     * Get email.
     *
     * @return string
     */
    public function getEmail()
    {
        return $this->email;
    }

    /**
     * Add customer.
     *
     * @return CustomerAddresses
     */
    public function addCustomer(Customer $customer)
    {
        $this->customers[] = $customer;

        return $this;
    }

    /**
     * Remove customer.
     */
    public function removeCustomer(Customer $customer): void
    {
        $this->customers->removeElement($customer);
    }

    /**
     * Get customers.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getCustomers()
    {
        return $this->customers;
    }
}
