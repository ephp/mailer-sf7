# MailFlow Backend — Specifiche Tecniche

## Istruzioni Generali

- Voglio usare Symfony 7.4 LTS
- Per ricevere i dati in POST e PUT, voglio usare i FormType di Symfony
- Indicami quando un bundle non è adatto a Symfony 7.4
- Nuovi bundle o estensioni a bundle esistenti vanno nella cartella /bundles e devono avere namespace Ephp\*****Bundle

---

## Descrizione Progetto

**MailFlow** è una piattaforma web multi-tenant per l'invio massivo di email (newsletter, comunicazioni marketing). Questo è il backend API REST che gestisce:

- Account multi-tenant con configurazione SMTP personalizzata
- Liste di contatti con tassonomie per segmentazione
- Composizione e invio campagne email
- Tracking aperture, click e disiscrizioni
- Statistiche aggregate

---

## Stack Tecnologico

| Componente | Tecnologia | Versione |
|------------|------------|----------|
| Framework | Symfony | 7.4 LTS |
| PHP | PHP | 8.3+ |
| Database | PostgreSQL | 16 |
| ORM | Doctrine ORM | Attributes |
| Queue | Symfony Messenger | Doctrine/Redis Transport |
| Auth | Lexik JWT Authentication | + Refresh Token |
| Mailer | Symfony Mailer | DSN dinamico per account |

---

## Bundle Oimmei Disponibili

### OiUserBundle / OiUserApiBundle
- **Uso:** Autenticazione, gestione utenti, JWT
- **Entità base:** `OiUserWithEmail` (da estendere in `User`)
- **Trait disponibili:** `FirstLoginTrait`, `ForgotPasswordTrait`
- **Route:** `/api/v1/auth/*`, `/api/v1/user/*`

### OiMailerBundle
- **Uso:** Invio email transazionali
- **Service:** `NotifyService` per email singole
- **Nota:** Per MailFlow, creare `AccountMailerFactory` per DSN dinamico per account

### OiFileBundle
- **Uso:** Upload file (loghi account, immagini email)
- **Entità:** `Upload` con varianti dimensione
- **FormType:** `OiImageType`, `OiFileType`
- **TTL:** Configurare cleanup a 365 giorni

### OiApiBundle
- **Uso:** Helpers per API REST
- **Service:** `FormErrorMessageHandler` per errori form
- **Pattern:** JSON request → FormType → Entity

### OiPostgresBundle
- **Uso:** Funzioni DQL custom per PostgreSQL
- **Funzioni:** `JsonAgg`, `StringAgg`, `Ilike`

---

## Comandi Console

```bash
# Creazione utente (già esistente)
bin/console app:create:user

# Worker invio email
bin/console messenger:consume async --limit=100

# Cleanup file scaduti (da creare)
bin/console app:cleanup:files --ttl=365

# Gestione bounce (da creare)
bin/console app:process:bounces
```

## Sicurezza

- Password SMTP cifrate a riposo (usa Symfony Secrets o encryption service)
- Token tracking UUID v4 (non guessable)
- Rate limiting su endpoint pubblici `/t/*` e `/unsubscribe/*`
- API Key per endpoint REST pubblici (header `X-API-Key`)
- Validazione email con `egulias/email-validator`
