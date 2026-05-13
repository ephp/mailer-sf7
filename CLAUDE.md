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

---

## Worker Invio Email

### Avvio

```bash
# Avvio base (gira finché non viene interrotto)
bin/console messenger:consume async

# Limita a 100 messaggi poi si ferma (consigliato in produzione con cron)
bin/console messenger:consume async --limit=100

# Ferma dopo 1 ora (utile con supervisor/systemd per evitare memory leak)
bin/console messenger:consume async --time-limit=3600

# Ferma se la memoria supera 128MB
bin/console messenger:consume async --memory-limit=128M

# Combinazione consigliata in produzione
bin/console messenger:consume async --limit=500 --time-limit=3600 --memory-limit=256M
```

### Graceful Shutdown

Il worker risponde al segnale **SIGTERM** terminando il messaggio in corso prima di fermarsi (nessun messaggio perso). Per inviarlo:

```bash
# Tramite PID
kill -SIGTERM <pid>

# Oppure con Symfony (crea un file di lock che il worker controlla)
bin/console messenger:stop-workers
```

### Retry Strategy

Configurata in `config/packages/messenger.yaml`:

| Parametro    | Valore | Descrizione                              |
|--------------|--------|------------------------------------------|
| max_retries  | 3      | Massimo 3 tentativi dopo il primo errore |
| delay        | 2000ms | Attesa iniziale tra tentativi            |
| multiplier   | 2      | Delay esponenziale (2s → 4s → 8s)        |
| max_delay    | 0      | Nessun tetto massimo                     |

Dopo 3 retry falliti, il messaggio va nel transport `failed` (tabella Doctrine `messenger_messages` con queue_name=`failed`). Per reinserire i messaggi falliti:

```bash
# Visualizza messaggi falliti
bin/console messenger:failed:show

# Reinvia tutti i messaggi falliti
bin/console messenger:failed:retry --all
```

### Rotazione Log

Il worker usa il logger Symfony standard. In produzione i log finiscono in `var/log/prod.log`. Per evitare che il file cresca senza limiti, configurare logrotate:

```
# /etc/logrotate.d/mailflow
/var/www/mailflow/var/log/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0640 www-data adm
}
```

### Produzione: Systemd

```ini
# /etc/systemd/system/mailflow-worker.service
[Unit]
Description=MailFlow Messenger Worker
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/mailflow
ExecStart=/usr/bin/php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M
Restart=always
RestartSec=5
KillMode=mixed
TimeoutStopSec=30

[Install]
WantedBy=multi-user.target
```

```bash
systemctl enable mailflow-worker
systemctl start mailflow-worker
systemctl status mailflow-worker
```

### Produzione: Cron (alternativa a systemd)

```cron
# Avvia un worker ogni ora che gira per 55 minuti poi si ferma
5 * * * * www-data /usr/bin/php /var/www/mailflow/bin/console messenger:consume async --time-limit=3300 --memory-limit=256M >> /var/log/mailflow-worker.log 2>&1
```

## Sicurezza

- Password SMTP cifrate a riposo (usa Symfony Secrets o encryption service)
- Token tracking UUID v4 (non guessable)
- Rate limiting su endpoint pubblici `/t/*` e `/unsubscribe/*`
- API Key per endpoint REST pubblici (header `X-API-Key`)
- Validazione email con `egulias/email-validator`
