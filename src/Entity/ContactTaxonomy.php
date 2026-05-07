<?php

namespace App\Entity;

use App\Repository\ContactTaxonomyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Table(name: 'contact_taxonomy')]
#[ORM\UniqueConstraint(name: 'contact_term_unique', columns: ['contact_id', 'term_id'])]
#[ORM\Entity(repositoryClass: ContactTaxonomyRepository::class)]
class ContactTaxonomy
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(name: 'contact_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Contact $contact = null;

    #[ORM\ManyToOne(targetEntity: TaxonomyTerm::class)]
    #[ORM\JoinColumn(name: 'term_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?TaxonomyTerm $term = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    #[Ignore]
    public function getContact(): ?Contact
    {
        return $this->contact;
    }

    public function setContact(?Contact $contact): static
    {
        $this->contact = $contact;
        return $this;
    }

    public function getContactId(): ?int
    {
        return $this->contact?->getId();
    }

    public function getTerm(): ?TaxonomyTerm
    {
        return $this->term;
    }

    public function setTerm(?TaxonomyTerm $term): static
    {
        $this->term = $term;
        return $this;
    }

    public function getTermId(): ?int
    {
        return $this->term?->getId();
    }
}
