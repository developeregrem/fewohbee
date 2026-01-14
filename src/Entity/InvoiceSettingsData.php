<?php

namespace App\Entity;

use App\Repository\InvoiceSettingsDataRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvoiceSettingsDataRepository::class)]
class InvoiceSettingsData
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $companyName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $taxNumber = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $vatID = null;

    #[ORM\Column(length: 100)]
    private ?string $contactName = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $contactDepartment = null;

    #[ORM\Column(length: 50)]
    private ?string $contactPhone = null;

    #[ORM\Column(length: 60)]
    private ?string $contactMail = null;

    #[ORM\Column(length: 60)]
    private ?string $companyInvoiceMail = null;

    #[ORM\Column(length: 100)]
    private ?string $companyAddress = null;

    #[ORM\Column(length: 10)]
    private ?string $companyPostCode = null;

    #[ORM\Column(length: 45)]
    private ?string $companyCity = null;

    #[ORM\Column(length: 2)]
    private ?string $companyCountry = null;

    #[ORM\Column(length: 50)]
    private ?string $accountIBAN = null;

    #[ORM\Column(length: 100)]
    private ?string $accountName = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $accountBIC = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $paymentTerms = null;

    #[ORM\Column]
    private ?bool $isActive = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $paymentDueDays = null;

    #[ORM\Column(length: 50, options: ['default' => 'xrechnung'])]
    private ?string $einvoiceProfile = 'xrechnung';

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $creditorReference = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompanyName(): ?string
    {
        return $this->companyName;
    }

    public function setCompanyName(string $companyName): static
    {
        $this->companyName = $companyName;

        return $this;
    }

    public function getTaxNumber(): ?string
    {
        return $this->taxNumber;
    }

    public function setTaxNumber(?string $taxNumber): static
    {
        $this->taxNumber = $taxNumber;

        return $this;
    }

    public function getVatID(): ?string
    {
        return $this->vatID;
    }

    public function setVatID(?string $vatID): static
    {
        $this->vatID = $vatID;

        return $this;
    }

    public function getContactName(): ?string
    {
        return $this->contactName;
    }

    public function setContactName(string $contactName): static
    {
        $this->contactName = $contactName;

        return $this;
    }

    public function getContactDepartment(): ?string
    {
        return $this->contactDepartment;
    }

    public function setContactDepartment(?string $contactDepartment): static
    {
        $this->contactDepartment = $contactDepartment;

        return $this;
    }

    public function getContactPhone(): ?string
    {
        return $this->contactPhone;
    }

    public function setContactPhone(string $contactPhone): static
    {
        $this->contactPhone = $contactPhone;

        return $this;
    }

    public function getContactMail(): ?string
    {
        return $this->contactMail;
    }

    public function setContactMail(string $contactMail): static
    {
        $this->contactMail = $contactMail;

        return $this;
    }

    public function getCompanyInvoiceMail(): ?string
    {
        return $this->companyInvoiceMail;
    }

    public function setCompanyInvoiceMail(string $companyInvoiceMail): static
    {
        $this->companyInvoiceMail = $companyInvoiceMail;

        return $this;
    }

    public function getCompanyAddress(): ?string
    {
        return $this->companyAddress;
    }

    public function setCompanyAddress(string $companyAddress): static
    {
        $this->companyAddress = $companyAddress;

        return $this;
    }

    public function getCompanyPostCode(): ?string
    {
        return $this->companyPostCode;
    }

    public function setCompanyPostCode(string $companyPostCode): static
    {
        $this->companyPostCode = $companyPostCode;

        return $this;
    }

    public function getCompanyCity(): ?string
    {
        return $this->companyCity;
    }

    public function setCompanyCity(string $companyCity): static
    {
        $this->companyCity = $companyCity;

        return $this;
    }

    public function getCompanyCountry(): ?string
    {
        return $this->companyCountry;
    }

    public function setCompanyCountry(string $companyCountry): static
    {
        $this->companyCountry = $companyCountry;

        return $this;
    }

    public function getAccountIBAN(): ?string
    {
        return $this->accountIBAN;
    }

    public function setAccountIBAN(string $accountIBAN): static
    {
        $this->accountIBAN = $accountIBAN;

        return $this;
    }

    public function getAccountName(): ?string
    {
        return $this->accountName;
    }

    public function setAccountName(string $accountName): static
    {
        $this->accountName = $accountName;

        return $this;
    }

    public function getAccountBIC(): ?string
    {
        return $this->accountBIC;
    }

    public function setAccountBIC(?string $accountBIC): static
    {
        $this->accountBIC = $accountBIC;

        return $this;
    }

    public function getPaymentTerms(): ?string
    {
        return $this->paymentTerms;
    }

    public function setPaymentTerms(?string $paymentTerms): static
    {
        $this->paymentTerms = $paymentTerms;

        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getPaymentDueDays(): ?int
    {
        return $this->paymentDueDays;
    }

    public function setPaymentDueDays(?int $paymentDueDays): static
    {
        $this->paymentDueDays = $paymentDueDays;

        return $this;
    }

    public function getEinvoiceProfile(): ?string
    {
        return $this->einvoiceProfile;
    }

    public function setEinvoiceProfile(string $einvoiceProfile): static
    {
        $this->einvoiceProfile = $einvoiceProfile;

        return $this;
    }

    public function getCreditorReference(): ?string
    {
        return $this->creditorReference;
    }

    public function setCreditorReference(?string $creditorReference): static
    {
        $this->creditorReference = $creditorReference;

        return $this;
    }
}
