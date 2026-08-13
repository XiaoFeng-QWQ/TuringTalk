<?php

namespace App\Enums;

/**
 * 聊天室消息类型枚举
 *
 * 用于扩展特殊消息（卡片、分享等），在数据表 lobby_messages_* 的 type 列存储。
 */
enum LobbyMessageType: string
{
    /** 普通文本消息 */
    case TEXT = 'text';

    /** 表情消息 */
    case STICKER = 'sticker';

    /** 战绩分享卡片 */
    case CARD_SHARE_RECORD = 'card.share.record';

    /** 五子棋对局邀请卡片 */
    case CARD_INVITE_GOMOKU = 'card.invite.gomoku';

    /**
     * 判断该类型是否属于卡片类（需要特殊渲染）
     */
    public function isCard(): bool
    {
        return str_starts_with($this->value, 'card.');
    }
}
