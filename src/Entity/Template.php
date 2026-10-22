<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\PaymentMeansCode;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: 'App\Repository\TemplateRepository')]
#[ORM\Table(name: 'templates')]
class Template
{
    /** @var int|'new'|null */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int|string|null $id = null;
    #[ORM\Column(type: 'string', length: 100)]
    private ?string $name = null;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $subject = null;
    #[ORM\Column(type: 'text')]
    private ?string $text = null;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $params = null;
    #[ORM\Column(type: 'boolean', nullable: false)]
    private bool $isDefault;
    #[ORM\Column(type: 'boolean', nullable: false, options: ['default' => false])]
    private bool $hidden = false;
    /**
     * Invoice templates only: when set, invoices with this payment means are created with this
     * template instead of the default one.
     */
    #[ORM\Column(nullable: true, enumType: PaymentMeansCode::class)]
    private ?PaymentMeansCode $paymentMeans = null;
    #[ORM\ManyToOne(targetEntity: TemplateType::class, inversedBy: 'templates')]
    private ?TemplateType $templateType = null;
    /** @var Collection<int, Correspondence> */
    #[ORM\OneToMany(targetEntity: Correspondence::class, mappedBy: 'template')]
    private Collection $correspondences;

    /**
     * Language of a language variant (a guest locale such as "en"); null for a template in the
     * installation language. A template is always rendered in its language (TemplatesService).
     */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $locale = null;

    /**
     * The template this one translates. Mails to a guest pick the variant in the guest's
     * language (SendTemplateEmailAction); elsewhere a variant is an ordinary template.
     */
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'variants')]
    #[ORM\JoinColumn(name: 'base_template_id', nullable: true)]
    private ?Template $baseTemplate = null;

    /** @var Collection<int, Template> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'baseTemplate')]
    #[ORM\OrderBy(['locale' => \SortDirection::Ascending])]
    private Collection $variants;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->correspondences = new ArrayCollection();
        $this->variants = new ArrayCollection();
        $this->isDefault = false;
    }

    /**
     * Set id.
     *
     * @param int|'new' $id Database ID, or the UI marker for an unpersisted create form
     *
     * @return $this
     */
    public function setId(int|string $id): static
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Get id.
     *
     * @return int|'new'|null
     */
    public function getId(): int|string|null
    {
        return $this->id;
    }

    /**
     * Set name.
     *
     * @return $this
     */
    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get name.
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Set subject.
     *
     * @return $this
     */
    public function setSubject(?string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * Get subject.
     */
    public function getSubject(): ?string
    {
        return $this->subject;
    }

    /**
     * Set text.
     *
     * @return $this
     */
    public function setText(string $text): static
    {
        $this->text = $text;

        return $this;
    }

    /**
     * Get text.
     */
    public function getText(): ?string
    {
        return $this->text;
    }

    /**
     * Set templateType.
     *
     * @return $this
     */
    public function setTemplateType(?TemplateType $templateType = null): static
    {
        $this->templateType = $templateType;

        return $this;
    }

    /**
     * Get templateType.
     */
    public function getTemplateType(): ?TemplateType
    {
        return $this->templateType;
    }

    /**
     * Add correspondence.
     *
     * @return $this
     */
    public function addCorrespondence(Correspondence $correspondence): static
    {
        $this->correspondences[] = $correspondence;

        return $this;
    }

    /**
     * Remove correspondence.
     */
    public function removeCorrespondence(Correspondence $correspondence): void
    {
        $this->correspondences->removeElement($correspondence);
    }

    /**
     * Get correspondences.
     *
     * @return Collection<int, Correspondence>
     */
    public function getCorrespondences(): ArrayCollection|Collection
    {
        return $this->correspondences;
    }

    /**
     * Set params.
     *
     * @return $this
     */
    public function setParams(string $params): static
    {
        $this->params = $params;

        return $this;
    }

    /**
     * Get params.
     */
    public function getParams(): ?string
    {
        return $this->params;
    }

    /**
     * Set isDefault.
     *
     *
     * @return $this
     */
    public function setIsDefault(bool $isDefault): static
    {
        $this->isDefault = $isDefault;

        return $this;
    }

    /**
     * Get isDefault.
     */
    public function getIsDefault(): bool
    {
        return $this->isDefault;
    }

    public function setHidden(bool $hidden): static
    {
        $this->hidden = $hidden;

        return $this;
    }

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function getPaymentMeans(): ?PaymentMeansCode
    {
        return $this->paymentMeans;
    }

    public function setPaymentMeans(?PaymentMeansCode $paymentMeans): static
    {
        $this->paymentMeans = $paymentMeans;

        return $this;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getBaseTemplate(): ?Template
    {
        return $this->baseTemplate;
    }

    public function setBaseTemplate(?Template $baseTemplate): static
    {
        $this->baseTemplate = $baseTemplate;

        return $this;
    }

    /** @return Collection<int, Template> */
    public function getVariants(): Collection
    {
        return $this->variants;
    }

    /** The variant of this template in $locale, or null when there is none. */
    public function getVariant(string $locale): ?Template
    {
        return $this->variants->findFirst(static fn (int $key, Template $variant): bool => $variant->getLocale() === $locale);
    }

    /**
     * The template to use for a guest speaking $locale: the variant in that language of this
     * template (or of the template it translates), else this template itself.
     */
    public function variantFor(?string $locale): Template
    {
        if (null === $locale) {
            return $this;
        }

        return ($this->baseTemplate ?? $this)->getVariant($locale) ?? $this;
    }

    public function isVariant(): bool
    {
        return null !== $this->baseTemplate;
    }
}
