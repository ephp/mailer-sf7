<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Oi\UserBundle\Entity\OiUserWithEmail;

#[ORM\Table(name: 'user_account')]
#[ORM\Entity]
class User extends OiUserWithEmail
{
    #[ORM\Column(length: 255)]
    private ?string $firstName = null;

    #[ORM\Column(length: 255)]
    private ?string $lastName = null;

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
}
