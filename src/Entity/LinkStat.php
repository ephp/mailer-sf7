<?php

namespace App\Entity;

use App\Repository\LinkStatRepository;
use Doctrine\ORM\Mapping as ORM;
use Oi\MailflowBundle\Entity\BaseLinkStat;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Table(name: 'link_stat')]
#[ORM\Entity(repositoryClass: LinkStatRepository::class)]
class LinkStat extends BaseLinkStat
{
    #[ORM\ManyToOne(targetEntity: CampaignEmail::class)]
    #[ORM\JoinColumn(name: 'campaign_email_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?CampaignEmail $campaignEmail = null;

    #[Ignore]
    public function getCampaignEmail(): ?CampaignEmail
    {
        return $this->campaignEmail;
    }

    public function setCampaignEmail(?CampaignEmail $campaignEmail): static
    {
        $this->campaignEmail = $campaignEmail;
        return $this;
    }

    public function getCampaignEmailId(): ?int
    {
        return $this->campaignEmail?->getId();
    }
}
