<?php

namespace App\Controller;

use Oi\ApiBundle\Model\ItemDetail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1')]
class TemplatePresetController extends AbstractController
{
    private const PRESETS = ['newsletter', 'promo', 'institutional', 'plaintext'];

    #[Route('/templates/presets', name: 'template_presets_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function list(
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        $presets = array_map(fn (string $id) => [
            'id' => $id,
            'name' => $translator->trans('campaign.template.preset.' . $id . '.name'),
            'description' => $translator->trans('campaign.template.preset.' . $id . '.description'),
            'thumbnail_url' => '/assets/email-templates/' . $id . '.png',
        ], self::PRESETS);

        $detail = new ItemDetail($presets);

        return new Response($serializer->serialize($detail, 'json'), Response::HTTP_OK);
    }
}
