<?php

namespace App\Service;

use App\Entity\Contact;

class PlaceholderService
{
    /** @return string[] */
    public function getAvailablePlaceholders(): array
    {
        return ['nome', 'cognome', 'email'];
    }

    public function replace(string $content, Contact $contact): string
    {
        $map = [
            '[[nome]]'    => $contact->getNome(),
            '[[cognome]]' => $contact->getCognome(),
            '[[email]]'   => $contact->getEmail(),
        ];

        foreach ($map as $placeholder => $value) {
            if ($value !== null) {
                $content = str_replace($placeholder, $value, $content);
            }
        }

        return $content;
    }
}
