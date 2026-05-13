<?php

namespace App\Service;

use App\Entity\Account;

/**
 * Renders the privacy policy text for an account, applying [[placeholder]]
 * substitutions. Each account can store its own text on Account.privacyPolicy;
 * if none is set, a default GDPR-compliant template is used.
 */
class PrivacyPolicyService
{
    public const DEFAULT_TEMPLATE = <<<'TEXT'
Informativa sulla privacy

Ai sensi del Regolamento (UE) 2016/679 (GDPR) ti informiamo che i dati personali da te forniti (indirizzo email, e facoltativamente nome, cognome e numero di telefono) saranno trattati nel rispetto dei principi di liceità, correttezza e trasparenza.

**Titolare del trattamento**
[[nome_account]]
Email di contatto: [[email_contatto_account]]
[[indirizzo_account]]

**Finalità del trattamento**
I dati raccolti tramite questo form saranno utilizzati esclusivamente da [[nome_account]] per inviarti comunicazioni email a scopo promozionale relative a eventi, iniziative e attività organizzate dal titolare.

**Base giuridica**
Il trattamento si fonda sul tuo consenso esplicito, che puoi revocare in qualsiasi momento senza pregiudicare la liceità del trattamento basato sul consenso prestato prima della revoca.

**Conservazione dei dati**
I dati saranno conservati fino alla revoca del consenso o alla richiesta di cancellazione.

**Disiscrizione e diritti dell'interessato**
Puoi disiscriverti in qualsiasi momento utilizzando il link di disiscrizione presente in ogni email ricevuta. Hai inoltre il diritto di accedere ai tuoi dati, di chiederne la rettifica o la cancellazione, di limitarne o opporti al trattamento, e di proporre reclamo all'Autorità Garante per la protezione dei dati personali. Per esercitare questi diritti puoi scrivere a [[email_contatto_account]].

**Comunicazione e trasferimento dei dati**
I dati non saranno ceduti a terzi né trasferiti al di fuori dello Spazio Economico Europeo, fatto salvo l'utilizzo di servizi tecnici (es. provider di posta elettronica) necessari all'erogazione del servizio.

Spuntando la casella di accettazione confermi di aver letto e compreso questa informativa.
TEXT;

    /**
     * @return list<string> the list of placeholders supported in privacy policy text.
     */
    public function availablePlaceholders(): array
    {
        return [
            '[[nome_account]]',
            '[[email_contatto_account]]',
            '[[indirizzo_account]]',
            '[[partita_iva_account]]',
            '[[mail_from_account]]',
            '[[mail_from_name_account]]',
        ];
    }

    public function getEffectiveTemplate(Account $account): string
    {
        $tpl = $account->getPrivacyPolicy();
        if ($tpl === null || trim($tpl) === '') {
            return self::DEFAULT_TEMPLATE;
        }
        return $tpl;
    }

    /**
     * Replaces [[placeholder_account]] tokens in the policy text with the
     * matching Account fields. Empty values are left as a blank string.
     */
    public function render(Account $account, ?string $template = null): string
    {
        $tpl = $template ?? $this->getEffectiveTemplate($account);

        $map = [
            '[[nome_account]]' => (string) ($account->getRagioneSociale() ?? ''),
            '[[email_contatto_account]]' => (string) ($account->getEmailContatto() ?? ''),
            '[[indirizzo_account]]' => (string) ($account->getIndirizzo() ?? ''),
            '[[partita_iva_account]]' => (string) ($account->getPartitaIva() ?? ''),
            '[[mail_from_account]]' => (string) ($account->getMailFrom() ?? ''),
            '[[mail_from_name_account]]' => (string) ($account->getMailFromName() ?? ''),
        ];

        return str_replace(array_keys($map), array_values($map), $tpl);
    }
}
