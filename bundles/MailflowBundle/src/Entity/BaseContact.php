<?php

namespace Ephp\MailflowBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\MappedSuperclass]
abstract class BaseContact
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\Column]
    protected ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Email]
    protected string $email = '';

    #[ORM\Column(length: 100, nullable: true)]
    protected ?string $nome = null;

    #[ORM\Column(length: 100, nullable: true)]
    protected ?string $cognome = null;

    #[ORM\Column(length: 20, nullable: true)]
    protected ?string $telefono = null;

    #[ORM\Column(options: ['default' => true])]
    protected bool $iscritto = true;

    #[ORM\Column(options: ['default' => 0])]
    protected int $bounceCount = 0;

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

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getNome(): ?string
    {
        return $this->nome;
    }

    public function setNome(?string $nome): static
    {
        $this->nome = $nome;
        return $this;
    }

    public function getCognome(): ?string
    {
        return $this->cognome;
    }

    public function setCognome(?string $cognome): static
    {
        $this->cognome = $cognome;
        return $this;
    }

    public function getTelefono(): ?string
    {
        return $this->telefono;
    }

    public function setTelefono(?string $telefono): static
    {
        $this->telefono = $telefono;
        return $this;
    }

    public function isIscritto(): bool
    {
        return $this->iscritto;
    }

    public function setIscritto(bool $iscritto): static
    {
        $this->iscritto = $iscritto;
        return $this;
    }

    public function getBounceCount(): int
    {
        return $this->bounceCount;
    }

    public function incrementBounceCount(): static
    {
        ++$this->bounceCount;
        return $this;
    }

    public function resetBounceCount(): static
    {
        $this->bounceCount = 0;
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

    public function getFullName(): string
    {
        return trim(sprintf('%s %s', $this->nome ?? '', $this->cognome ?? ''));
    }
}
