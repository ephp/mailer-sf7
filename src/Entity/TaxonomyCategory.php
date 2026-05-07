<?php

namespace App\Entity;

use App\Repository\TaxonomyCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Table(name: 'taxonomy_category')]
#[ORM\Entity(repositoryClass: TaxonomyCategoryRepository::class)]
class TaxonomyCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $name = null;

    #[ORM\ManyToOne(targetEntity: MailList::class)]
    #[ORM\JoinColumn(name: 'mail_list_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?MailList $mailList = null;

    #[ORM\OneToMany(targetEntity: TaxonomyTerm::class, mappedBy: 'category', cascade: ['remove'])]
    private Collection $terms;

    public function __construct()
    {
        $this->terms = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    #[Ignore]
    public function getMailList(): ?MailList
    {
        return $this->mailList;
    }

    public function setMailList(?MailList $mailList): static
    {
        $this->mailList = $mailList;
        return $this;
    }

    public function getMailListId(): ?int
    {
        return $this->mailList?->getId();
    }

    public function getTermCount(): int
    {
        return $this->terms->count();
    }

    /**
     * @return Collection<int, TaxonomyTerm>
     */
    #[Ignore]
    public function getTerms(): Collection
    {
        return $this->terms;
    }

    public function addTerm(TaxonomyTerm $term): static
    {
        if (!$this->terms->contains($term)) {
            $this->terms->add($term);
        }
        return $this;
    }
}
