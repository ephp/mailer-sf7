<?php

namespace App\EventSubscriber;

use App\Entity\CampaignEmail;
use App\Message\SendCampaignEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Ephp\MailflowBundle\Enum\CampaignEmailStatus;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

class SendCampaignEmailFailedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [WorkerMessageFailedEvent::class => 'onMessageFailed'];
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof SendCampaignEmailMessage) {
            return;
        }

        $campaignEmail = $this->em->find(CampaignEmail::class, $message->getCampaignEmailId());
        if ($campaignEmail === null || $campaignEmail->getStatus() === CampaignEmailStatus::Sent) {
            return;
        }

        $campaignEmail->setStatus(CampaignEmailStatus::Failed);
        $this->em->flush();
    }
}
