<?php

namespace App\Entity;

use App\Repository\MailListRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Table(name: 'mail_list')]
#[ORM\UniqueConstraint(name: 'unique_name_account', columns: ['name', 'account_id'])]
#[ORM\Entity(repositoryClass: MailListRepository::class)]
#[UniqueEntity(fields: ['name', 'account'], message: 'mail_list.error.name.unique')]
class MailList
{
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 255)]
    private ?string $name = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $firmaHtml = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $useCustomDsn = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $mailerDsnOverride = null;

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

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Assert\Email]
    private ?string $mailFrom = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $mailFromName = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $permettiDisiscrizione = true;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $unsubscribeText = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $deletedAt = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Account $account = null;

    #[ORM\OneToMany(targetEntity: Contact::class, mappedBy: 'mailList')]
    private Collection $contacts;

    public function __construct()
    {
        $this->contacts = new ArrayCollection();
    }

    #[Groups(['list:read'])]
    public function getId(): ?int
    {
        return $this->id;
    }

    #[Groups(['list:read'])]
    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    #[Groups(['list:read'])]
    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    #[Groups(['list:read'])]
    public function getFirmaHtml(): ?string
    {
        return $this->firmaHtml;
    }

    public function setFirmaHtml(?string $firmaHtml): static
    {
        $this->firmaHtml = $firmaHtml;
        return $this;
    }

    #[Groups(['list:read'])]
    public function isUseCustomDsn(): bool
    {
        return $this->useCustomDsn;
    }

    public function setUseCustomDsn(bool $useCustomDsn): static
    {
        $this->useCustomDsn = $useCustomDsn;
        return $this;
    }

    #[Groups(['list:read'])]
    public function getMailerDsnOverride(): ?string
    {
        return $this->mailerDsnOverride;
    }

    public function setMailerDsnOverride(?string $mailerDsnOverride): static
    {
        $this->mailerDsnOverride = $mailerDsnOverride;
        return $this;
    }

    #[Groups(['list:read'])]
    public function getSmtpHost(): ?string
    {
        return $this->smtpHost;
    }

    public function setSmtpHost(?string $smtpHost): static
    {
        $this->smtpHost = $smtpHost;
        return $this;
    }

    #[Groups(['list:read'])]
    public function getSmtpPort(): ?int
    {
        return $this->smtpPort;
    }

    public function setSmtpPort(?int $smtpPort): static
    {
        $this->smtpPort = $smtpPort;
        return $this;
    }

    #[Groups(['list:read'])]
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

    #[Groups(['list:read'])]
    public function getSmtpEncryption(): ?string
    {
        return $this->smtpEncryption;
    }

    public function setSmtpEncryption(?string $smtpEncryption): static
    {
        $this->smtpEncryption = $smtpEncryption;
        return $this;
    }

    #[Groups(['list:read'])]
    public function getMailFrom(): ?string
    {
        return $this->mailFrom;
    }

    public function setMailFrom(?string $mailFrom): static
    {
        $this->mailFrom = $mailFrom;
        return $this;
    }

    #[Groups(['list:read'])]
    public function getMailFromName(): ?string
    {
        return $this->mailFromName;
    }

    public function setMailFromName(?string $mailFromName): static
    {
        $this->mailFromName = $mailFromName;
        return $this;
    }

    /**
     * Returns the DSN override for this list if useCustomDsn is on, otherwise null
     * (so the caller falls back to the account DSN).
     */
    public function getEffectiveDsn(): ?string
    {
        if (!$this->useCustomDsn) {
            return null;
        }

        if ($this->mailerDsnOverride !== null && $this->mailerDsnOverride !== '') {
            return $this->mailerDsnOverride;
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

    /**
     * Returns the From address for this list if useCustomDsn is on and one is set,
     * otherwise the account default.
     */
    public function getEffectiveMailFrom(): ?string
    {
        if ($this->useCustomDsn && $this->mailFrom !== null && $this->mailFrom !== '') {
            return $this->mailFrom;
        }
        return $this->account?->getMailFrom();
    }

    public function getEffectiveMailFromName(): ?string
    {
        if ($this->useCustomDsn && $this->mailFromName !== null && $this->mailFromName !== '') {
            return $this->mailFromName;
        }
        return $this->account?->getMailFromName();
    }

    #[Groups(['list:read'])]
    public function isPermettiDisiscrizione(): bool
    {
        return $this->permettiDisiscrizione;
    }

    public function setPermettiDisiscrizione(bool $permettiDisiscrizione): static
    {
        $this->permettiDisiscrizione = $permettiDisiscrizione;
        return $this;
    }

    #[Groups(['list:read'])]
    public function getUnsubscribeText(): ?string
    {
        return $this->unsubscribeText;
    }

    public function setUnsubscribeText(?string $unsubscribeText): static
    {
        $this->unsubscribeText = $unsubscribeText;
        return $this;
    }

    public function getDeletedAt(): ?\DateTime
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTime $deletedAt): static
    {
        $this->deletedAt = $deletedAt;
        return $this;
    }

    #[Ignore]
    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setAccount(?Account $account): static
    {
        $this->account = $account;
        return $this;
    }

    #[Groups(['list:read'])]
    public function getAccountId(): ?int
    {
        return $this->account?->getId();
    }

    #[Groups(['list:read'])]
    public function getContactCount(): int
    {
        return $this->contacts->count();
    }

    #[Groups(['list:read'])]
    public function getActiveCount(): int
    {
        return $this->contacts->filter(fn($c) => $c->isIscritto() === true)->count();
    }

    #[Groups(['list:read'])]
    public function getCreatedAt(): ?\DateTime
    {
        return $this->createdAt;
    }

    #[Groups(['list:read'])]
    public function getUpdatedAt(): ?\DateTime
    {
        return $this->updatedAt;
    }
}
