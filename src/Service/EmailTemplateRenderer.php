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
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%app_url%')]
        private readonly string $appUrl = '',
    ) {}

    public function render(Campaign $campaign, ?Contact $contact = null, ?string $unsubscribeUrl = null, ?string $webViewUrl = null, ?string $openTrackingUrl = null): RenderedEmail
    {
        $contact ??= $this->createSampleContact();

        $structure = $campaign->getStructure() ?? [];
        // The frontend stores structure keys in snake_case; accept both for safety.
        $templateId = $structure['template_id'] ?? $structure['templateId'] ?? 'newsletter';

        if (!isset(self::TEMPLATES[$templateId])) {
            $templateId = 'newsletter';
        }

        $body = $this->placeholder->replace($campaign->getBody() ?? '', $contact);
        $subject = $this->placeholder->replace($campaign->getEmailSubject(), $contact);

        $account = $campaign->getAccount();

        // First associated mail list: used as fallback for defaults and for the signature.
        $firstList = $campaign->getMailLists()->first();
        $firstList = $firstList !== false ? $firstList : null;

        // Determine which mail list provides the defaults:
        //  - if global_style is on → none, the structure values win
        //  - if style_per_list is on AND we have the contact's mail list → that one
        //  - otherwise → the first associated list
        $useGlobal = (bool) ($structure['global_style'] ?? false);
        $perList   = (bool) ($structure['style_per_list'] ?? false);

        $defaultsList = null;
        if (!$useGlobal) {
            if ($perList && $contact->getMailList() !== null) {
                $defaultsList = $contact->getMailList();
            } else {
                $defaultsList = $firstList;
            }
        }

        $primaryColor = $useGlobal
            ? ($structure['primary_color'] ?? $structure['primaryColor'] ?? '#1976d2')
            : ($defaultsList?->getDefaultPrimaryColor() ?? '#1976d2');
        $textColor = $useGlobal
            ? ($structure['text_color'] ?? $structure['textColor'] ?? '#333333')
            : ($defaultsList?->getDefaultTextColor() ?? '#333333');
        $headingFont = $useGlobal
            ? ($structure['heading_font'] ?? $structure['headingFont'] ?? 'Roboto')
            : ($defaultsList?->getDefaultHeadingFont() ?? 'Roboto');
        $bodyFont = $useGlobal
            ? ($structure['body_font'] ?? $structure['bodyFont'] ?? 'Inter')
            : ($defaultsList?->getDefaultBodyFont() ?? 'Inter');

        $logo = $structure['logo_override']
            ?? $structure['logoOverride']
            ?? $account?->getLogo()?->getUrl();
        // Email clients fetch images over the public internet, so a relative
        // path like "/uploads/..." would 404. Prefix with %app_url% (composed
        // of APP_PROTOCOL + APP_HOSTNAME) when the logo is not already a
        // fully qualified URL.
        if (is_string($logo) && $logo !== '' && !preg_match('#^(https?:)?//#', $logo)) {
            $logo = rtrim($this->appUrl, '/') . '/' . ltrim($logo, '/');
        }

        // Signature is taken from the first associated list (independent of style mode).
        $signature = null;
        if ($firstList !== null && $firstList->getFirmaHtml()) {
            $signature = $this->placeholder->replace($firstList->getFirmaHtml(), $contact);
        }

        $context = [
            'campaign'        => $campaign,
            'account'         => $account,
            'body'            => $body,
            'subject'         => $subject,
            'preheader'       => $campaign->getSnippet(),
            'primaryColor'    => $primaryColor,
            'textColor'       => $textColor,
            'onPrimaryColor'  => $this->contrastColor($primaryColor),
            'logo'            => $logo,
            'headingFont'     => $headingFont,
            'bodyFont'        => $bodyFont,
            'googleFontsUrl'  => $this->buildGoogleFontsUrl($headingFont, $bodyFont),
            'signature'       => $signature,
            'bodyPlain'       => $this->htmlToPlainText($body),
            'signaturePlain'  => $signature !== null ? $this->htmlToPlainText($signature) : null,
            'unsubscribeUrl'  => $unsubscribeUrl,
            'webViewUrl'      => $webViewUrl,
            'openTrackingUrl' => $openTrackingUrl,
        ];

        $html = $this->twig->render(self::TEMPLATES[$templateId], $context);

        return new RenderedEmail($html, $this->generatePlainText($html));
    }

    public function generatePlainText(string $html): string
    {
        return $this->htmlToPlainText($html);
    }

    /**
     * Converts HTML to readable plain text, preserving line breaks introduced
     * by block-level tags (p, div, br, li, headings) — striptags alone collapses
     * everything onto a single line.
     */
    private function htmlToPlainText(string $html): string
    {
        // Insert newlines for block tags before stripping them.
        $html = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<\s*li[^>]*>/i', '- ', $html) ?? $html;
        $html = preg_replace('/<\s*\/(p|div|li|h[1-6]|tr|blockquote)\s*>/i', "\n", $html) ?? $html;

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize whitespace without losing newlines.
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n[ \t]+/', "\n", $text) ?? $text;
        $text = preg_replace('/[ \t]+\n/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

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

    /**
     * System fonts that don't need to be loaded from Google Fonts.
     */
    private const SYSTEM_FONTS = [
        'arial', 'helvetica', 'georgia', 'times new roman', 'courier new',
        'verdana', 'tahoma', 'trebuchet ms', 'monospace', 'serif', 'sans-serif',
    ];

    /**
     * Builds a single CSS URL that loads both the heading and body fonts from Google Fonts.
     * Returns null if both fonts are system fonts (no remote stylesheet needed).
     */
    private function buildGoogleFontsUrl(string $headingFont, string $bodyFont): ?string
    {
        $families = [];
        foreach ([$headingFont, $bodyFont] as $font) {
            if ($font === '' || in_array(strtolower($font), self::SYSTEM_FONTS, true)) {
                continue;
            }
            $key = str_replace(' ', '+', $font);
            if (!in_array($key, $families, true)) {
                $families[] = $key . ':wght@400;500;700';
            }
        }
        if (empty($families)) {
            return null;
        }

        return 'https://fonts.googleapis.com/css2?'
            . implode('&', array_map(fn($f) => 'family=' . $f, $families))
            . '&display=swap';
    }

    /**
     * Returns #000000 or #ffffff depending on which gives better contrast against the
     * provided hex color. Used to pick the foreground color for header text and CTA buttons
     * so they remain readable regardless of the chosen primary color.
     */
    private function contrastColor(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return '#ffffff';
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        // Perceived luminance (0..255).
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b);

        return $luminance > 160 ? '#000000' : '#ffffff';
    }
}
