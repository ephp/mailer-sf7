<?php

namespace App\Service;

use App\Entity\Campaign;
use App\Entity\Contact;
use Twig\Environment;

class EmailTemplateRenderer
{
    public const TEMPLATES = [
        'newsletter'    => 'email/newsletter.html.twig',
        'promo'         => 'email/promo.html.twig',
        'institutional' => 'email/institutional.html.twig',
        'plaintext'     => 'email/plaintext.html.twig',
    ];

    public function __construct(
        private readonly Environment $twig,
        private readonly PlaceholderService $placeholder,
    ) {}

    public function render(Campaign $campaign, ?Contact $contact = null): RenderedEmail
    {
        $contact ??= $this->createSampleContact();

        $structure = $campaign->getStructure() ?? [];
        $templateId = $structure['templateId'] ?? 'newsletter';

        if (!isset(self::TEMPLATES[$templateId])) {
            $templateId = 'newsletter';
        }

        $body = $this->placeholder->replace($campaign->getBody() ?? '', $contact);
        $subject = $this->placeholder->replace($campaign->getEmailSubject(), $contact);

        $account = $campaign->getAccount();
        $primaryColor = $structure['primaryColor'] ?? '#1976d2';
        $textColor = $structure['textColor'] ?? '#333333';
        $logo = $structure['logoOverride'] ?? $account?->getLogo()?->getUrl();

        $context = [
            'campaign'     => $campaign,
            'account'      => $account,
            'body'         => $body,
            'subject'      => $subject,
            'primaryColor' => $primaryColor,
            'textColor'    => $textColor,
            'logo'         => $logo,
            'unsubscribeUrl' => null,
        ];

        $html = $this->twig->render(self::TEMPLATES[$templateId], $context);

        return new RenderedEmail($html, $this->generatePlainText($html));
    }

    public function generatePlainText(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/(\s*\n\s*){3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function createSampleContact(): Contact
    {
        $contact = new Contact();
        $contact->setNome('Mario');
        $contact->setCognome('Rossi');
        $contact->setEmail('mario@example.com');

        return $contact;
    }
}
