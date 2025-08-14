<?php

namespace app\controller\game;

use app\controller\common\LogHelper;
use app\BaseController;
use app\model\Luzhu;
use app\model\LuzhuHeguan;
use app\model\LuzhuPreset;
use app\model\Table;
use app\job\TableEndTaskJob;
use app\service\CardSettlementService;
use app\validate\BetOrder as validates;
use think\exception\ValidateException;
use think\facade\Queue;
use app\model\UserModel;           // 用户模型
use app\model\GameRecords;         // 游戏记录模型  
use think\facade\Db;               // 数据库操作
use app\business\Curl;
use app\business\RequestUrl;

class GetForeignTableInfo extends BaseController
{
    
    /**
     * ========================================
     * 获取用户详细信息
     * ========================================
     * 
     * 根据用户ID获取用户的完整信息，包括基础信息、余额、统计数据等
     * 主要用于：
     * - 荷官端查看用户信息
     * - 客服端用户资料查询
     * - 管理端用户管理
     * 
     * @return string JSON响应
     */
    public function get_user_info(): string
    {
        LogHelper::debug('=== 获取用户信息请求开始 ===');
        
        // 获取参数
        $user_id = $this->request->param('user_id', 0);
        
        // 参数验证
        if (empty($user_id) || !is_numeric($user_id)) {
            LogHelper::warning('用户ID参数无效', ['user_id' => $user_id]);
            return show([], config('ToConfig.http_code.error'), '用户ID必填且必须为数字');
        }
        
        $user_id = intval($user_id);
        
        LogHelper::debug('查询用户信息', ['user_id' => $user_id]);
        
        try {
            // 查询用户基础信息
            $userInfo = UserModel::where('id', $user_id)->find();
            // 先检查用户是否存在
            if (empty($userInfo)) {
                LogHelper::warning('用户不存在', ['user_id' => $user_id]);
                return show([], config('ToConfig.http_code.error'), '用户不存在');
            }
            
            // 转换为数组便于处理
            $userData = $userInfo->toArray();

            // 请求远程更新用户的余额
            if(env('zonghepan.enable', false)) {
                try {
                    $url = env('zonghepan.game_url', '0.0.0.0').RequestUrl::balance();
                    
                    // 确保用户名存在
                    if (!empty($userData['user_name'])) {
                        $data = Curl::post($url, ['user_name' => $userData['user_name']], []);
                        
                        // 检查返回数据的完整性
                        if (is_array($data)) {
                            if (isset($data['code']) && $data['code'] == 200) {
                                // 安全地获取余额
                                if (isset($data['data']) && isset($data['data']['balance'])) {
                                    $userData['money_balance'] = $data['data']['balance'];
                                    LogHelper::debug('远程余额更新成功', [
                                        'user_name' => $userData['user_name'],
                                        'balance' => $userData['money_balance']
                                    ]);
                                }
                            } else {
                                // 记录错误但不中断流程
                                $errorMsg = isset($data['msg']) ? $data['msg'] : '未知错误';
                                LogHelper::warning('获取用户余额失败', [
                                    'user_name' => $userData['user_name'], 
                                    'error' => $errorMsg,
                                    'response' => $data
                                ]);
                            }
                        } else {
                            LogHelper::warning('远程余额接口返回格式错误', [
                                'user_name' => $userData['user_name'],
                                'response' => $data
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    // 捕获远程请求异常，但不影响主流程
                    LogHelper::error('请求远程余额接口异常', [
                        'user_name' => $userData['user_name'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // 移除敏感信息
            unset($userData['pwd']);
            unset($userData['withdraw_pwd']);
            
            // 格式化金额字段（确保字段存在）
            $userData['money_balance'] = number_format($userData['money_balance'] ?? 0, 2);
            $userData['money_freeze'] = number_format($userData['money_freeze'] ?? 0, 2);
            $userData['money_total_recharge'] = number_format($userData['money_total_recharge'] ?? 0, 2);
            $userData['money_total_withdraw'] = number_format($userData['money_total_withdraw'] ?? 0, 2);
            $userData['rebate_balance'] = number_format($userData['rebate_balance'] ?? 0, 2);
            $userData['rebate_total'] = number_format($userData['rebate_total'] ?? 0, 2);
            
            // 格式化状态字段（使用 ?? 防止字段不存在）
            $userData['type_text'] = ($userData['type'] ?? 0) == 1 ? '代理' : '会员';
            $userData['status_text'] = ($userData['status'] ?? 0) == 1 ? '正常' : '冻结';
            $userData['state_text'] = ($userData['state'] ?? 0) == 1 ? '在线' : '离线';
            $userData['is_real_name_text'] = ($userData['is_real_name'] ?? 0) == 1 ? '已实名' : '未实名';
            
            // 虚拟账号类型
            $fictitiousTypes = [
                0 => '正常账号',
                1 => '虚拟账号',
                2 => '试玩账号'
            ];
            $userData['is_fictitious_text'] = $fictitiousTypes[$userData['is_fictitious'] ?? 0] ?? '未知';

            // 增加获取客服的方式（安全获取配置）
            try {
                $app_kefu_url = get_config('app_kefu_url');
                $app_feiji_url = get_config('app_feiji_url');
                $web_main = get_config('web_main');
                
                $userData['app_kefu_url'] = $app_kefu_url['value'] ?? '';
                $userData['app_feiji_url'] = $app_feiji_url['value'] ?? '';
                $userData['web_main'] = $web_main['value'] ?? '';
            } catch (\Exception $e) {
                // 配置获取失败，设置默认值
                LogHelper::warning('获取配置信息失败', ['error' => $e->getMessage()]);
                $userData['app_kefu_url'] = '';
                $userData['app_feiji_url'] = '';
                $userData['web_main'] = '';
            }
            
            LogHelper::debug('用户信息查询成功', [
                'user_id' => $user_id,
                'user_name' => $userData['user_name'] ?? 'unknown',
                'balance' => $userData['money_balance']
            ]);
            
            return show($userData, 1, '获取用户信息成功');
            
        } catch (\Exception $e) {
            LogHelper::error('获取用户信息失败', [
                'user_id' => $user_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return show([], config('ToConfig.http_code.error'), '获取用户信息失败：' . $e->getMessage());
        }
    }

    //获取台桌视频
    public function get_table_video()
    {
        $params = $this->request->param();
        $returnData = array();
        $info = Table::order('id desc')->find($params['tableId']);
        $returnData['video_near'] = $info['video_near'];
        $returnData['video_far'] = $info['video_far'];
        // 返回数据
        show($returnData, 1);
    }

    //获取荷官台桌露珠信息
    public function get_hg_lz_list(): string
    {
        
        $params = $this->request->param();
       
        $returnData = LuzhuHeguan::LuZhuList($params);
        show($returnData, 1);
    }

    public function get_hg_data_list(): string
    {
        
        $params = $this->request->param();
       //查询台桌是否在洗桌状态
       //获取台桌信息
        if(!isset($params['tableId']) || empty($params['tableId'])) return show([],1,'台桌ID不存在');
         $table  = Table::where('id',$params['tableId'])->find();
         if(empty($table)) return show([],1,'台桌不存在');
         $table = $table->toArray();
         if($table['wash_status']  ==1 ){
               show([], 1);
         }
        $returnData = LuzhuHeguan::LuZhuList($params);
        show($returnData, 1);
    }
    
    public function get_hg_video_list(): string
    {
        
        $params = $this->request->param();
        //获取台桌信息
        if(!isset($params['tableId']) || empty($params['tableId'])) return show([],1,'台桌ID不存在');
        $table  = Table::where('id',$params['tableId'])->find();
         if(empty($table)) return show([],1,'台桌不存在');
         $table = $table->toArray();
       $video_near = explode('=',$table['video_near']);
       $video_far = explode('=',$table['video_far']);

        show(['video_near'=>$video_near[1].$table['id'],'video_far'=>$video_far[1].$table['id']], 1);
    }

   //获取台桌露珠信息
    public function get_lz_list(): string
    {
        $params = $this->request->param();
        $tableId = $this->request->param('tableId',0);
        if ($tableId <=0 ) show([], config('ToConfig.http_code.error'),'台桌ID必填');
        $returnData = Luzhu::LuZhuList($params);        
        show($returnData, 1);
    }

    // 获取台桌列表
    public function get_table_list(): string
    {
        $gameTypeView = array(
            '1' => '_nn',
            '2' => '_lh',
            '3' => '_bjl'
        );
        $infos = Table::where(['status' => 1])->order('id asc')->select()->toArray();
        empty($infos) && show($infos, 1);
        foreach ($infos as $k => $v) {
            // 设置台桌类型 对应的 view 文件
            $infos[$k]['viewType'] = $gameTypeView[$v['game_type']];
            $number = rand(100, 3000);// 随机人数
            $infos[$k]['number'] = $number;
            // 获取靴号
            //正式需要加上时间查询
            $luZhu = Luzhu::where(['status' => 1, 'table_id' => $v['id']])->whereTime('create_time', 'today')->select()->toArray();

            if (isset($luZhu['xue_number'])) {
                $infos[$k]['xue_number'] = $luZhu['xue_number'];
                continue;
            }
            $infos[$k]['xue_number'] = 1;
        }
        show($infos, 1);
    }

    // 获取统计数据
    public function get_table_count(): string
    {
        $params = $this->request->param();
        $map = array();
        $map['status'] = 1;
        if (!isset($params['tableId']) || !isset($params['xue']) || !isset($params['gameType'])) {
            show([], 0,'');
        }

        $map['table_id'] = $params['tableId'];
        $map['xue_number'] = $params['xue'];
        $map['game_type'] = $params['gameType']; // 代表骰宝

        $nowTime = time();
		$startTime = strtotime(date("Y-m-d 09:00:00", time()));
		// 如果小于，则算前一天的
		if ($nowTime < $startTime) {
		    $startTime = $startTime - (24 * 60 * 60);
		} else {
		    // 保持不变 这样做到 自动更新 露珠
		}

        // 暂时不知道统计什么 先空着
        $returnData = array();
        // 返回数据
        show($returnData, 1);
    }

    //获取发送的数据 荷官开牌
    public function set_post_data(): string
    {
        LogHelper::debug('=== 开牌流程开始 ===');
        LogHelper::debug('接收到荷官开牌请求', [
            'ip' => request()->ip(),
            'timestamp' => date('Y-m-d H:i:s')
        ]);


        $postField = 'gameType,tableId,xueNumber,puNumber,result,ext,pai_result';
        $params = $this->request->only(explode(',', $postField), 'param', null);
        LogHelper::debug('荷官原始参数', $params);

        try {
            validate(validates::class)->scene('lz_post')->check($params);
        } catch (ValidateException $e) {
            return show([], config('ToConfig.http_code.error'), $e->getError());
        }
        $map = array();
        $map['status'] = 1;
        $map['table_id'] = $params['tableId'];
        $map['xue_number'] = $params['xueNumber'];
        $map['pu_number'] = $params['puNumber'];
        $map['game_type'] = $params['gameType'];

        //查询当日最新的一铺牌
        $info = Luzhu::whereTime('create_time', 'today')->where('result','<>',0)->where($map)->find();
        if (!empty($info)) show($info, 0, '数据重复上传');

        #####开始预设###########
        //查询是否有预设的开牌信息
        $presetInfo = LuzhuPreset::LuZhuPresetFind($map);
        $map['update_time'] = $map['create_time'] = time();
        $HeguanLuzhu = $map;

        $id = 0;
        if ($presetInfo){
            //插入当前信息
            $id = $presetInfo['id'];
            $map['result'] = $presetInfo['result'];
            $map['result_pai'] = $presetInfo['result_pai'];
        }else{
            //插入当前信息
            $map['result'] = intval($params['result']) . '|' . intval($params['ext']);
            $map['result_pai'] = json_encode($params['pai_result']);
        }
        //荷官正常露珠
        $HeguanLuzhu['result'] = intval($params['result']) . '|' . intval($params['ext']);
        $HeguanLuzhu['result_pai'] = json_encode($params['pai_result']);
        #####结束预设###########


        // 增加缓存删除
        \think\facade\Cache::delete('luzhuinfo_'.$params['tableId']);

        // 根据游戏类型调用相应服务
        switch ($map['game_type']) {
            case 9:
                LogHelper::debug('调用骰宝开牌服务', ['table_id' => $map['table_id']]);
                $card = new CardSettlementService();
                return $card->open_game($map, $HeguanLuzhu, $id);
            default:
                LogHelper::error('不支持的游戏类型', ['game_type' => $map['game_type']]);
                show([], 404, 'game_type错误！');
        }
    }
    
    
   
    //删除指定露珠
    public function lz_delete(): string
    {
        $params = $this->request->param();
        $map = array();
        $map['table_id'] = $params['tableId'];
        $map['xue_number'] = $params['num_xue'];
        $map['pu_number'] = $params['num_pu'];
        Luzhu::where($map)->delete();
        show([], config('ToConfig.http_code.error'));
    }

    //清除一张台桌露珠
    public function lz_table_delete(): string
    {
        $table_id = $this->request->param('tableId', 0);
        if ($table_id <= 0) show([], config('ToConfig.http_code.error'), '台桌id错误');
        $del = Luzhu::where(['table_id' => $table_id])->delete();
        if ($del) show([]);
        show([], config('ToConfig.http_code.error'));
    }

    //设置靴号 荷官主动换靴号
    public function set_xue_number(): string
    {
        //过滤数据
        $postField = 'tableId,num_xue,gameType';
        $post = $this->request->only(explode(',', $postField), 'param', null);

        try {
            validate(validates::class)->scene('lz_set_xue')->check($post);
        } catch (ValidateException $e) {
            return show([], config('ToConfig.http_code.error'), $e->getError());
        }

        //取才创建时间最后一条数据
        $find = Luzhu::where('table_id', $post['tableId'])->order('id desc')->find();

        if ($find) {
            $xue_number['xue_number'] = $find->xue_number + 1;
        } else {
            $xue_number['xue_number'] = 1;
        }
        $post['status'] = 1;
        $post['table_id'] = $post['tableId'];
        $post['xue_number'] = $xue_number['xue_number'];
        $post['pu_number'] = 1;
        $post['update_time'] = $post['create_time'] = time();
        $post['game_type'] = $post['gameType'];
        $post['result'] = 0;
        $post['result_pai'] = 0;

        $save = (new Luzhu())->save($post);
        if ($save) show($post);
        show($post, config('ToConfig.http_code.error'));
    }

    //开局信号
    public function set_start_signal(): string
    {
        $table_id = $this->request->param('tableId', 0);

        // 请求远程更新用户的余额
        if(env('zonghepan.enable', false)) {
            $url = env('zonghepan.game_url', '0.0.0.0').RequestUrl::bet_result();            
            // 根据用户信息获取用户投注记录
            $user_id = $user['id'];
            $xue = xue_number($table_id);
            $map = [];
            $map['user_id'] = $user_id;
            $map['table_id'] = $table_id;
            $map['xue_number'] = $xue['xue_number'];
            $map['pu_number'] = $xue['pu_number'];
            $betRecord = Db::name('dianji_records')->where($map)->find();
            if (empty($betRecord)) {
                show([], config('ToConfig.http_code.error'), '没有投注记录');
            }else{
                $post_data = [];
                // user_name=XGlisi&betId=123&roundId=1&externalTransactionId=123&betAmount=23.56&winAmount=23.56&effectiveTurnover=23.56&jackpotAmount=23.56&resultType=END&isFreespin=1&isEndRound=0&betTime=11652545&settledTime=25684256&winLoss=23.56&gameCode=bjl_1
                $post_data['user_name'] = $user['user_name'];
                $post_data['betId'] = $betRecord['id'];
                $post_data['roundId'] = $xue['xue_number']; 
                $post_data['externalTransactionId'] = $betRecord['id']; //  
                $post_data['betAmount'] = $betRecord['bet_amt']; // 下注金额
                $post_data['winAmount'] = $betRecord['win_amt']; // 赢取金额
                $post_data['effectiveTurnover'] = $betRecord['bet_amt']; // 有效投注额
                $post_data['jackpotAmount'] = 0; // 奖池金额
                $post_data['resultType'] = "BET_WIN"; // 结果类型
                $post_data['isFreespin'] = 0;  // Integer (0,1) 用于指示是否是免费旋转。
                $post_data['isEndRound'] = 1; // Integer (0,1) 用于指示下注已完成的状态。
                $post_data['betTime'] = time() * 1000; // 毫秒时间戳
                $post_data['settledTime'] = time() * 1000; // 毫秒时间戳
                $post_data['winLoss'] = $betRecord['win_amt'] - $betRecord['bet_amt']; // 输赢金额
                $post_data['gameCode'] = 'bjl_'.$table_id; // 游戏代码
                $data = Curl::post($url, $post_data, []);
            }            
        }

        
        $time = $this->request->param('time', 45);
        if ($table_id <= 0) show([], config('ToConfig.http_code.error'), 'tableId参数错误');
        $data = [
            'start_time' => time(),
            'countdown_time' => $time,
            // 'status' => 1,
            'run_status' => 1,
            'wash_status'=>0,
            'update_time' => time(),
        ];
        $save = Table::where('id', $table_id)
            ->update($data);
        if (!$save) {
            show($data, config('ToConfig.http_code.error'));
        }
        $data['table_id'] = $table_id;
        Queue::later($time, TableEndTaskJob::class, $data,'sicbo_end_queue');
        redis()->del('table_info_'.$table_id);
        redis()->set('table_set_start_signal_'.$table_id,$table_id,$time+1);//储存redis
        show($data);
    }

    //结束信号
    public function set_end_signal(): string
    {
        $table_id = $this->request->param('tableId', 0);
        if ($table_id <= 0) show([], config('ToConfig.http_code.error'));
        $save = Table::where('id', $table_id)
            ->update([
                // 'status' => 1,
                'run_status' => 2,
                'wash_status'=>0,
                'update_time' => time(),
            ]);
        if ($save) show([]);
        show([], config('ToConfig.http_code.error'));
    }

    //台桌信息 靴号 铺号
    public function get_table_info()
    {
        $params = $this->request->param();
        $returnData = array();
        $info = Table::order('id desc')->find($params['tableId']);
        // 发给前台的 数据             
        $returnData['table_title'] = $info['table_title'];
        $returnData['lu_zhu_name'] = $info['lu_zhu_name'];
        $returnData['right_money_banker_player'] = $info['xian_hong_zhuang_xian_usd'];
        $returnData['right_money_banker_player_cny'] = $info['xian_hong_zhuang_xian_cny'];
        $returnData['right_money_tie'] = $info['xian_hong_he_usd'];
        $returnData['right_money_tie_cny'] = $info['xian_hong_he_cny'];
        $returnData['right_money_pair'] = $info['xian_hong_duizi_usd'];
        $returnData['right_money_pair_cny'] = $info['xian_hong_duizi_cny'];
        $returnData['video_near'] = $info['video_near'];
        $returnData['video_far'] = $info['video_far'];
        $returnData['time_start'] = $info['countdown_time'];

        // 获取最新的 靴号，铺号
        $xun = bureau_number($params['tableId'],true);
        $returnData['id'] = $info['id'];
        $returnData['num_pu'] = $xun['xue']['pu_number'];
        $returnData['num_xue'] = $xun['xue']['xue_number'];
        $returnData['bureau_number'] = $xun['bureau_number'];
        // 返回数据
        show($returnData, 1);
    }
    
    //台桌信息 靴号 铺号
    public function get_table_info_for_bet()
    {
        $params = $this->request->param();
        $returnData = array();
        $info = Table::order('id desc')->find($params['tableId']);
        // 获取最新的 靴号，铺号
        $xun = bureau_number($params['tableId'],true);
        $info['num_pu'] = $xun['xue']['pu_number'];
        $info['num_xue'] = $xun['xue']['xue_number'];
        // 返回数据
        show($info, 1);
    }

    //台桌信息
    public function get_table_wash_brand()
    {
        $tableId = $this->request->param('tableId',0);
        if ($tableId <=0 ) show([], config('ToConfig.http_code.error'),'台桌ID必填');
        $table  = Table::where('id',$tableId)->find();
        $status = $table->wash_status == 0 ? 1 : 0;
        $table->save(['wash_status'=>$status]);
        $returnData['result_info']  = ['table_info'=>['game_type'=>123456]];
        $returnData['money_spend']  = '';
        // worker_tcp('userall','洗牌中！',$returnData,207);
        // 返回数据
        show([], 1);
    }

}