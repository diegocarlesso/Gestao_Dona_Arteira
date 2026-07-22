#!/bin/bash
# Mapeia a estrutura de diretórios da hospedagem — ERP Dona Arteira
#
# Objetivo: descobrir ONDE a aplicação Laravel pode morar sem ficar
# dentro do document root do site principal (ver 23-Deploy/02, §3).
#
# USO (via SSH):  bash mapear-estrutura.sh

echo "================================================================"
echo " ESTRUTURA DA HOSPEDAGEM — $(date '+%Y-%m-%d %H:%M:%S')"
echo " usuário: $(whoami)   home: $HOME"
echo "================================================================"

echo
echo "── Raiz da home ────────────────────────────────────────────────"
ls -la "$HOME" | grep -v '^total'

echo
echo "── public_html é link simbólico? ───────────────────────────────"
if [ -L "$HOME/public_html" ]; then
    echo "SIM → aponta para: $(readlink -f "$HOME/public_html")"
else
    echo "NÃO — é diretório real: $HOME/public_html"
fi

echo
echo "── Existe estrutura domains/? ──────────────────────────────────"
if [ -d "$HOME/domains" ]; then
    echo "SIM. Conteúdo:"
    ls -la "$HOME/domains"
    echo
    echo "Um nível abaixo (procurando docroots por domínio):"
    find "$HOME/domains" -maxdepth 2 -type d 2>/dev/null | head -30
else
    echo "NÃO existe ~/domains"
fi

echo
echo "── Qual é o document root REAL do site? ────────────────────────"
# ~/public_html pode ser um diretório vazio herdado. O docroot de verdade
# costuma ficar em ~/domains/<dominio>/public_html. Descobrir pelo conteúdo.
DOCROOT=""
for cand in "$HOME"/domains/*/public_html "$HOME/public_html"; do
    [ -d "$cand" ] || continue
    n=$(ls -A "$cand" 2>/dev/null | wc -l)
    echo "  $cand → $n item(ns)"
    if [ -f "$cand/wp-config.php" ] || [ -f "$cand/index.php" ]; then
        DOCROOT="$cand"
    fi
done
if [ -n "$DOCROOT" ]; then
    echo "  ⇒ DOCUMENT ROOT DETECTADO: $DOCROOT"
else
    echo "  ⇒ não identificado automaticamente — inspecionar manualmente"
    DOCROOT="$HOME/public_html"
fi

echo
echo "── Conteúdo do document root (primeiro nível) ──────────────────"
ls -la "$DOCROOT" 2>/dev/null | head -25

echo
echo "── Onde está a pasta do subdomínio 'gestao'? ───────────────────"
find "$HOME/domains" "$HOME/public_html" -maxdepth 3 -type d -name gestao 2>/dev/null \
    || echo "não encontrada"

echo
echo "── .htaccess do site principal (regras que afetam /gestao) ─────"
if [ -f "$DOCROOT/.htaccess" ]; then
    grep -nE 'RewriteRule|RewriteCond|Deny|Require|Options' "$DOCROOT/.htaccess" | head -20
else
    echo "sem .htaccess em $DOCROOT"
fi

echo
echo "── Composer disponível? (item M-1) ─────────────────────────────"
if command -v composer >/dev/null 2>&1; then
    echo "SIM → $(command -v composer) · $(composer --version 2>/dev/null | head -1)"
else
    echo "NÃO está no PATH. Testar também: php ~/composer.phar --version"
fi

echo
echo "── Binário do PHP (para o cron) ────────────────────────────────"
echo "which php  → $(command -v php || echo 'não encontrado')"
php -v 2>/dev/null | head -1
echo "Alternativas CloudLinux:"
ls -d /opt/alt/php*/usr/bin/php 2>/dev/null | tail -5 || echo "  (nenhuma em /opt/alt)"

echo
echo "================================================================"
echo " O que procurar no resultado:"
echo " 1. Existe algum diretório FORA de public_html onde a aplicação"
echo "    possa morar? (~/domains/... ou a própria home)"
echo " 2. public_html é link? Isso muda o caminho real a informar"
echo "    no campo de document root do painel."
echo "================================================================"
