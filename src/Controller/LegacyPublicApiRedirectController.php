<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

class LegacyPublicApiRedirectController
{
    #[Route('/api/public/{path}', requirements: ['path' => '(?!v1/).+'])]
    public function __invoke(string $path): RedirectResponse
    {
        return new RedirectResponse('/api/public/v1/' . $path, 308);
    }
}
