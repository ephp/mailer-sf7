<?php

namespace App\Repository;

use App\Entity\TaxonomyCategory;
use App\Entity\TaxonomyTerm;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Oi\HelperBundle\Repository\Traits\ORMRepositoryTrait;

/**
 * @extends ServiceEntityRepository<TaxonomyTerm>
 */
class TaxonomyTermRepository extends ServiceEntityRepository
{
    use ORMRepositoryTrait {
        mdr as private mdrBase;
    }

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaxonomyTerm::class);
    }

    /**
     * Override of the trait's mdr to also filter by category (a ManyToOne
     * relation, which the base trait skips because it iterates only fieldMappings).
     */
    public function mdr($entity, array $all = []): QueryBuilder
    {
        $qb = $this->mdrBase($entity, $all);

        if ($entity instanceof TaxonomyTerm && $entity->getCategory() !== null) {
            $qb->andWhere('q.category = :category')
                ->setParameter('category', $entity->getCategory());
        }

        return $qb;
    }

    /** @return TaxonomyTerm[] */
    public function findByCategory(TaxonomyCategory $category): array
    {
        return $this->findBy(['category' => $category], ['label' => 'ASC']);
    }
}
