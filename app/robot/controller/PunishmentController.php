<?php
namespace app\robot\controller;

use think\Request;

class PunishmentController
{
    // 自定义违禁词接口
    public function setForbiddenWords(Request $request)
    {
        $groupId = $request->post('group_id');
        $words = $request->post('words');  // 违禁词列表
        $action = $request->post('action'); // 禁言或踢出
        $duration = $request->post('duration'); // 惩罚时长

        // 逻辑处理
        return json(['status' => 'success', 'message' => 'Forbidden words and punishment set']);
    }

    // 惩罚信息不在群组发言提示
    public function setSilentPunishment(Request $request)
    {
        $groupId = $request->post('group_id');
        $silent = $request->post('silent'); // 是否静默处理

        // 逻辑处理
        return json(['status' => 'success', 'message' => 'Punishment set to silent mode']);
    }
}
