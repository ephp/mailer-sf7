<?php

namespace App\EventSubscriber;

use App\Entity\CampaignEmail;
use App\Message\SendCampaignEmailMessage;
use App\Service\CampaignCompletionNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

class CampaignCompletionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CampaignCompletionNotifier $notifier,
        private readonly LoggerInterface $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [WorkerMessageHandledEvent::class => 'onMessageHandled'];
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof SendCampaignEmailMessage) {
            return;
        }

        try {
            $campaignEmail = $this->em->find(CampaignEmail::class, $message->getCampaignEmailId());
            if ($campaignEmail === null) {
                return;
            }

            $campaign = $campaignEmail->getCampaign();
            if ($campaign === null) {
                return;
            }

            $this->notifier->notifyIfComplete($campaign);
        } catch (\Throwable $e) {
            $this->logger->warning('CampaignCompletionSubscriber: error checking completion', [
                'campaignEmailId' => $message->getCampaignEmailId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
