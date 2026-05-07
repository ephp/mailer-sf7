<?php

namespace App\Entity;

use App\Repository\TaxonomyTermRepository;
use Doctrine\ORM\Mapping as ORM;
use Oi\TagBundle\Entity\Interfaces\TagInterface;
use Oi\TagBundle\Entity\TagBase;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Table(name: 'taxonomy_term')]
#[ORM\Entity(repositoryClass: TaxonomyTermRepository::class)]
class TaxonomyTerm extends TagBase implements TagInterface
{
    #[ORM\ManyToOne(targetEntity: TaxonomyCategory::class, inversedBy: 'terms')]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?TaxonomyCategory $category = null;

    #[Ignore]
    public function getCategory(): ?TaxonomyCategory
    {
        return $this->category;
    }

    public function setCategory(?TaxonomyCategory $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getCategoryId(): ?int
    {
        return $this->category?->getId();
    }
}
