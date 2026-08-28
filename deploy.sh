#!/bin/bash
# ============================================================
#  deploy-guidance.sh — commit, push, and deploy guidance-system
#  Usage: ./deploy-guidance.sh "commit message"
#  Run this from inside your local guidance-system repo folder.
# ============================================================
set -e

MSG="${1:-Update}"
DROPLET_IP="68.183.228.242"
SSH_KEY="/c/Users/dance/ssh_key/cms_ssh"
REMOTE_PATH="/var/www/html/guidance-system"

echo "== Staging and committing local changes =="
git add -A
git commit -m "$MSG" || echo "(nothing to commit — continuing)"

echo "== Pushing to GitHub =="
git push origin main

echo "== Pulling on droplet =="
ssh -i "$SSH_KEY" "root@${DROPLET_IP}" "cd ${REMOTE_PATH} && git pull origin main"

echo "== Done. Live at http://${DROPLET_IP}/guidance-system/public/login.php =="

# to deploy in one line ---  ./deploy.sh "fixed the BOM issue"