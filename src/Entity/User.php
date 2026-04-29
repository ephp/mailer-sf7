<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Oi\UserBundle\Entity\OiUserWithEmail;

#[ORM\Table(name: 'user_account')]
#[ORM\Entity(repositoryClass: UserRepository::class)]
class User extends OiUserWithEmail
{
    #[ORM\Column(length: 255)]
    private ?string $firstName = null;

    #[ORM\Column(length: 255)]
    private ?string $lastName = null;

    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: 'users')]
    #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Account $account = null;

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setAccount(?Account $account): static
    {
        $this->account = $account;

        return $this;
    }
}
