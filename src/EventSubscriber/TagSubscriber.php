<?php

namespace App\EventSubscriber;

use App\Entity\TaxonomyTerm;
use App\Entity\User;
use App\Repository\TaxonomyCategoryRepository;
use Oi\TagBundle\Event\TagPrepareEvent;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TagSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TaxonomyCategoryRepository $taxonomyCategoryRepository,
        private readonly Security $security,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // The bundle dispatches with the string event name, not the FQCN.
        return [TagPrepareEvent::NAME => 'onTagPrepare'];
    }

    public function onTagPrepare(TagPrepareEvent $event): void
    {
        $tag = $event->getTag();
        if (!$tag instanceof TaxonomyTerm) {
            return;
        }

        $categoryId = $event->getRequest()->query->getInt('category_id');
        if ($categoryId <= 0) {
            throw new NotFoundHttpException('Parameter category_id missing in request');
        }

        $category = $this->taxonomyCategoryRepository->find($categoryId);

        $user = $this->security->getUser();
        $accountId = $user instanceof User ? $user->getAccount()?->getId() : null;

        // Multi-tenant guard: pretend the category does not exist when it
        // belongs to a different account, so we don't disclose its presence.
        if ($category === null || $category->getMailList()?->getAccountId() !== $accountId) {
            throw new NotFoundHttpException('Category not found');
        }

        $tag->setCategory($category);
    }
}
