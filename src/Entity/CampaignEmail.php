<?php

namespace App\Entity;

use App\Repository\CampaignEmailRepository;
use Doctrine\ORM\Mapping as ORM;
use Oi\MailflowBundle\Entity\BaseCampaignEmail;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Table(name: 'campaign_email')]
#[ORM\Entity(repositoryClass: CampaignEmailRepository::class)]
class CampaignEmail extends BaseCampaignEmail
{
    #[ORM\ManyToOne(targetEntity: Campaign::class)]
    #[ORM\JoinColumn(name: 'campaign_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Campaign $campaign = null;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(name: 'contact_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Contact $contact = null;

    #[ORM\ManyToOne(targetEntity: MailList::class)]
    #[ORM\JoinColumn(name: 'mail_list_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?MailList $mailList = null;

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
}
