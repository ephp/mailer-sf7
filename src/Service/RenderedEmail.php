<?php

namespace App\Service;

readonly class RenderedEmail
{
    public function __construct(
        public string $html,
        public string $plainText,
    ) {}
}
