<?php

namespace Ephp\MailflowBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class BaseLinkStat
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\Column]
    protected ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    protected string $url = '';

    #[ORM\Column(length: 36, unique: true)]
    protected string $trackingToken = '';

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    protected \DateTimeInterface $clickedAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    protected ?\DateTimeInterface $firstClickedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    protected int $count = 0;

    public function __construct(string $url, string $trackingToken, \DateTimeInterface $clickedAt)
    {
        $this->url = $url;
        $this->trackingToken = $trackingToken;
        $this->clickedAt = $clickedAt;
        $this->firstClickedAt = $clickedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getTrackingToken(): string
    {
        return $this->trackingToken;
    }

    public function getClickedAt(): \DateTimeInterface
    {
        return $this->clickedAt;
    }

    public function getFirstClickedAt(): ?\DateTimeInterface
    {
        return $this->firstClickedAt;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function incrementCount(): static
    {
        ++$this->count;
        $this->clickedAt = new \DateTime();
        return $this;
    }
}
