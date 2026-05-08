<?php

namespace App\Entity;

use App\Repository\CampaignEmailRepository;
use Doctrine\ORM\Mapping as ORM;
use Ephp\MailflowBundle\Entity\BaseCampaignEmail;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Table(name: 'campaign_email')]
#[ORM\Index(columns: ['status'], name: 'idx_campaign_email_status')]
#[ORM\Index(columns: ['campaign_id'], name: 'idx_campaign_email_campaign_id')]
#[ORM\Index(columns: ['tracking_open_id'], name: 'idx_campaign_email_tracking_open_id')]
#[ORM\Index(columns: ['unsubscribe_token'], name: 'idx_campaign_email_unsubscribe_token')]
#[ORM\Entity(repositoryClass: CampaignEmailRepository::class)]
class CampaignEmail extends BaseCampaignEmail
{
    #[ORM\Column(type: 'guid', unique: true)]
    private string $unsubscribeToken;

    #[ORM\ManyToOne(targetEntity: Campaign::class)]
    #[ORM\JoinColumn(name: 'campaign_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Campaign $campaign = null;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(name: 'contact_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Contact $contact = null;

    #[ORM\ManyToOne(targetEntity: MailList::class)]
    #[ORM\JoinColumn(name: 'mail_list_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?MailList $mailList = null;

    public function __construct()
    {
        $this->unsubscribeToken = self::generateUuidV4();
    }

    public function getUnsubscribeToken(): string
    {
        return $this->unsubscribeToken;
    }

    #[Ignore]
    public function getCampaign(): ?Campaign
    {
        return $this->campaign;
    }

    public function setCampaign(?Campaign $campaign): static
    {
        $this->campaign = $campaign;
        return $this;
    }

    public function getCampaignId(): ?int
    {
        return $this->campaign?->getId();
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

    private static function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
