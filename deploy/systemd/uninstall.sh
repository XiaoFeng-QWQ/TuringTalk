#!/bin/bash
# Turing Game - systemd 服务卸载脚本
# 用法: sudo bash uninstall.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "==> 停止所有模块..."
for svc in turing-game-proxy turing-game-web turing-game-game turing-game-whoisai turing-game-lobby turing-game-gomoku turing-game-admin; do
    if systemctl is-active --quiet "$svc" 2>/dev/null; then
        echo "  - 停止 $svc"
        systemctl stop "$svc"
    fi
done

echo "==> 停止 turing-game.target..."
systemctl stop turing-game.target 2>/dev/null || true

echo "==> 禁用开机自启..."
systemctl disable turing-game.target 2>/dev/null || true

echo "==> 删除服务文件..."
for svc in "$SCRIPT_DIR"/*.service "$SCRIPT_DIR"/turing-game.target; do
    name=$(basename "$svc")
    if [ -f "/etc/systemd/system/$name" ]; then
        echo "  - 删除 $name"
        rm -f "/etc/systemd/system/$name"
    fi
done

echo "==> 重载 systemd 配置..."
systemctl daemon-reload

echo ""
echo "==> 卸载完成！所有 Turing Game 模块已停止并移除。"