#!/usr/bin/env bash
# Apply Resend DNS instructions helper.
# 1) Create https://resend.com account
# 2) Domains → Add ebubeconnect.com
# 3) Copy the TXT/CNAME records Resend shows into Cloudflare DNS
# 4) Run: railway variable set MAIL_SMTP_PASS=re_xxx MAIL_SMTP_USER=resend MAIL_SMTP_HOST=smtp.resend.com MAIL_FROM_EMAIL=no-reply@ebubeconnect.com MAIL_FROM_NAME=EbubeConnect --service web
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ -z "${RESEND_API_KEY:-}" ]]; then
  echo "Export RESEND_API_KEY=re_... then re-run to set Railway vars."
  echo "Or set manually in Railway dashboard → web → Variables."
  exit 1
fi
railway variable set \
  "MAIL_SMTP_HOST=smtp.resend.com" \
  "MAIL_SMTP_PORT=465" \
  "MAIL_SMTP_USER=resend" \
  "MAIL_SMTP_PASS=${RESEND_API_KEY}" \
  "MAIL_SMTP_SECURE=ssl" \
  "MAIL_FROM_EMAIL=no-reply@ebubeconnect.com" \
  "MAIL_FROM_NAME=EbubeConnect" \
  "RESEND_API_KEY=${RESEND_API_KEY}" \
  --service web --json
echo "Resend vars set on Railway web service."
