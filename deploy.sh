#!/bin/bash
# imeihub - Production Deployment Script
# Run this on a fresh DigitalOcean/Vultr VPS (Ubuntu 22.04+)
#
# Usage:
#   curl -fsSL https://raw.githubusercontent.com/minerelx168-sketch/imeihub/production/deploy.sh | bash
#   OR
#   git clone https://github.com/minerelx168-sketch/imeihub.git && cd imeihub && bash deploy.sh
#
set -euo pipefail

echo "╔══════════════════════════════════════════════════════╗"
echo "║         imeihub Production Deployment               ║"
echo "╚══════════════════════════════════════════════════════╝"
echo ""

# ─── 1. Install Docker if not present ─────────────────────
if ! command -v docker &> /dev/null; then
    echo "→ Installing Docker..."
    curl -fsSL https://get.docker.com | sh
    systemctl enable docker
    systemctl start docker
    echo "  ✓ Docker installed"
fi

if ! command -v docker compose &> /dev/null && ! docker compose version &> /dev/null; then
    echo "→ Installing Docker Compose plugin..."
    apt-get install -y docker-compose-plugin
fi

# ─── 2. Clone repo if not already in it ───────────────────
if [ ! -f "docker-compose.prod.yml" ]; then
    echo "→ Cloning imeihub repository..."
    git clone --branch production https://github.com/minerelx168-sketch/imeihub.git /opt/imeihub
    cd /opt/imeihub
else
    echo "→ Already in imeihub directory"
fi

# ─── 3. Setup .env ────────────────────────────────────────
if [ ! -f ".env" ]; then
    echo ""
    echo "→ Creating .env from template..."
    cp .env.example .env
    
    # Generate a strong DB password
    DB_PASS=$(openssl rand -base64 24 | tr -d '/+=' | head -c 24)
    sed -i "s/DB_PASS=.*/DB_PASS=${DB_PASS}/" .env
    
    echo ""
    echo "  ⚠ IMPORTANT: Edit .env with your production values:"
    echo "    nano .env"
    echo ""
    echo "  Required settings:"
    echo "    - APP_URL=https://imeihub.com"
    echo "    - APP_DEBUG=false"
    echo "    - IMEI_API_KEY (your PHP API key)"
    echo "    - IMEI_DHRU_API_KEY (your DHRU key)"
    echo "    - GOOGLE_CLIENT_ID + GOOGLE_CLIENT_SECRET"
    echo "    - STRIPE_SECRET_KEY + STRIPE_PUBLISHABLE_KEY + STRIPE_WEBHOOK_SECRET"
    echo ""
    echo "  Press Enter after editing .env to continue..."
    read -r
fi

# ─── 4. Setup firewall ────────────────────────────────────
echo "→ Configuring firewall..."
ufw allow 22/tcp   # SSH
ufw allow 80/tcp   # HTTP (Cloudflare proxy)
ufw allow 443/tcp  # HTTPS (Cloudflare proxy)
ufw --force enable
echo "  ✓ Firewall configured (SSH + HTTP + HTTPS only)"

# ─── 5. Build and start ───────────────────────────────────
echo "→ Building and starting containers..."
docker compose -f docker-compose.prod.yml up -d --build

echo ""
echo "→ Waiting for database to be ready..."
sleep 15

# ─── 6. Run migrations ────────────────────────────────────
echo "→ Running database migrations..."
docker compose -f docker-compose.prod.yml exec -T db \
    mysql -uroot -p"$(grep DB_PASS .env | cut -d= -f2)" imei_checker < sql/migrate-dhru-async.sql 2>/dev/null || true

# ─── 7. Setup cron for DHRU polling ───────────────────────
echo "→ Setting up DHRU order polling cron..."
CRON_CMD="* * * * * docker compose -f /opt/imeihub/docker-compose.prod.yml exec -T app php /var/www/html/scripts/poll-dhru-orders.php >> /var/log/imeihub-dhru.log 2>&1"
(crontab -l 2>/dev/null | grep -v "poll-dhru-orders"; echo "$CRON_CMD") | crontab -
mkdir -p /var/log
echo "  ✓ Cron job installed (polls every 60s)"

# ─── 8. Setup log rotation ────────────────────────────────
cat > /etc/logrotate.d/imeihub << 'EOF'
/var/log/imeihub-*.log {
    daily
    rotate 14
    compress
    missingok
    notifempty
}
EOF

echo ""
echo "╔══════════════════════════════════════════════════════╗"
echo "║              Deployment Complete! ✓                  ║"
echo "╚══════════════════════════════════════════════════════╝"
echo ""
echo "  Server IP: $(curl -s https://api.ipify.org)"
echo ""
echo "  Next steps:"
echo "  1. Add this IP to unlock-service.net whitelist"
echo "     → https://unlock-service.net/api_connect.php"
echo ""
echo "  2. Configure Cloudflare DNS:"
echo "     → A record: imeihub.com → $(curl -s https://api.ipify.org)"
echo "     → A record: www.imeihub.com → $(curl -s https://api.ipify.org)"
echo "     → Proxy: ON (orange cloud)"
echo "     → SSL: Full (Strict)"
echo ""
echo "  3. Setup Google OAuth callback:"
echo "     → https://imeihub.com/api/auth/google/callback.php"
echo ""
echo "  4. Setup Stripe webhook:"
echo "     → https://imeihub.com/api/webhooks/stripe.php"
echo "     → Events: checkout.session.completed"
echo ""
echo "  5. Test the site:"
echo "     → curl http://$(curl -s https://api.ipify.org)"
echo ""
