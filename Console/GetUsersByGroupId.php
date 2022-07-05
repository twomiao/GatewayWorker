<?php
namespace Gateway;

use GatewayWorker\Protocols\GatewayProtocol;

/**
 * 获取用户组成员
 * Class Group
 */
class GetUsersByGroupId {
    /**
     * @param int $groupId
     * @return string
     */
    public static function encode(int $groupId)  : string {
        // gateway 数据包格式
        $gateway = GatewayProtocol::$empty;
        // 房间号保存进去
        $gateway['ext_data'] = $groupId;
        // 加载指定群ID命令
        $gateway['cmd']      = GatewayProtocol::CMD_GET_CLIENT_SESSIONS_BY_GROUP;
        return GatewayProtocol::encode($gateway);
    }
}


