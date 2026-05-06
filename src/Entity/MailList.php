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

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $mailerDsnOverride = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $permettiDisiscrizione = true;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $unsubscribeText = null;

    #[ORM\Column(type: 'datetime_mutable', nullable: true)]
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
