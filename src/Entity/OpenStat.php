<?php

namespace App\Entity;

use App\Repository\OpenStatRepository;
use Doctrine\ORM\Mapping as ORM;
use Ephp\MailflowBundle\Entity\BaseSendStat;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Table(name: 'open_stat')]
#[ORM\Entity(repositoryClass: OpenStatRepository::class)]
class OpenStat extends BaseSendStat
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
