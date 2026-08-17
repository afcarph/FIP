# Getting mail out

**Status: blocked on one credential.** Everything else is ready. This is the
whole remaining path, in order.

## What is wrong today

Production sends every password reset, alert and notification to a MailHog
container nobody reads. Nothing errors, nothing logs — MailHog accepts mail and
keeps it, so from the application's side a captured message and a delivered one
are indistinguishable. It ran that way for weeks.

Two separate faults, and the second is worse than the first:

| | |
|---|---|
| `MAIL_HOST=mailhog` | a development catcher; nothing leaves the host |
| `MAIL_FROM_ADDRESS=no-reply@fip.ph` | **a domain that is not ours** |

`fip.ph` resolves to a parking host on `commonmx.com` nameservers and publishes
no SPF record at all. Mail sent as that address would fail authentication
everywhere it landed, and until it did, the platform was putting somebody
else's domain on its own outgoing post. `MAIL_USERNAME` and `MAIL_PASSWORD` are
four-character placeholders, so no relay was ever configured.

`fip:check-config --production` now refuses both.

## What the domain already has

Checked on `nelleeph.com`, the domain this deployment actually serves from:

```
MX     route1/2/3.mx.cloudflare.net        inbound only — Cloudflare cannot send
TXT    v=spf1 include:_spf.mx.cloudflare.net ~all
TXT    brevo-code:a5b21a2357829f27b9d7352a5e4085b8
DKIM   none published
```

The `brevo-code` record means somebody has already begun verifying this domain
with **Brevo**. That makes Brevo the obvious provider unless there is a reason
to choose otherwise — the account exists and the domain is half-verified.

Note the SPF record covers Cloudflare's *inbound* routing only. Email Routing
receives mail; it cannot send it. A sending provider is required regardless.

## The three steps

### 1. Finish the provider setup (needs the account holder)

In Brevo: complete domain authentication for `nelleeph.com` and generate an
SMTP key. Brevo will give you a DKIM record and its own SPF include.

### 2. Publish DNS on `nelleeph.com` (Cloudflare)

```
TXT   @                v=spf1 include:_spf.mx.cloudflare.net include:spf.brevo.com ~all
TXT   brevo._domainkey <the value Brevo shows>
TXT   _dmarc           v=DMARC1; p=none; rua=mailto:dmarc@nelleeph.com
```

One SPF record, not two — a domain with two `v=spf1` records fails SPF
outright, so the Brevo include is added to the existing line rather than
published alongside it. Start DMARC at `p=none` so nothing is rejected while
you watch the reports, and tighten later.

### 3. Set the environment on the host

```bash
ssh staging && cd /opt/fip
# edit .env — do not paste the key into a shell command, it lands in history
MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=<the Brevo SMTP login>
MAIL_PASSWORD=<the Brevo SMTP key>
MAIL_FROM_ADDRESS=no-reply@nelleeph.com
MAIL_FROM_NAME="Fuel Intelligence Platform"
```

Then, because config is cached:

```bash
DC="docker compose -f infra/docker-compose.yml -f infra/docker-compose.staging.yml --env-file .env"
$DC exec -T api php artisan config:cache
$DC restart queue
```

`queue` matters: mail is sent from the worker, which holds its own copy of the
configuration until it is restarted.

## Proving it works

```bash
$DC exec -T api php artisan fip:check-config --production   # must pass mail and mail from
$DC exec -T api php artisan fip:mail-test you@example.com
```

`fip:mail-test` sends one message synchronously and reports what the transport
said. It prints no credentials, so whoever holds them can run it without those
passing through anybody else's hands. It also warns when the host is a catcher,
because a catcher makes the test go green while proving nothing.

**Accepted by the relay is not delivered.** Open the message and read the
headers:

```
Authentication-Results: spf=pass ... dkim=pass ... dmarc=pass
```

Anything less and the mail will reach some inboxes and silently not others,
which is harder to diagnose than not sending at all.

Finally, exercise the real path rather than the test command: request a
password reset from the login page and confirm the mail arrives and its link
opens. That is the flow this whole thing exists for, and it is the one that has
never once been seen working end to end.
