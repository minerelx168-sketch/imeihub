# imeihub — Production Deployment Guide

## Architecture

```
imeihub.com (Cloudflare DNS + CDN + SSL)
    └── DigitalOcean Droplet (Singapore, $6/mo)
         ├── Docker: PHP 8.4 + Apache (port 80)
         ├── Docker: MySQL 8.4 (internal only)
         └── Cron: poll-dhru-orders.php (every 60s)
```

## Prerequisites

1. **Domain**: `imeihub.com` registered and nameservers pointed to Cloudflare
2. **VPS**: DigitalOcean Droplet (or Vultr/Hetzner) — Ubuntu 22.04+, 1GB RAM minimum
3. **API Keys**:
   - unlock-service.net PHP API Key
   - unlock-service.net DHRU API Key (GSM Tool)
   - Google OAuth Client ID + Secret
   - Stripe Secret Key + Publishable Key + Webhook Secret

## Quick Deploy

```bash
# SSH into your VPS
ssh root@YOUR_SERVER_IP

# Clone and deploy
git clone --branch production https://github.com/minerelx168-sketch/imeihub.git /opt/imeihub
cd /opt/imeihub
bash deploy.sh
```

## Manual Deploy

### 1. Install Docker

```bash
curl -fsSL https://get.docker.com | sh
systemctl enable docker && systemctl start docker
```

### 2. Clone Repository

```bash
git clone --branch production https://github.com/minerelx168-sketch/imeihub.git /opt/imeihub
cd /opt/imeihub
```

### 3. Configure Environment

```bash
cp .env.example .env
nano .env
```

**Required `.env` values:**

| Variable | Description |
|----------|-------------|
| `APP_URL` | `https://imeihub.com` |
| `APP_DEBUG` | `false` |
| `DB_PASS` | Strong random password (24+ chars) |
| `IMEI_API_KEY` | PHP API Key from unlock-service.net |
| `IMEI_DHRU_API_KEY` | GSM Tool API Key from unlock-service.net |
| `IMEI_API_USERNAME` | `rmandzor` |
| `GOOGLE_CLIENT_ID` | From Google Cloud Console |
| `GOOGLE_CLIENT_SECRET` | From Google Cloud Console |
| `STRIPE_SECRET_KEY` | From Stripe Dashboard |
| `STRIPE_PUBLISHABLE_KEY` | From Stripe Dashboard |
| `STRIPE_WEBHOOK_SECRET` | From Stripe Webhook settings |

### 4. Start Services

```bash
docker compose -f docker-compose.prod.yml up -d --build
```

### 5. Setup Cron

```bash
crontab -e
# Add:
* * * * * docker compose -f /opt/imeihub/docker-compose.prod.yml exec -T app php /var/www/html/scripts/poll-dhru-orders.php >> /var/log/imeihub-dhru.log 2>&1
```

## Post-Deploy Checklist

### Cloudflare DNS

| Type | Name | Content | Proxy |
|------|------|---------|-------|
| A | `@` | `YOUR_SERVER_IP` | Proxied (orange) |
| A | `www` | `YOUR_SERVER_IP` | Proxied (orange) |
| CNAME | `api` | `imeihub.com` | Proxied (orange) |

**Cloudflare SSL Settings:**
- SSL/TLS: Full (Strict)
- Always Use HTTPS: ON
- Minimum TLS: 1.2
- Auto Minify: JS + CSS + HTML

### unlock-service.net

1. Login to https://unlock-service.net/api_connect.php
2. Add your VPS IP to the whitelist
3. Test: `curl "https://api.unlock-service.net/?key=YOUR_KEY&accountinfo=balance"` from the VPS

### Google OAuth

1. Go to https://console.cloud.google.com/apis/credentials
2. Create OAuth 2.0 Client ID (Web application)
3. Authorized redirect URI: `https://imeihub.com/api/auth/google/callback.php`

### Stripe Webhooks

1. Go to https://dashboard.stripe.com/webhooks
2. Add endpoint: `https://imeihub.com/api/webhooks/stripe.php`
3. Events to send: `checkout.session.completed`
4. Copy the signing secret to `STRIPE_WEBHOOK_SECRET`

## Maintenance

### View Logs

```bash
# App logs
docker compose -f docker-compose.prod.yml logs -f app

# Database logs
docker compose -f docker-compose.prod.yml logs -f db

# DHRU polling logs
tail -f /var/log/imeihub-dhru.log
```

### Update Code

```bash
cd /opt/imeihub
git pull origin production
docker compose -f docker-compose.prod.yml up -d --build
```

### Database Backup

```bash
docker compose -f docker-compose.prod.yml exec db \
  mysqldump -uroot -p"$DB_PASS" imei_checker > backup-$(date +%Y%m%d).sql
```

### Restore Database

```bash
docker compose -f docker-compose.prod.yml exec -T db \
  mysql -uroot -p"$DB_PASS" imei_checker < backup-YYYYMMDD.sql
```

## Troubleshooting

| Issue | Solution |
|-------|----------|
| "Invalid IP request" from API | Add VPS IP to unlock-service.net whitelist |
| Google login fails | Check redirect URI matches exactly |
| Stripe webhook 400 | Verify webhook secret in .env |
| DHRU orders stuck PROCESSING | Check cron is running: `crontab -l` |
| 502 on IMEI check | Check API balance: `curl "https://api.unlock-service.net/?key=KEY&accountinfo=balance"` |
| Container won't start | Check logs: `docker compose -f docker-compose.prod.yml logs app` |
