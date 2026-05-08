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
        EntityManagerInterface $em,
    ): Response {
        $campaignEmail = $em->getRepository(CampaignEmail::class)->findOneBy(['unsubscribeToken' => $uuid]);

        if ($campaignEmail === null) {
            return new Response(
                $this->renderInvalidPage(),
                Response::HTTP_NOT_FOUND,
                ['Content-Type' => 'text/html; charset=UTF-8']
            );
        }

        return new Response(
            $this->renderConfirmPage($uuid, $campaignEmail),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=UTF-8']
        );
    }

    #[Route('/unsubscribe/{uuid}', name: 'tracking_unsubscribe_post', methods: ['POST'])]
    public function unsubscribePost(
        string $uuid,
        Request $request,
        EntityManagerInterface $em,
        UnsubscribeRequestRepository $unsubscribeRepository,
    ): Response {
        $campaignEmail = $em->getRepository(CampaignEmail::class)->findOneBy(['unsubscribeToken' => $uuid]);

        if ($campaignEmail === null) {
            return new Response(
                $this->renderInvalidPage(),
                Response::HTTP_NOT_FOUND,
                ['Content-Type' => 'text/html; charset=UTF-8']
            );
        }

        $existing = $unsubscribeRepository->findOneByCampaignEmail($campaignEmail);
        if ($existing !== null) {
            return new Response(
                $this->renderAlreadyDonePage(),
                Response::HTTP_OK,
                ['Content-Type' => 'text/html; charset=UTF-8']
            );
        }

        $unsubscribeRequest = new UnsubscribeRequest(new \DateTimeImmutable());
        $unsubscribeRequest->setCampaignEmail($campaignEmail);
        $unsubscribeRequest->setIpAddress($request->getClientIp());
        $em->persist($unsubscribeRequest);

        $contact = $campaignEmail->getContact();
        if ($contact !== null) {
            $contact->unsubscribe();
        }

        $em->flush();

        return new Response(
            $this->renderSuccessPage($uuid),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=UTF-8']
        );
    }

    private function renderConfirmPage(string $uuid, CampaignEmail $campaignEmail): string
    {
        $listName = htmlspecialchars(
            $campaignEmail->getMailList()?->getName() ?? 'questa lista',
            ENT_QUOTES,
            'UTF-8'
        );
        $safeUuid = htmlspecialchars($uuid, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Conferma disiscrizione</title>
<style>
  body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f5f5f5;color:#333}
  .card{background:#fff;padding:2rem 3rem;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1);text-align:center;max-width:500px;width:100%}
  h1{font-size:1.5rem;margin-bottom:1rem}
  p{line-height:1.6;color:#555;margin-bottom:1.5rem}
  .btn-confirm{display:inline-block;background:#d32f2f;color:#fff;border:none;padding:.75rem 2rem;border-radius:6px;font-size:1rem;cursor:pointer}
  .btn-confirm:hover{background:#b71c1c}
  .link-cancel{display:block;margin-top:1rem;color:#777;font-size:.9rem;text-decoration:none}
  .link-cancel:hover{color:#333}
</style>
</head>
<body>
<div class="card">
  <h1>Conferma disiscrizione</h1>
  <p>Stai per disiscriverti da <strong>{$listName}</strong>.<br>Non riceverai più email da questa lista.</p>
  <form method="POST" action="/unsubscribe/{$safeUuid}">
    <button type="submit" class="btn-confirm">Conferma disiscrizione</button>
  </form>
  <a href="javascript:history.back()" class="link-cancel">Annulla</a>
</div>
</body>
</html>
HTML;
    }

    private function renderSuccessPage(string $uuid): string
    {
        $safeUuid = htmlspecialchars($uuid, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Disiscrizione confermata</title>
<style>
  body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f5f5f5;color:#333}
  .card{background:#fff;padding:2rem 3rem;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1);text-align:center;max-width:500px;width:100%}
  h1{font-size:1.5rem;margin-bottom:1rem}
  p{line-height:1.6;color:#555;margin-bottom:1.5rem}
  .link-resubscribe{display:inline-block;color:#1976d2;font-size:.9rem}
</style>
</head>
<body>
<div class="card">
  <h1>Sei stato disiscritto</h1>
  <p>La tua disiscrizione è stata confermata. Non riceverai più email da questa lista.</p>
  <form method="POST" action="/unsubscribe/{$safeUuid}/resubscribe">
    <button type="submit" class="link-resubscribe" style="background:none;border:none;cursor:pointer;font-size:.9rem">Iscriviti di nuovo</button>
  </form>
</div>
</body>
</html>
HTML;
    }

    private function renderAlreadyDonePage(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Già disiscritto</title>
<style>
  body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f5f5f5;color:#333}
  .card{background:#fff;padding:2rem 3rem;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1);text-align:center;max-width:500px;width:100%}
  h1{font-size:1.5rem;margin-bottom:1rem}
  p{line-height:1.6;color:#555}
</style>
</head>
<body>
<div class="card">
  <h1>Già disiscritto</h1>
  <p>La tua richiesta di disiscrizione è già stata elaborata in precedenza.</p>
</div>
</body>
</html>
HTML;
    }

    private function renderInvalidPage(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Link non valido</title>
<style>
  body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f5f5f5;color:#333}
  .card{background:#fff;padding:2rem 3rem;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1);text-align:center;max-width:500px;width:100%}
  h1{font-size:1.5rem;margin-bottom:1rem}
  p{line-height:1.6;color:#555}
</style>
</head>
<body>
<div class="card">
  <h1>Link non valido</h1>
  <p>Il link di disiscrizione non è valido o è scaduto.</p>
</div>
</body>
</html>
HTML;
    }
}
