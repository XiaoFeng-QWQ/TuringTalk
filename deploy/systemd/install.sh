#!/bin/bash
# Turing Game - systemd 服务安装脚本
# 用法: sudo bash install.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"

echo "==> 安装 systemd 服务文件..."
for svc in "$SCRIPT_DIR"/*.service; do
    name=$(basename "$svc")
    echo "  - 安装 $name"
    cp "$svc" /etc/systemd/system/"$name"
    chmod 644 /etc/systemd/system/"$name"
    sed -i "s|__PROJECT_DIR__|$PROJECT_DIR|g" /etc/systemd/system/"$name"
done

# 安装 target
cp "$SCRIPT_DIR/turing-game.target" /etc/systemd/system/turing-game.target
chmod 644 /etc/systemd/system/turing-game.target
sed -i "s|__PROJECT_DIR__|$PROJECT_DIR|g" /etc/systemd/system/turing-game.target

echo "==> 重载 systemd 配置..."
systemctl daemon-reload

echo ""
echo "==> 安装完成！可用命令："
echo ""
echo "  启动所有模块：  sudo systemctl start turing-game.target"
echo "  停止所有模块：  sudo systemctl stop turing-game.target"
echo "  查看所有状态：  sudo systemctl status turing-game.target"
echo ""
echo "  单个模块管理："
echo "    sudo systemctl start|stop|restart|status turing-game-{proxy,web,game,whoisai,lobby,gomoku,admin}"
echo ""
echo "  开机自启："
echo "    sudo systemctl enable turing-game.target"
echo ""
echo "  查看日志："
echo "    journalctl -u turing-game-proxy -f"
echo "    journalctl -u turing-game-lobby -f"
echo "    journalctl -u turing-game-game -f"
echo ""
echo "  重启单个模块（不中断其他服务）："
echo "    sudo systemctl restart turing-game-lobby"