<?php

namespace App\Entity;

use App\Repository\LinkClickEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Table(name: 'link_click_event')]
#[ORM\Index(columns: ['link_stat_id'], name: 'idx_link_click_event_link_stat_id')]
#[ORM\Index(columns: ['clicked_at'], name: 'idx_link_click_event_clicked_at')]
#[ORM\Entity(repositoryClass: LinkClickEventRepository::class)]
class LinkClickEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LinkStat::class)]
    #[ORM\JoinColumn(name: 'link_stat_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?LinkStat $linkStat = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $clickedAt;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $counted = true;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $skipReason = null;

    public function __construct()
    {
        $this->clickedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    #[Ignore]
    public function getLinkStat(): ?LinkStat
    {
        return $this->linkStat;
    }

    public function setLinkStat(?LinkStat $linkStat): static
    {
        $this->linkStat = $linkStat;
        return $this;
    }

    public function getClickedAt(): \DateTimeInterface
    {
        return $this->clickedAt;
    }

    public function setClickedAt(\DateTimeInterface $clickedAt): static
    {
        $this->clickedAt = $clickedAt;
        return $this;
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

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    public function isCounted(): bool
    {
        return $this->counted;
    }

    public function setCounted(bool $counted): static
    {
        $this->counted = $counted;
        return $this;
    }

    public function getSkipReason(): ?string
    {
        return $this->skipReason;
    }

    public function setSkipReason(?string $skipReason): static
    {
        $this->skipReason = $skipReason;
        return $this;
    }
}
