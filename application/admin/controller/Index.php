<?php

// +----------------------------------------------------------------------
// | ThinkAdmin
// +----------------------------------------------------------------------
// | 版权所有 2014~2017 广州楚才信息科技有限公司 [ http://www.cuci.cc ]
// +----------------------------------------------------------------------
// | 官方网站: http://think.ctolog.com
// +----------------------------------------------------------------------
// | 开源协议 ( https://mit-license.org )
// +----------------------------------------------------------------------
// | github开源项目：https://github.com/zoujingli/ThinkAdmin
// +----------------------------------------------------------------------

namespace app\admin\controller;

use controller\BasicAdmin;
use service\DataService;
use service\NodeService;
use service\ToolsService;
use think\App;
use think\Db;

/**
 * 后台入口
 * Class Index
 * @package app\admin\controller
 * @author Anyon <zoujingli@qq.com>
 * @date 2017/02/15 10:41
 */
class Index extends BasicAdmin
{

    /**
     * 后台框架布局
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function index()
    {
        NodeService::applyAuthNode();

        //在 '系统菜单表' 中取得启用的菜单项
        $list = (array)Db::name('SystemMenu')->where(['status' => '1'])->order('sort asc,id asc')->select();
        /**
         * //双感叹号(!!),返回一个(boolean)值,
         * //https://stackoverflow.com/questions/2127260/double-not-operator-in-php
         * //It is functionally equivalent to a cast to boolean:
         * //return (bool)$row;
         **/
        $menus = $this->buildMenuData(ToolsService::arr2tree($list), NodeService::get(), !!session('user'));

        //如果没有菜单并且没有登录,就跳到登录页面
        //如果有菜单，而且不需要 权限认证，就跳到权限认证的界面
        if (empty($menus) && !session('user.id')) {
            $this->redirect('@admin/login');
        }
        return $this->fetch('', ['title' => '系统管理', 'menus' => $menus]);
    }

    /**
     * 后台主菜单权限过滤
     * @param array $menus 当前菜单列表
     * @param array $nodes 系统权限节点数据
     * @param bool $isLogin 是否已经登录
     * @return array
     */
    private function buildMenuData($menus, $nodes, $isLogin)
    {
        foreach ($menus as $key => &$menu) {
            //如果是有子菜单的话，就继续子菜单的权限过滤.
            !empty($menu['sub']) && $menu['sub'] = $this->buildMenuData($menu['sub'], $nodes, $isLogin);
            if (!empty($menu['sub'])) {//如果有子菜单,就忽略原本的 url
                $menu['url'] = '#';
            } elseif (preg_match('/^https?\:/i', $menu['url'])) {//如果没有子菜单，而且有分配了 url 地址
                // i修正模式,不区分大小写
                continue;
            } elseif ($menu['url'] !== '#') {   //如果没有子菜单,而且没有填写 '#' 符号.
                /**
                 * preg_replace('/[\W]/', '/', $menu['url']),在 $menu 的 url 里，所有不是'数字，字母，下划线' 的都被替换成了 '/'
                 * array_slice,在数组中取出一段，当前取出(model/controller/action)
                 *
                 */
                $node = join('/', array_slice(explode('/', preg_replace('/[\W]/', '/', $menu['url'])), 0, 3));
                $menu['url'] = url($menu['url']) . (empty($menu['params']) ? '' : "?{$menu['params']}");

                //如果是 节点array 中有菜单中指定的节点,而且 节点需要登录控制，并且 没有登录
                if (isset($nodes[$node]) && $nodes[$node]['is_login'] && empty($isLogin)) {
                    //过滤这个节点
                    unset($menus[$key]);

                    //如果是 节点array 中有菜单中指定的节点,而且 节点需要 身份认证控制,已经登录了,但没有通过 身份认证控制
                } elseif (isset($nodes[$node]) && $nodes[$node]['is_auth'] && $isLogin && !auth($node)) {
                    unset($menus[$key]);
                }
            } else {//如果填写的是 # ,就移除这个 节点
                unset($menus[$key]);
            }
        }
        return $menus;
    }

    /**
     * 主机信息显示
     * @return string
     */
    public function main()
    {
        //显示 mysql 的 version
        $_version = Db::query('select version() as ver');
        return $this->fetch('', [
            'title' => '后台首页',
            'think_ver' => App::VERSION,
            'mysql_ver' => array_pop($_version)['ver'],
        ]);
    }

    /**
     * 修改密码
     * @return array|string
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * @throws \think\exception\PDOException
     */
    public function pass()
    {
        if (intval($this->request->request('id')) !== intval(session('user.id'))) {
            $this->error('只能修改当前用户的密码！');
        }
        //显示修改密码的 html dialog
        if ($this->request->isGet()) {
            $this->assign('verify', true);
            return $this->_form('SystemUser', 'user/pass');
        }
        //验证 修改密码
        //取得提交过来的数据
        $data = $this->request->post();
        if ($data['password'] !== $data['repassword']) {
            $this->error('两次输入的密码不一致，请重新输入！');
        }
        $user = Db::name('SystemUser')->where('id', session('user.id'))->find();
        if (md5($data['oldpassword']) !== $user['password']) {
            $this->error('旧密码验证失败，请重新输入！');
        }
        if (DataService::save('SystemUser', ['id' => session('user.id'), 'password' => md5($data['password'])])) {
            $this->success('密码修改成功，下次请使用新密码登录！', '');
        }
        $this->error('密码修改失败，请稍候再试！');
    }

    /**
     * 修改资料
     * @return array|string
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function info()
    {
        if (intval($this->request->request('id')) === intval(session('user.id'))) {
            return $this->_form('SystemUser', 'user/form');
        }
        $this->error('只能修改当前用户的资料！');
    }

}
