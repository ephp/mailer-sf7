<?php

namespace Ephp\MailflowBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ephp\MailflowBundle\Enum\CampaignEmailStatus;

#[ORM\MappedSuperclass]
abstract class BaseCampaignEmail
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\Column]
    protected ?int $id = null;

    #[ORM\Column(length: 255)]
    protected string $email = '';

    #[ORM\Column(length: 36, unique: true)]
    protected string $trackingOpenId = '';

    #[ORM\Column(length: 20, enumType: CampaignEmailStatus::class)]
    protected CampaignEmailStatus $status = CampaignEmailStatus::Pending;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    protected ?\DateTimeInterface $sentAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $errorMessage = null;

    #[ORM\Column(options: ['default' => 0])]
    protected int $retryCount = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getTrackingOpenId(): string
    {
        return $this->trackingOpenId;
    }

    public function setTrackingOpenId(string $trackingOpenId): static
    {
        $this->trackingOpenId = $trackingOpenId;
        return $this;
    }

    public function getStatus(): CampaignEmailStatus
    {
        return $this->status;
    }

    public function setStatus(CampaignEmailStatus $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getSentAt(): ?\DateTimeInterface
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeInterface $sentAt): static
    {
        $this->sentAt = $sentAt;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function incrementRetryCount(): static
    {
        ++$this->retryCount;
        return $this;
    }
}
