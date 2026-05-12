<?php

namespace App\Entity;

use App\Repository\AccountRepository;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Oi\FileBundle\Entity\Upload;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Table(name: 'account')]
#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[UniqueEntity(fields: ['ragioneSociale'], message: 'account.error.ragione_sociale.unique')]
class Account
{
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $ragioneSociale = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private ?string $emailContatto = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private ?string $mailFrom = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $mailFromName = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    private ?string $partitaIva = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $indirizzo = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $mailerDsn = null;

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

    #[ORM\ManyToOne(targetEntity: Upload::class)]
    #[ORM\JoinColumn(name: 'logo_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Upload $logo = null;

    #[ORM\Column(type: 'integer', options: ['default' => 50])]
    private int $batchSize = 50;

    #[ORM\Column(type: 'integer', options: ['default' => 30])]
    private int $sendInterval = 30;

    #[ORM\Column(length: 64, unique: true)]
    private string $apiKey;

    #[ORM\Column(type: 'integer', options: ['default' => 60])]
    private int $apiRateLimit = 60;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $privacyPolicy = null;

    #[ORM\OneToOne(targetEntity: User::class, mappedBy: 'account')]
    private ?User $user = null;

    public function __construct()
    {
        $this->apiKey = bin2hex(random_bytes(32));
    }

    #[Groups(['account:read'])]
    public function getId(): ?int
    {
        return $this->id;
    }

    #[Groups(['account:read'])]
    public function getRagioneSociale(): ?string
    {
        return $this->ragioneSociale;
    }

    public function setRagioneSociale(string $ragioneSociale): static
    {
        $this->ragioneSociale = $ragioneSociale;
        return $this;
    }

    #[Groups(['account:read'])]
    public function getEmailContatto(): ?string
    {
        return $this->emailContatto;
    }

    public function setEmailContatto(string $emailContatto): static
    {
        $this->emailContatto = $emailContatto;
        return $this;
    }

    #[Groups(['account:read'])]
    public function getMailFrom(): ?string
    {
        return $this->mailFrom;
    }

    public function setMailFrom(string $mailFrom): static
    {
        $this->mailFrom = $mailFrom;
        return $this;
    }

    #[Groups(['account:read'])]
    public function getMailFromName(): ?string
    {
        return $this->mailFromName;
    }

    public function setMailFromName(string $mailFromName): static
    {
        $this->mailFromName = $mailFromName;
        return $this;
    }

    #[Groups(['account:read'])]
    public function getPartitaIva(): ?string
    {
        return $this->partitaIva;
    }

    public function setPartitaIva(?string $partitaIva): static
    {
        $this->partitaIva = $partitaIva;
        return $this;
    }

    #[Groups(['account:read'])]
    public function getIndirizzo(): ?string
    {
        return $this->indirizzo;
    }

    public function setIndirizzo(?string $indirizzo): static
    {
        $this->indirizzo = $indirizzo;
        return $this;
    }

    #[Groups(['account:read'])]
    public function getMailerDsn(): ?string
    {
        return $this->mailerDsn;
    }

    public function setMailerDsn(?string $mailerDsn): static
    {
        $this->mailerDsn = $mailerDsn;
        return $this;
    }

    #[Groups(['account:read'])]
    public function getSmtpHost(): ?string
    {
        return $this->smtpHost;
    }

    public function setSmtpHost(?string $smtpHost): static
    {
        $this->smtpHost = $smtpHost;
        return $this;
    }

    #[Groups(['account:read'])]
    public function getSmtpPort(): ?int
    {
        return $this->smtpPort;
    }

    public function setSmtpPort(?int $smtpPort): static
    {
        $this->smtpPort = $smtpPort;
        return $this;
    }

    #[Groups(['account:read'])]
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

    #[Groups(['account:read'])]
    public function getSmtpEncryption(): ?string
    {
        return $this->smtpEncryption;
    }

    public function setSmtpEncryption(?string $smtpEncryption): static
    {
        $this->smtpEncryption = $smtpEncryption;
        return $this;
    }

    #[Groups(['account:read'])]
    public function getLogo(): ?Upload
    {
        return $this->logo;
    }

    public function setLogo(?Upload $logo): static
    {
        $this->logo = $logo;
        return $this;
    }

    #[Groups(['account:read'])]
    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    public function setBatchSize(int $batchSize): static
    {
        $this->batchSize = $batchSize;
        return $this;
    }

    #[Groups(['account:read'])]
    public function getSendInterval(): int
    {
        return $this->sendInterval;
    }

    public function setSendInterval(int $sendInterval): static
    {
        $this->sendInterval = $sendInterval;
        return $this;
    }

    #[Groups(['account:read'])]
    public function getApiRateLimit(): int
    {
        return $this->apiRateLimit;
    }

    public function setApiRateLimit(int $apiRateLimit): static
    {
        $this->apiRateLimit = $apiRateLimit;
        return $this;
    }

    #[Groups(['account:read'])]
    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function regenerateApiKey(): static
    {
        $this->apiKey = bin2hex(random_bytes(32));
        return $this;
    }

    #[Groups(['account:read'])]
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;
        return $this;
    }

    #[Groups(['account:read'])]
    public function getPrivacyPolicy(): ?string
    {
        return $this->privacyPolicy;
    }

    public function setPrivacyPolicy(?string $privacyPolicy): static
    {
        $this->privacyPolicy = $privacyPolicy;
        return $this;
    }

    #[Groups(['account:read'])]
    public function getCreatedAt(): ?\DateTime
    {
        return $this->createdAt;
    }

    #[Groups(['account:read'])]
    public function getUpdatedAt(): ?\DateTime
    {
        return $this->updatedAt;
    }

    #[Ignore]
    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getEffectiveDsn(): ?string
    {
        if ($this->mailerDsn !== null) {
            return $this->mailerDsn;
        }

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
