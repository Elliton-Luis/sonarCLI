#!/usr/bin/env bash
# ============================================================================
# SONAR: Script de Setup & Verificação de Ambiente
# ============================================================================
set -e

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

SONAR_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SONAR_DIR"

echo -e "${BLUE}=======================================================${NC}"
echo -e "${BLUE}🛰️  SONAR: Inicializador e Verificador de Ambiente${NC}"
echo -e "${BLUE}=======================================================${NC}\n"

# 1. Verifica binário do PHP
if ! command -v php &> /dev/null; then
    echo -e "${RED}❌ PHP não encontrado!${NC}"
    echo "Instale o PHP 8.1 ou superior (ex: sudo apt install php8.2-cli php8.2-curl php8.2-mbstring ou sudo dnf install php php-curl php-mbstring)."
    exit 1
fi

PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;')
PHP_MINOR=$(php -r 'echo PHP_MINOR_VERSION;')

if [ "$PHP_MAJOR" -lt 8 ] || ([ "$PHP_MAJOR" -eq 8 ] && [ "$PHP_MINOR" -lt 1 ]); then
    echo -e "${RED}❌ Versão do PHP detectada: $PHP_VERSION. Sonar requer PHP 8.1+!${NC}"
    exit 1
fi
echo -e "${GREEN}✓ PHP $PHP_VERSION detectado.${NC}"

# 2. Verifica extensões essenciais do PHP
REQUIRED_EXTS=("curl" "mbstring" "json" "openssl")
OPTIONAL_EXTS=("pcntl" "posix")
MISSING_EXTS=()

for ext in "${REQUIRED_EXTS[@]}"; do
    if ! php -m | grep -qi "^$ext$"; then
        MISSING_EXTS+=("$ext")
    fi
done

if [ ${#MISSING_EXTS[@]} -gt 0 ]; then
    echo -e "${RED}❌ Extensões PHP obrigatórias ausentes: ${MISSING_EXTS[*]}${NC}"
    echo "Por favor instale as extensões antes de prosseguir."
    exit 1
fi
echo -e "${GREEN}✓ Extensões obrigatórias (curl, mbstring, json, openssl) carregadas.${NC}"

for ext in "${OPTIONAL_EXTS[@]}"; do
    if ! php -m | grep -qi "^$ext$"; then
        echo -e "${YELLOW}⚠️ Extensão '$ext' não encontrada. O suporte a encerramento gracioso de sinais será limitado.${NC}"
    fi
done

# 3. Ferramentas opcionais do sistema
if command -v ffmpeg &> /dev/null; then
    echo -e "${GREEN}✓ FFmpeg encontrado (conversão de áudio para Whisper STT ativa).${NC}"
else
    echo -e "${YELLOW}⚠️ FFmpeg não encontrado no PATH. Mensagens de voz precisarão de conversão prévia.${NC}"
fi

if command -v agy &> /dev/null; then
    echo -e "${GREEN}✓ Antigravity CLI (agy) detectado.${NC}"
elif command -v opencode &> /dev/null; then
    echo -e "${GREEN}✓ OpenCode CLI detectado.${NC}"
else
    echo -e "${YELLOW}ℹ️ Nem 'agy' nem 'opencode' detectados no PATH. Prompts usarão a API rápida (NVIDIA/OpenRouter) ou comandos nativos de terminal.${NC}"
fi

# 4. Estrutura de pastas e arquivos de log seguros
mkdir -p "$SONAR_DIR/storage/tmp"
chmod 0700 "$SONAR_DIR/storage" "$SONAR_DIR/storage/tmp"

touch "$SONAR_DIR/daemon.log"
chmod 0600 "$SONAR_DIR/daemon.log"
echo -e "${GREEN}✓ Diretório storage/tmp e daemon.log inicializados com permissões restritas (0700/0600).${NC}"

# 5. Configuração e proteção do .env
if [ ! -f "$SONAR_DIR/.env" ]; then
    if [ -f "$SONAR_DIR/.env.example" ]; then
        cp "$SONAR_DIR/.env.example" "$SONAR_DIR/.env"
        echo -e "${GREEN}✓ Arquivo .env criado a partir de .env.example.${NC}"
        echo -e "${YELLOW}👉 IMPORTANTE: Edite o arquivo .env e configure seu TELEGRAM_BOT_TOKEN!${NC}"
    else
        echo -e "${RED}❌ Arquivo .env.example não encontrado!${NC}"
        exit 1
    fi
else
    echo -e "${GREEN}✓ Arquivo .env já existe.${NC}"
fi
chmod 0600 "$SONAR_DIR/.env"
echo -e "${GREEN}✓ Permissões estritas (0600) garantidas no .env.${NC}"

# 6. Permissões de execução dos utilitários
chmod +x "$SONAR_DIR/setup.sh" 2>/dev/null || true
if [ -f "$SONAR_DIR/sonar" ]; then
    chmod +x "$SONAR_DIR/sonar" 2>/dev/null || true
fi

# 7. Instalação opcional como serviço systemd
if [[ "$*" == *"--service"* ]]; then
    echo -e "\n${BLUE}Configurando serviço systemd de usuário...${NC}"
    SYSTEMD_USER_DIR="$HOME/.config/systemd/user"
    mkdir -p "$SYSTEMD_USER_DIR"

    SERVICE_FILE="$SYSTEMD_USER_DIR/sonar-daemon.service"
    PHP_PATH=$(command -v php)

    cat <<EOF > "$SERVICE_FILE"
[Unit]
Description=Sonar AI Terminal Bridge Daemon
After=network.target

[Service]
Type=simple
WorkingDirectory=$SONAR_DIR
ExecStart=$PHP_PATH $SONAR_DIR/daemon_telegram.php
Restart=always
RestartSec=5
StandardOutput=append:$SONAR_DIR/daemon.log
StandardError=append:$SONAR_DIR/daemon.log

[Install]
WantedBy=default.target
EOF

    systemctl --user daemon-reload
    systemctl --user enable sonar-daemon.service
    systemctl --user restart sonar-daemon.service
    echo -e "${GREEN}✓ Serviço 'sonar-daemon.service' configurado e iniciado via systemd!${NC}"
    echo -e "Status: systemctl --user status sonar-daemon.service"
fi

echo -e "\n${GREEN}=======================================================${NC}"
echo -e "${GREEN}🎉 Ambiente pronto para uso!${NC}"
echo -e "${GREEN}=======================================================${NC}"
echo -e "Para rodar interativamente no terminal:"
echo -e "  ./sonar start  (ou php daemon_telegram.php)\n"
echo -e "Para instalar como serviço de segundo plano:"
echo -e "  ./setup.sh --service\n"
