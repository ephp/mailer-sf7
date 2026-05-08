<?php

namespace App\Entity;

use App\Repository\ContactRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Table(name: 'contact')]
#[ORM\Entity(repositoryClass: ContactRepository::class)]
#[UniqueEntity(fields: ['email', 'mailList'], message: 'contact.error.email.unique')]
class Contact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 255)]
    private ?string $email = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $nome = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $cognome = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    private ?string $telefono = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $iscritto = true;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $bounceCount = 0;

    #[ORM\ManyToOne(targetEntity: MailList::class, inversedBy: 'contacts')]
    #[ORM\JoinColumn(name: 'mail_list_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?MailList $mailList = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
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

    public function setBounceCount(int $bounceCount): static
    {
        $this->bounceCount = $bounceCount;
        return $this;
    }

    public function incrementBounceCount(): void
    {
        ++$this->bounceCount;
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
