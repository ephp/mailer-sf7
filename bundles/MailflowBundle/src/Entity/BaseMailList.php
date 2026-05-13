<?php

namespace Ephp\MailflowBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\MappedSuperclass]
abstract class BaseMailList
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\Column]
    protected ?int $id = null;

    #[ORM\Column(length: 255)]
    protected string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $firmaHtml = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $mailerDsnOverride = null;

    #[ORM\Column(options: ['default' => false])]
    protected bool $permetti_disiscrizione = false;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    protected ?\DateTimeInterface $createdAt = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    protected ?\DateTimeInterface $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getFirmaHtml(): ?string
    {
        return $this->firmaHtml;
    }

    public function setFirmaHtml(?string $firmaHtml): static
    {
        $this->firmaHtml = $firmaHtml;
        return $this;
    }

    public function getMailerDsnOverride(): ?string
    {
        return $this->mailerDsnOverride;
    }

    public function setMailerDsnOverride(?string $mailerDsnOverride): static
    {
        $this->mailerDsnOverride = $mailerDsnOverride;
        return $this;
    }

    public function isPermetti_disiscrizione(): bool
    {
        return $this->permetti_disiscrizione;
    }

    public function setPermetti_disiscrizione(bool $permetti_disiscrizione): static
    {
        $this->permetti_disiscrizione = $permetti_disiscrizione;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }
}
