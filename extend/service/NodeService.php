<?php

// +----------------------------------------------------------------------
// | ThinkAdmin
// +----------------------------------------------------------------------
// | 版权所有 2014~2017 广州楚才信息科技有限公司 [ http://www.cuci.cc ]
// +----------------------------------------------------------------------
// | 官方网站: http://think.ctolog.com
// +----------------------------------------------------------------------
// | 开源协议 ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | github开源项目：https://github.com/zoujingli/ThinkAdmin
// +----------------------------------------------------------------------

namespace service;

use think\Db;

/**
 * 系统权限节点读取器
 * Class NodeService
 * @package extend
 * @author Anyon <zoujingli@qq.com>
 * @date 2017/05/08 11:28
 */
class NodeService
{

    /**
     * 应用用户权限节点
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public static function applyAuthNode()
    {
        cache('need_access_node', null);
        //TODO ,为什么要保存两次用户的信息到 session(可能为使 session 中保持最新的 USER 数据.)
        if (($userid = session('user.id'))) {
            session('user', Db::name('SystemUser')->where(['id' => $userid])->find());
        }
        if (($authorize = session('user.authorize'))) {
            $where = ['status' => '1'];
            //在系统权限表中，找出对应的状态为启用的权限.
            $authorizeids = Db::name('SystemAuth')->whereIn('id', explode(',', $authorize))->where($where)->column('id');
            if (empty($authorizeids)) {
                return session('user.nodes', []);
            }
            //在 系统角色与节点绑定 表中,根据权限 ID 取得权限对应的节点
            $nodes = Db::name('SystemAuthNode')->whereIn('auth', $authorizeids)->column('node');
            return session('user.nodes', $nodes);
        }
        return false;
    }

    /**
     * 获取授权节点
     * @return array
     */
    public static function getAuthNode()
    {
        $nodes = cache('need_access_node');
        if (empty($nodes)) {
            $nodes = Db::name('SystemNode')->where(['is_auth' => '1'])->column('node');
            cache('need_access_node', $nodes);
        }
        return $nodes;
    }

    /**
     * 检查用户节点权限
     * @param string $node 节点
     * @return bool
     */
    public static function checkAuthNode($node)
    {
        list($module, $controller, $action) = explode('/', str_replace(['?', '=', '&'], '/', $node . '///'));
        $currentNode = self::parseNodeStr("{$module}/{$controller}") . strtolower("/{$action}");

        //如果用户是 admin 或
        if (session('user.username') === 'admin' || stripos($node, 'admin/index') === 0) {
            return true;
        }
        //如果当前的节点不在系统的授权节点列表，就返回 true
        if (!in_array($currentNode, self::getAuthNode())) {
            return true;
        }
        return in_array($currentNode, (array)session('user.nodes'));
    }

    /**
     * 获取系统代码节点的信息
     * @param array $nodes
     * @return array
     */
    public static function get($nodes = [])
    {
        //从 '系统节点表' 中获取到节点的信息。
        $alias = Db::name('SystemNode')->column('node,is_menu,is_auth,is_login,title');
        $ignore = ['index', 'wechat/review', 'admin/plugs', 'admin/login', 'admin/index'];

        //获得完整的方法访问的路径: model/controller/action
        foreach (self::getNodeTree(env('app_path')) as $thr) {
            foreach ($ignore as $str) {
                //遇到需要ignore 的特殊的路径，不记录.
                if (stripos($thr, $str) === 0) {
                    continue 2;//跳出当前的 foreach 循环,
                }
            }
            $tmp = explode('/', $thr);

            //以 $thr='admin/auth/apply' 为例,$one = 'admin' ,$two = 'admin/auth'
            list($one, $two) = ["{$tmp[0]}", "{$tmp[0]}/{$tmp[1]}"];
            $nodes[$one] = array_merge(isset($alias[$one]) ? $alias[$one] : ['node' => $one, 'title' => '', 'is_menu' => 0, 'is_auth' => 0, 'is_login' => 0], ['pnode' => '']);
            $nodes[$two] = array_merge(isset($alias[$two]) ? $alias[$two] : ['node' => $two, 'title' => '', 'is_menu' => 0, 'is_auth' => 0, 'is_login' => 0], ['pnode' => $one]);
            $nodes[$thr] = array_merge(isset($alias[$thr]) ? $alias[$thr] : ['node' => $thr, 'title' => '', 'is_menu' => 0, 'is_auth' => 0, 'is_login' => 0], ['pnode' => $two]);
        }

        //根据 is_auth 去检查 is_login 的值.
        foreach ($nodes as &$node) {
            list($node['is_auth'], $node['is_menu'], $node['is_login']) = [intval($node['is_auth']), intval($node['is_menu']), empty($node['is_auth']) ? intval($node['is_login']) : 1];
        }
        return $nodes;
    }

    /**
     * 获取节点列表
     * @param string $dirPath 路径
     * @param array $nodes 额外数据
     * @return array
     */
    public static function getNodeTree($dirPath, $nodes = [])
    {
        foreach (self::scanDirFile($dirPath) as $filename) {
            $matches = [];
            //如果路径匹配不正确，就跳过.
            if (!preg_match('|/(\w+)/controller/(\w+)|', str_replace(DIRECTORY_SEPARATOR, '/', $filename), $matches) || count($matches) !== 3) {
                continue;
            }
            //$matches[0]将包含完整模式匹配到的文本
            $className = env('app_namespace') . str_replace('/', '\\', $matches[0]);
            if (!class_exists($className)) {
                continue;
            }
            //返回由类的方法名组成的数组。
            foreach (get_class_methods($className) as $funcName) {
                //如果不是魔术方法或者是初始化方法
                if (strpos($funcName, '_') !== 0 && $funcName !== 'initialize') {
                    //获得完整的方法访问的路径: model/controller/action
                    $nodes[] = self::parseNodeStr("{$matches[1]}/{$matches[2]}") . '/' . strtolower($funcName);
                }
            }
        }
        return $nodes;
    }

    /**
     * 驼峰转下划线规则
     * @param string $node
     * @return string
     */
    public static function parseNodeStr($node)
    {
        $tmp = [];
        foreach (explode('/', $node) as $name) {
            $tmp[] = strtolower(trim(preg_replace("/[A-Z]/", "_\\0", $name), "_"));
        }
        return trim(join('/', $tmp), '/');
    }

    /**
     * 获取所有PHP文件(包括路径)
     * @param string $dirPath 目录
     * @param array $data 额外数据
     * @param string $ext 有文件后缀
     * @return array
     */
    private static function scanDirFile($dirPath, $data = [], $ext = 'php')
    {
        foreach (scandir($dirPath) as $dir) {
            //忽略隐藏的文件
            if (strpos($dir, '.') === 0) {
                continue;
            }
            $tmpPath = realpath($dirPath . DIRECTORY_SEPARATOR . $dir);
            if (is_dir($tmpPath)) {
                $data = array_merge($data, self::scanDirFile($tmpPath));
//            } elseif (pathinfo($tmpPath, 4) === $ext) {
            } elseif (pathinfo($tmpPath, PATHINFO_EXTENSION) === $ext) {
                $data[] = $tmpPath;
            }
        }
        return $data;
    }

}
