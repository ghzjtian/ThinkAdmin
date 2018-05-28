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

namespace controller;

use service\DataService;
use think\Controller;
use think\Db;
use think\db\Query;

/**
 * 后台权限基础控制器
 * Class BasicAdmin
 * @package controller
 */
class BasicAdmin extends Controller
{

    /**
     * 页面标题
     * @var string
     */
    public $title;

    /**
     * 默认操作数据表
     * @var string
     */
    public $table;

    /**
     * 表单默认操作
     * @param Query $dbQuery 数据库查询对象
     * @param string $tplFile 显示模板名字
     * @param string $pkField 更新主键规则
     * @param array $where 查询规则
     * @param array $extendData 扩展数据
     * @return array|string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * @throws \think\Exception
     */
    protected function _form($dbQuery = null, $tplFile = '', $pkField = '', $where = [], $extendData = [])
    {
        //指定 DB 的数据表
        $db = is_null($dbQuery) ? Db::name($this->table) : (is_string($dbQuery) ? Db::name($dbQuery) : $dbQuery);
        //获得主键值
        $pk = empty($pkField) ? ($db->getPk() ? $db->getPk() : 'id') : $pkField;

        //获得传过来的主键对应的值
        $pkValue = $this->request->request($pk, isset($where[$pk]) ? $where[$pk] : (isset($extendData[$pk]) ? $extendData[$pk] : null));

        // 非POST请求, 获取数据并显示表单页面
        if (!$this->request->isPost()) {
            //根据 $dbQuery 的值，去数据库中查找数据
            $vo = ($pkValue !== null) ? array_merge((array)$db->where($pk, $pkValue)->where($where)->find(), $extendData) : $extendData;//根据主键获取到数据
            if (false !== $this->_callback('_form_filter', $vo, [])) {
                //如果 title 不为空，就传进 html 中
                empty($this->title) || $this->assign('title', $this->title);
                return $this->fetch($tplFile, ['vo' => $vo]);
            }
            return $vo;
        }


        // POST请求, 数据自动存库
        $data = array_merge($this->request->post(), $extendData);
        if (false !== $this->_callback('_form_filter', $data, [])) {
            $result = DataService::save($db, $data, $pk, $where);
            if (false !== $this->_callback('_form_result', $result, $data)) {
                if ($result !== false) {
                    $this->success('恭喜, 数据保存成功!', '');
                }
                $this->error('数据保存失败, 请稍候再试!');
            }
        }
    }

    /**
     * 列表集成处理方法
     * @param Query $dbQuery 数据库查询对象
     * @param bool $isPage 是启用分页
     * @param bool $isDisplay 是否直接输出显示
     * @param bool $total 总记录数
     * @param array $result 结果集
     * @return array|string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * @throws \think\Exception
     */
    protected function _list($dbQuery = null, $isPage = true, $isDisplay = true, $total = false, $result = [])
    {
        $db = is_null($dbQuery) ? Db::name($this->table) : (is_string($dbQuery) ? Db::name($dbQuery) : $dbQuery);

        // 列表排序默认处理,内容提交后的处理,
        if ($this->request->isPost() && $this->request->post('action') === 'resort') {
            //@TODO,不知道这里的 $this->request->post() 中的值是怎么来的.!!!
            foreach ($this->request->post() as $key => $value) {
                //在 menu/index 中，点击了排序操作后， $key 对应为 System_menu 中的 id 值， $value 对应为 System_menu 中的 sort 值.
                if (preg_match('/^_\d{1,}$/', $key) && preg_match('/^\d{1,}$/', $value)) {
                    //去除 key 上面的 '_' 底杠
                    list($where, $update) = [['id' => trim($key, '_')], ['sort' => $value]];
                    // 开始更新的操作
                    if (false === Db::table($db->getTable())->where($where)->update($update)) {
                        $this->error('列表排序失败, 请稍候再试');
                    }
                }
            }
            //@TODO,不知道这里怎么把数据渲染到一个 Toast 中的.
            $this->success('列表排序成功, 正在刷新列表', '');
        }
        // 列表数据查询与显示
        if (null === $db->getOptions('order')) {
            in_array('sort', $db->getTableFields($db->getTable())) && $db->order('sort asc');
        }

        //是否分页
        if ($isPage) {
            $rows = intval($this->request->get('rows', cookie('page-rows')));
            cookie('page-rows', $rows = $rows >= 10 ? $rows : 20);
            // 分页数据处理
            $query = $this->request->get();
            $page = $db->paginate($rows, $total, ['query' => $query]);
            if (($totalNum = $page->total()) > 0) {
                list($rowsHTML, $pageHTML, $maxNum) = [[], [], $page->lastPage()];
                foreach ([10, 20, 30, 40, 50, 60, 70, 80, 90, 100, 110, 120, 130, 140, 150, 160, 170, 180, 190, 200] as $num) {
                    list($query['rows'], $query['page']) = [$num, '1'];
                    $url = url('@admin') . '#' . $this->request->baseUrl() . '?' . http_build_query($query);
                    $rowsHTML[] = "<option data-url='{$url}' " . ($rows === $num ? 'selected' : '') . " value='{$num}'>{$num}</option>";
                }
                for ($i = 1; $i <= $maxNum; $i++) {
                    list($query['rows'], $query['page']) = [$rows, $i];
                    $url = url('@admin') . '#' . $this->request->baseUrl() . '?' . http_build_query($query);
                    $selected = $i === intval($page->currentPage()) ? 'selected' : '';
                    $pageHTML[] = "<option data-url='{$url}' {$selected} value='{$i}'>{$i}</option>";
                }
                list($pattern, $replacement) = [['|href="(.*?)"|', '|pagination|'], ['data-open="$1"', 'pagination pull-right']];
                $html = "<span class='pagination-trigger nowrap'>共 {$totalNum} 条记录，每页显示 <select data-auto-none>" . join('', $rowsHTML) . "</select> 条，共 " . ceil($totalNum / $rows) . " 页当前显示第 <select>" . join('', $pageHTML) . "</select> 页。</span>";
                list($result['total'], $result['list'], $result['page']) = [$totalNum, $page->all(), $html . preg_replace($pattern, $replacement, $page->render())];
            } else {
                list($result['total'], $result['list'], $result['page']) = [$totalNum, $page->all(), $page->render()];
            }
        } else {
            $result['list'] = $db->select();
        }

        //返回 View
        if (false !== $this->_callback('_data_filter', $result['list'], []) && $isDisplay) {
            //如果title 有值，就赋值
            !empty($this->title) && $this->assign('title', $this->title);
            return $this->fetch('', $result);
        }
        return $result;
    }

    /**
     * 当前对象回调成员方法,可以调用父类的方法
     * @param string $method
     * @param array|bool $data1
     * @param array|bool $data2
     * @return bool
     */
    protected function _callback($method, &$data1, $data2)
    {
        //例如是 menu 的 add Action , $_method 就会为 add_form_result
        foreach ([$method, "_" . $this->request->action() . "{$method}"] as $_method) {
            if (method_exists($this, $_method) && false === $this->$_method($data1, $data2)) {
                return false;
            }
        }
        return true;
    }

}
