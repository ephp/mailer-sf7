<?php

namespace App\Entity;

use App\Repository\CampaignRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Oi\MailflowBundle\Entity\BaseCampaign;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Table(name: 'campaign')]
#[ORM\Entity(repositoryClass: CampaignRepository::class)]
class Campaign extends BaseCampaign
{
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

    public function getAccountId(): ?int
    {
        return $this->account?->getId();
    }

    #[Ignore]
    public function getMailLists(): Collection
    {
        return $this->mailLists;
    }

    public function getMailListIds(): array
    {
        return array_values($this->mailLists->map(fn(MailList $ml) => $ml->getId())->toArray());
    }

    public function getRecipientCount(): int
    {
        return (int) array_sum($this->mailLists->map(fn(MailList $ml) => $ml->getContactCount())->toArray());
    }
}
