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

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $dataDisiscrizione = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $unsubscribeReason = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $bounceCount = 0;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $privacyAcceptedAt = null;

    #[ORM\ManyToOne(targetEntity: MailList::class, inversedBy: 'contacts')]
    #[ORM\JoinColumn(name: 'mail_list_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?MailList $mailList = null;

    /**
     * Transient counters populated by ContactController::index on the current
     * page. Null when not yet computed (e.g. on the single-find route).
     */
    private ?int $bouncedFailedCount = null;
    private ?int $sentCount = null;
    private ?int $openedCount = null;
    private ?int $clickedCount = null;

    public function setBouncedFailedCount(int $n): void
    {
        $this->bouncedFailedCount = $n;
    }

    public function getBouncedFailedCount(): ?int
    {
        return $this->bouncedFailedCount;
    }

    public function setSentCount(int $n): void
    {
        $this->sentCount = $n;
    }

    public function getSentCount(): ?int
    {
        return $this->sentCount;
    }

    public function setOpenedCount(int $n): void
    {
        $this->openedCount = $n;
    }

    public function getOpenedCount(): ?int
    {
        return $this->openedCount;
    }

    public function setClickedCount(int $n): void
    {
        $this->clickedCount = $n;
    }

    public function getClickedCount(): ?int
    {
        return $this->clickedCount;
    }

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

    public function getDataDisiscrizione(): ?\DateTime
    {
        return $this->dataDisiscrizione;
    }

    public function setDataDisiscrizione(?\DateTime $dataDisiscrizione): static
    {
        $this->dataDisiscrizione = $dataDisiscrizione;
        return $this;
    }

    public function unsubscribe(?string $reason = null): void
    {
        $this->iscritto = false;
        $this->dataDisiscrizione = new \DateTime();
        $this->unsubscribeReason = $reason;
    }

    public function resubscribe(): void
    {
        $this->iscritto = true;
        $this->dataDisiscrizione = null;
        $this->unsubscribeReason = null;
    }

    public function getUnsubscribeReason(): ?string
    {
        return $this->unsubscribeReason;
    }

    public function setUnsubscribeReason(?string $unsubscribeReason): static
    {
        $this->unsubscribeReason = $unsubscribeReason;
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

    public function getPrivacyAcceptedAt(): ?\DateTime
    {
        return $this->privacyAcceptedAt;
    }

    public function setPrivacyAcceptedAt(?\DateTime $privacyAcceptedAt): static
    {
        $this->privacyAcceptedAt = $privacyAcceptedAt;
        return $this;
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
