<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

class LegacyPublicApiRedirectController extends AbstractController
{
    #[Route('/api/public/{path}', requirements: ['path' => '(?!v1/).+'])]
    public function redirect(string $path): RedirectResponse
    {
        return new RedirectResponse('/api/public/v1/' . $path, 308);
    }
}
