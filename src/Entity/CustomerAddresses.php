<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'customer_addresses')]
class CustomerAddresses
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;
    #[ORM\Column(type: 'string', length: 50)]
    private ?string $type = null;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $company = null;
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $address = null;
    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $zip = null;
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $city = null;
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $country = null;
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $phone = null;
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $fax = null;
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $mobile_phone = null;
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $buyerVatId = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $buyerReference = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $customerIBAN = null;

    /** @var \Doctrine\Common\Collections\Collection<int, Customer> */
    #[ORM\ManyToMany(targetEntity: Customer::class, mappedBy: 'customerAddresses')]
    private Collection $customers;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->customers = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setCompany(?string $company): static
    {
        $this->company = $company;

        return $this;
    }

    public function getCompany(): ?string
    {
        return $this->company;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setZip(?string $zip): static
    {
        $this->zip = $zip;

        return $this;
    }

    public function getZip(): ?string
    {
        return $this->zip;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCountry(?string $country): static
    {
        $this->country = $country;

        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setFax(?string $fax): static
    {
        $this->fax = $fax;

        return $this;
    }

    public function getFax(): ?string
    {
        return $this->fax;
    }

    public function setMobilePhone(?string $mobilePhone): static
    {
        $this->mobile_phone = $mobilePhone;

        return $this;
    }

    public function getMobilePhone(): ?string
    {
        return $this->mobile_phone;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getBuyerVatId(): ?string
    {
        return $this->buyerVatId;
    }

    public function setBuyerVatId(?string $buyerVatId): static
    {
        $this->buyerVatId = $buyerVatId;

        return $this;
    }

    public function getBuyerReference(): ?string
    {
        return $this->buyerReference;
    }

    public function setBuyerReference(?string $buyerReference): static
    {
        $this->buyerReference = $buyerReference;

        return $this;
    }

    public function getCustomerIBAN(): ?string
    {
        return $this->customerIBAN;
    }

    public function setCustomerIBAN(?string $customerIBAN): static
    {
        $this->customerIBAN = $customerIBAN;

        return $this;
    }

    public function addCustomer(Customer $customer): static
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
     * @return \Doctrine\Common\Collections\Collection<int, Customer>
     */
    public function getCustomers(): Collection
    {
        return $this->customers;
    }
}
