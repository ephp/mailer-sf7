<?php

namespace App\Entity;

use App\Repository\UnsubscribeRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Table(name: 'unsubscribe_request')]
#[ORM\Entity(repositoryClass: UnsubscribeRequestRepository::class)]
class UnsubscribeRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CampaignEmail::class)]
    #[ORM\JoinColumn(name: 'campaign_email_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?CampaignEmail $campaignEmail = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $confirmedAt;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    public function __construct(\DateTimeInterface $confirmedAt)
    {
        $this->confirmedAt = $confirmedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

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

    public function getConfirmedAt(): \DateTimeInterface
    {
        return $this->confirmedAt;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }
}
