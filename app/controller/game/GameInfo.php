<?php
namespace app\controller\game;

use app\controller\common\LogHelper;
use app\model\Luzhu;
use app\controller\Base;
use app\service\WorkerOpenPaiService;
use think\facade\Db;

class GameInfo extends Base
{
    //获取普配牌型，用于前端展示当前牌型
    public function get_poker_type()
    {
        $id = $this->request->post('id/d', 0);
        if ($id <= 0) show([], config('ToConfig.http_code.error'), '露珠ID必填');
        $find = Luzhu::find($id);
        if (empty($find)) show([], config('ToConfig.http_code.error'), '牌型信息不存在');
        if ($find->game_type != 3) show([], config('ToConfig.http_code.error'), '骰宝游戏类型不正确');
        //获取台桌开牌信息
        $service = new WorkerOpenPaiService();
        $poker = $service->get_pai_info_sicbo($find->result_pai);
        show($poker);
    }

    /**
     * 获取用户投注历史记录
     */
    public function get_user_bet_history()
    {
        $user_id = $this->request->param('user_id/d', 0);
        $table_id = $this->request->param('table_id', '');
        $game_type = $this->request->param('game_type/d', 0);
        $page = $this->request->param('page/d', 1);
        $page_size = $this->request->param('page_size/d', 20);
        $status = $this->request->param('status', '');
        $start_date = $this->request->param('start_date', '');
        $end_date = $this->request->param('end_date', '');
        $bet_type = $this->request->param('bet_type', '');

        if ($user_id <= 0) show([], config('ToConfig.http_code.error'), '用户ID必填');
        if (empty($table_id)) show([], config('ToConfig.http_code.error'), '台桌ID必填');

        // 构建查询条件
        $where = [
            ['user_id', '=', $user_id],
            ['table_id', '=', $table_id],
            ['game_type', '=', $game_type]
        ];

        // 状态筛选
        if (!empty($status) && $status != 'all') {
            $statusMap = [
                'pending' => 1,    // 待开牌
                'win' => 2,        // 已结算-中奖
                'lose' => 2,       // 已结算-未中奖
                'cancelled' => 3,  // 台面作废
                'processing' => 4  // 修改结果
            ];
            if (isset($statusMap[$status])) {
                $where[] = ['close_status', '=', $statusMap[$status]];
                if ($status == 'win') {
                    $where[] = ['win_amt', '>', 0];
                } elseif ($status == 'lose') {
                    $where[] = ['win_amt', '=', 0];
                }
            }
        }

        // 时间筛选
        if (!empty($start_date)) {
            $where[] = ['created_at', '>=', $start_date . ' 00:00:00'];
        }
        if (!empty($end_date)) {
            $where[] = ['created_at', '<=', $end_date . ' 23:59:59'];
        }

        // 投注类型筛选
        if (!empty($bet_type) && $bet_type != 'all') {
            $where[] = ['detail', 'like', '%' . $bet_type . '%'];
        }

        // 查询总数
        $total = Db::name('dianji_records')->where($where)->count();

        // 分页查询
        $records = Db::name('dianji_records')
            ->where($where)
            ->order('created_at desc')
            ->page($page, $page_size)
            ->select()
            ->toArray();

        // 格式化数据
        $formattedRecords = [];
        foreach ($records as $record) {
            $formattedRecords[] = $this->formatBettingRecord($record);
        }

        // 计算分页信息
        $totalPages = ceil($total / $page_size);
        
        $result = [
            'records' => $formattedRecords,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'total_records' => $total,
                'page_size' => $page_size,
                'has_more' => $page < $totalPages
            ]
        ];

        show($result);
    }

    /**
     * 获取单条投注记录详情
     */
    public function get_bet_detail()
    {
        $record_id = $this->request->param('record_id', '');
        $user_id = $this->request->param('user_id/d', 0);

        if (empty($record_id)) show([], config('ToConfig.http_code.error'), '记录ID必填');
        if ($user_id <= 0) show([], config('ToConfig.http_code.error'), '用户ID必填');

        // 查询记录详情
        $record = Db::name('dianji_records')
            ->where('id', $record_id)
            ->where('user_id', $user_id)
            ->find();

        if (empty($record)) show([], config('ToConfig.http_code.error'), '投注记录不存在');

        $result = $this->formatBettingRecord($record);
        show($result);
    }

    /**
     * 格式化投注记录数据
     */
    private function formatBettingRecord($record)
    {
        // 解析投注详情
        $betDetails = $this->parseBetDetails($record['detail'], $record['bet_amt'], $record['win_amt']);
        
        // 解析游戏结果
        $diceResults = $this->parseGameResult($record['lu_zhu_id']);
        
        // 确定状态
        $status = $this->getRecordStatus($record);

        return [
            'id' => (string)$record['id'],
            'game_number' => $record['id'] ?? '',
            'table_id' => $record['table_id'] ?? '',
            'user_id' => (string)$record['user_id'],
            'bet_time' => $record['created_at'] ?? '',
            'settle_time' => $record['updated_at'] ?? '',
            'bet_details' => $betDetails,
            'total_bet_amount' => (float)$record['bet_amt'],
            'total_win_amount' => (float)$record['win_amt'],
            'net_amount' => (float)$record['delta_amt'],
            'dice_results' => $diceResults['dice'] ?? [1, 1, 1],
            'dice_total' => $diceResults['total'] ?? 3,
            'status' => $status,
            'is_settled' => $record['close_status'] != 1,
            'currency' => 'CNY'
        ];
    }

    /**
     * 解析投注详情
     */
    private function parseBetDetails($detail, $betAmt, $winAmt)
    {
        // 简单解析投注详情文本
        $betTypeName = $detail ?? '未知投注';
        
        return [
            [
                'bet_type' => $this->extractBetType($detail),
                'bet_type_name' => $betTypeName,
                'bet_amount' => (float)$betAmt,
                'odds' => $this->calculateOdds($betAmt, $winAmt),
                'win_amount' => (float)$winAmt,
                'is_win' => $winAmt > 0,
                'rate_id' => 1
            ]
        ];
    }

    /**
     * 解析游戏结果
     */
private function parseGameResult($lu_zhu_id)
{
    $r = Db::name('dianji_lu_zhu')->find($lu_zhu_id);
    $r_result = $r['result_pai'];
    
    // 将 JSON 字符串解码为数组
    $r_result = json_decode($r_result, true);
    
    
    // 简单解析结果，如果没有具体数据则返回默认值
    return [
        'dice' => [$r_result[1], $r_result[2], $r_result[3]],
        'total' => 3
    ];
}

    /**
     * 提取投注类型
     */
    private function extractBetType($detail)
    {
        if (strpos($detail, '大') !== false) return 'big';
        if (strpos($detail, '小') !== false) return 'small';
        if (strpos($detail, '单') !== false) return 'odd';
        if (strpos($detail, '双') !== false) return 'even';
        return 'unknown';
    }

    /**
     * 计算赔率
     */
    private function calculateOdds($betAmt, $winAmt)
    {
        if ($betAmt <= 0 || $winAmt <= 0) return '1:0';
        $ratio = round($winAmt / $betAmt, 2);
        return '1:' . $ratio;
    }

    /**
     * 获取记录状态
     */
    private function getRecordStatus($record)
    {
        switch ($record['close_status']) {
            case 1:
                return 'pending';
            case 2:
                return $record['win_amt'] > 0 ? 'win' : 'lose';
            case 3:
                return 'cancelled';
            case 4:
                return 'processing';
            default:
                return 'pending';
        }
    }


}