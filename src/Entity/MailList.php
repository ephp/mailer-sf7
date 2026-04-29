<?php

namespace App\Entity;

use App\Repository\MailListRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Table(name: 'mail_list')]
#[ORM\Entity(repositoryClass: MailListRepository::class)]
class MailList
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
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

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Account $account = null;

    #[ORM\OneToMany(targetEntity: Contact::class, mappedBy: 'mailList')]
    private Collection $contacts;

    public function __construct()
    {
        $this->contacts = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
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

    public function isPermettiDisiscrizione(): bool
    {
        return $this->permettiDisiscrizione;
    }

    public function setPermettiDisiscrizione(bool $permettiDisiscrizione): static
    {
        $this->permettiDisiscrizione = $permettiDisiscrizione;
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

    public function getAccountId(): ?int
    {
        return $this->account?->getId();
    }

    public function getContactCount(): int
    {
        return $this->contacts->count();
    }
}
