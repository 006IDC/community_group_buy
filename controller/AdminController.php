<?php

namespace addon\community_group_buy\controller;

use addon\community_group_buy\model\GroupBuyService;
use app\event\controller\PluginAdminBaseController;

class AdminController extends PluginAdminBaseController
{
    public function dashboard()
    {
        return $this->ok(GroupBuyService::dashboard());
    }

    public function economics()
    {
        return $this->ok(GroupBuyService::economics());
    }

    public function products()
    {
        return $this->ok(GroupBuyService::products($this->request->param()));
    }

    public function settings()
    {
        return $this->ok(['settings' => GroupBuyService::settings()]);
    }

    public function saveSettings()
    {
        return json(GroupBuyService::saveSettings($this->request->param()));
    }

    public function groups()
    {
        return $this->ok(GroupBuyService::groups($this->request->param(), false));
    }

    public function groupDetail()
    {
        $detail = GroupBuyService::groupDetail((int) $this->request->param('id', 0), false);
        if (empty($detail)) {
            return json(['status' => 404, 'msg' => '拼团不存在']);
        }
        return $this->ok(['group' => $detail]);
    }

    private function ok($data)
    {
        return json(['status' => 200, 'msg' => 'success', 'data' => $data]);
    }
}
