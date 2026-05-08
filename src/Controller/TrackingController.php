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
            return $this->render('unsubscribe/invalid.html.twig', [], new Response(status: Response::HTTP_NOT_FOUND));
        }

        $mailList = $campaignEmail->getMailList();

        return $this->render('unsubscribe/confirm.html.twig', [
            'uuid' => $uuid,
            'listName' => $mailList?->getName() ?? 'questa lista',
            'unsubscribeText' => $mailList?->getUnsubscribeText(),
        ]);
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
            return $this->render('unsubscribe/invalid.html.twig', [], new Response(status: Response::HTTP_NOT_FOUND));
        }

        $existing = $unsubscribeRepository->findOneByCampaignEmail($campaignEmail);
        if ($existing !== null) {
            return $this->render('unsubscribe/already_done.html.twig');
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

        $listName = $campaignEmail->getMailList()?->getName() ?? 'questa lista';

        return $this->render('unsubscribe/success.html.twig', [
            'uuid' => $uuid,
            'listName' => $listName,
        ]);
    }
}
