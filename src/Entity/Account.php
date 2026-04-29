<?php

namespace App\Entity;

use App\Repository\AccountRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Table(name: 'account')]
#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[UniqueEntity(fields: ['ragioneSociale'], message: 'account.error.ragione_sociale.unique')]
class Account
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $ragioneSociale = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    private ?string $partitaIva = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $indirizzo = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $smtpHost = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\Range(min: 1, max: 65535)]
    private ?int $smtpPort = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $smtpUser = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $smtpPassword = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Choice(choices: ['tls', 'ssl', 'none'])]
    private ?string $smtpEncryption = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $apiKey;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\OneToMany(targetEntity: User::class, mappedBy: 'account')]
    private Collection $users;

    public function __construct()
    {
        $this->apiKey = bin2hex(random_bytes(32));
        $this->users = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRagioneSociale(): ?string
    {
        return $this->ragioneSociale;
    }

    public function setRagioneSociale(string $ragioneSociale): static
    {
        $this->ragioneSociale = $ragioneSociale;
        return $this;
    }

    public function getPartitaIva(): ?string
    {
        return $this->partitaIva;
    }

    public function setPartitaIva(?string $partitaIva): static
    {
        $this->partitaIva = $partitaIva;
        return $this;
    }

    public function getIndirizzo(): ?string
    {
        return $this->indirizzo;
    }

    public function setIndirizzo(?string $indirizzo): static
    {
        $this->indirizzo = $indirizzo;
        return $this;
    }

    public function getSmtpHost(): ?string
    {
        return $this->smtpHost;
    }

    public function setSmtpHost(?string $smtpHost): static
    {
        $this->smtpHost = $smtpHost;
        return $this;
    }

    public function getSmtpPort(): ?int
    {
        return $this->smtpPort;
    }

    public function setSmtpPort(?int $smtpPort): static
    {
        $this->smtpPort = $smtpPort;
        return $this;
    }

    public function getSmtpUser(): ?string
    {
        return $this->smtpUser;
    }

    public function setSmtpUser(?string $smtpUser): static
    {
        $this->smtpUser = $smtpUser;
        return $this;
    }

    public function getSmtpPassword(): ?string
    {
        return $this->smtpPassword;
    }

    public function setSmtpPassword(?string $smtpPassword): static
    {
        $this->smtpPassword = $smtpPassword;
        return $this;
    }

    public function getSmtpEncryption(): ?string
    {
        return $this->smtpEncryption;
    }

    public function setSmtpEncryption(?string $smtpEncryption): static
    {
        $this->smtpEncryption = $smtpEncryption;
        return $this;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function regenerateApiKey(): static
    {
        $this->apiKey = bin2hex(random_bytes(32));
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;
        return $this;
    }

    /** @return Collection<int, User> */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function getSmtpDsn(): ?string
    {
        if ($this->smtpHost === null || $this->smtpUser === null) {
            return null;
        }

        $password = $this->smtpPassword !== null ? urlencode($this->smtpPassword) : '';
        $port = $this->smtpPort ?? 587;
        $encryption = $this->smtpEncryption ?? 'tls';

        return sprintf(
            'smtp://%s:%s@%s:%d?encryption=%s',
            urlencode($this->smtpUser),
            $password,
            $this->smtpHost,
            $port,
            $encryption,
        );
    }
}
