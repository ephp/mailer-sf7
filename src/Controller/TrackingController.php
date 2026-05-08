<?php

namespace App\Controller;

use App\Entity\CampaignEmail;
use App\Entity\OpenStat;
use App\Entity\UnsubscribeRequest;
use App\Repository\LinkStatRepository;
use App\Repository\OpenStatRepository;
use App\Repository\UnsubscribeRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TrackingController extends AbstractController
{
    // 1x1 transparent PNG
    private const PIXEL_PNG = "\x89\x50\x4e\x47\x0d\x0a\x1a\x0a\x00\x00\x00\x0d\x49\x48\x44\x52\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\x0a\x49\x44\x41\x54\x78\x9c\x62\x00\x01\x00\x00\x05\x00\x01\x0d\x0a\x2d\xb4\x00\x00\x00\x00\x49\x45\x4e\x44\xae\x42\x60\x82";

    #[Route('/t/open/{uuid}', name: 'tracking_open', methods: ['GET'])]
    public function open(
        string $uuid,
        EntityManagerInterface $em,
        OpenStatRepository $openStatRepository,
    ): Response {
        $campaignEmail = $em->getRepository(CampaignEmail::class)->findOneBy(['trackingOpenId' => $uuid]);

        if ($campaignEmail !== null) {
            $openStat = $openStatRepository->findOneByCampaignEmail($campaignEmail);
            if ($openStat === null) {
                $openStat = new OpenStat(new \DateTimeImmutable());
                $openStat->setCampaignEmail($campaignEmail);
                $em->persist($openStat);
            } else {
                $openStat->incrementCount();
            }
            $em->flush();
        }

        return new Response(
            self::PIXEL_PNG,
            Response::HTTP_OK,
            [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]
        );
    }

    #[Route('/t/click/{token}', name: 'tracking_click', methods: ['GET'])]
    public function click(
        string $token,
        EntityManagerInterface $em,
        LinkStatRepository $linkStatRepository,
    ): Response {
        $linkStat = $linkStatRepository->findOneByToken($token);

        if ($linkStat === null) {
            return new Response('Link non trovato.', Response::HTTP_NOT_FOUND);
        }

        $linkStat->incrementCount();
        $em->flush();

        return new RedirectResponse($linkStat->getUrl(), Response::HTTP_FOUND);
    }

    #[Route('/unsubscribe/{uuid}', name: 'tracking_unsubscribe', methods: ['GET'])]
    public function unsubscribe(
        string $uuid,
        Request $request,
        EntityManagerInterface $em,
        UnsubscribeRequestRepository $unsubscribeRepository,
    ): Response {
        $campaignEmail = $em->getRepository(CampaignEmail::class)->findOneBy(['unsubscribeToken' => $uuid]);

        $alreadyDone = false;

        if ($campaignEmail !== null) {
            $existing = $unsubscribeRepository->findOneByCampaignEmail($campaignEmail);
            if ($existing === null) {
                $unsubscribeRequest = new UnsubscribeRequest(new \DateTimeImmutable());
                $unsubscribeRequest->setCampaignEmail($campaignEmail);
                $unsubscribeRequest->setIpAddress($request->getClientIp());
                $em->persist($unsubscribeRequest);

                $contact = $campaignEmail->getContact();
                if ($contact !== null) {
                    $contact->setIscritto(false);
                }

                $em->flush();
            } else {
                $alreadyDone = true;
            }
        }

        $html = $alreadyDone
            ? $this->renderUnsubscribePage('Già disiscritto', 'La tua richiesta di disiscrizione è già stata elaborata.')
            : $this->renderUnsubscribePage('Disiscrizione confermata', 'Hai confermato la disiscrizione. Non riceverai più email da questa lista.');

        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function renderUnsubscribePage(string $title, string $message): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$safeTitle}</title>
<style>
  body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f5f5f5;color:#333}
  .card{background:#fff;padding:2rem 3rem;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1);text-align:center;max-width:480px}
  h1{font-size:1.5rem;margin-bottom:1rem}
  p{line-height:1.6;color:#555}
</style>
</head>
<body>
<div class="card">
  <h1>{$safeTitle}</h1>
  <p>{$safeMessage}</p>
</div>
</body>
</html>
HTML;
    }
}
