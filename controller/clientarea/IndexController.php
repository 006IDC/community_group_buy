<?php

namespace addon\community_group_buy\controller\clientarea;

use addon\community_group_buy\model\GroupBuyService;
use app\event\controller\PluginBaseController;

class IndexController extends PluginBaseController
{
    public function overview()
    {
        return $this->ok(GroupBuyService::overview());
    }

    public function groups()
    {
        return $this->ok(GroupBuyService::groups($this->request->param(), true));
    }

    public function groupDetail()
    {
        $detail = GroupBuyService::groupDetail((int) $this->request->param('id', 0), true);
        if (empty($detail)) {
            return json(['status' => 404, 'msg' => '拼团不存在']);
        }
        return $this->ok(['group' => $detail]);
    }

    public function share()
    {
        try {
            return $this->ok(GroupBuyService::shareInfo(
                (int) $this->request->param('id', 0),
                (string) $this->request->domain()
            ));
        } catch (\Throwable $e) {
            return json(['status' => 400, 'msg' => GroupBuyService::publicExceptionMessage(
                $e,
                '邀请卡生成失败，请稍后重试'
            )]);
        }
    }

    public function createGroup()
    {
        return json(GroupBuyService::createGroup(
            (int) $this->request->param('product_id', 0),
            (int) $this->request->param('target_size', 0),
            (string) $this->request->param('mode', 'normal'),
            (string) $this->request->param('request_id', '')
        ));
    }

    public function joinGroup()
    {
        return json(GroupBuyService::joinGroup((int) $this->request->param('id', 0)));
    }

    private function ok($data)
    {
        return json(['status' => 200, 'msg' => 'success', 'data' => $data]);
    }
}
