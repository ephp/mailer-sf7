<?php

namespace App\Entity;

use App\Repository\CampaignRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Ephp\MailflowBundle\Entity\BaseCampaign;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Table(name: 'campaign')]
#[ORM\Entity(repositoryClass: CampaignRepository::class)]
class Campaign extends BaseCampaign
{
    #[ORM\Column(length: 20, options: ['default' => 'draft'])]
    #[Assert\Choice(choices: ['draft', 'scheduled', 'sending', 'sent'])]
    private string $status = 'draft';

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'cloned_from_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Campaign $clonedFrom = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Account $account = null;

    #[ORM\ManyToMany(targetEntity: MailList::class)]
    #[ORM\JoinTable(name: 'campaign_mail_list')]
    private Collection $mailLists;

    public function __construct()
    {
        $this->mailLists = new ArrayCollection();
    }

    #[Groups(['campaign:read'])]
    public function getId(): ?int
    {
        return parent::getId();
    }

    #[Groups(['campaign:read'])]
    public function getName(): ?string
    {
        return parent::getName();
    }

    #[Groups(['campaign:read'])]
    public function getEmailSubject(): string
    {
        return parent::getEmailSubject();
    }

    #[Groups(['campaign:read'])]
    public function getSnippet(): ?string
    {
        return parent::getSnippet();
    }

    #[Groups(['campaign:read'])]
    public function getBody(): ?string
    {
        return parent::getBody();
    }

    #[Groups(['campaign:read'])]
    public function isDraft(): bool
    {
        return parent::isDraft();
    }

    #[Groups(['campaign:read'])]
    public function isTemplate(): bool
    {
        return parent::isTemplate();
    }

    #[Groups(['campaign:read'])]
    public function getScheduledAt(): ?\DateTimeInterface
    {
        return parent::getScheduledAt();
    }

    #[Groups(['campaign:read'])]
    public function getSentAt(): ?\DateTimeInterface
    {
        return parent::getSentAt();
    }

    #[Groups(['campaign:read'])]
    public function getCreatedAt(): ?\DateTimeInterface
    {
        return parent::getCreatedAt();
    }

    #[Groups(['campaign:read'])]
    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return parent::getUpdatedAt();
    }

    #[Groups(['campaign:read'])]
    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    #[Ignore]
    public function getClonedFrom(): ?Campaign
    {
        return $this->clonedFrom;
    }

    public function setClonedFrom(?Campaign $clonedFrom): static
    {
        $this->clonedFrom = $clonedFrom;
        return $this;
    }

    #[Groups(['campaign:read'])]
    public function getClonedFromId(): ?int
    {
        return $this->clonedFrom?->getId();
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

    #[Groups(['campaign:read'])]
    public function getAccountId(): ?int
    {
        return $this->account?->getId();
    }

    #[Ignore]
    public function getMailLists(): Collection
    {
        return $this->mailLists;
    }

    #[Groups(['campaign:read'])]
    public function getMailListIds(): array
    {
        return array_values($this->mailLists->map(fn(MailList $ml) => $ml->getId())->toArray());
    }

    #[Groups(['campaign:read'])]
    public function getRecipientCount(): int
    {
        return (int) array_sum($this->mailLists->map(fn(MailList $ml) => $ml->getContactCount())->toArray());
    }
}
