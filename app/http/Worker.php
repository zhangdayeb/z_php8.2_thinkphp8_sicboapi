<?php
use app\controller\common\LogHelper;
use Workerman\Worker;
use \Workerman\Lib\Timer;
use \app\service\CardSettlementService;
use app\model\Table;
require_once __DIR__ . '/../../vendor/autoload.php';

// 初始化一个worker容器，监听1234端口
$worker = new Worker(env('worker.one', 'websocket://0.0.0.0:2009'));
// ====这里进程数必须必须必须设置为1====
$worker->count = 1;
// 新增加一个属性，用来保存uid到connection的映射(uid是用户id或者客户端唯一标识)
$worker->uidConnections = array();
// 当有客户端发来消息时执行的回调函数
$worker->onMessage = function ($connection, $data) {
    if($data == 'ping'){
        return $connection->send('pong');
    }
    global $worker;
    static $request_count;
    // 业务处理略
    if (++$request_count > 10000) {
        // 请求数达到10000后退出当前进程，主进程会自动重启一个新的进程
        Worker::stopAll();
    }

    $data = json_decode($data, true);
    // 判断当前客户端是否已经验证,即是否设置了uid
    if (!isset($connection->uid)) {
        // 获取右侧台桌信息 

        // 原先的逻辑
        $connection->lastMessageTime = time();

        if (!isset($data['user_id']) || empty($data['user_id'])) {
            return $connection->send('连接成功，userId错误');
        }
        if (!isset($data['table_id']) || !isset($data['game_type'])) {
            return $connection->send('连接成功，参数错误');
        }

        //绑定uid
        $data['user_id'] = $connection->uid = $data['user_id'] == 'null__' ? rand(10000,99999): $data['user_id'];
        $connection->data_info = $data;
        $worker->uidConnections[$connection->uid] = $connection;


        //前端逻辑变化，这里就不发连接成功，改为发送台桌信息过去
        $WorkerOpenPaiService = new \app\service\WorkerOpenPaiService();
        $user_id = intval(str_replace('_', '', $data['user_id']));

        if ($user_id) {
            $table_info['table_run_info'] = $WorkerOpenPaiService->get_table_info($data['table_id'], $user_id);
        } else {
            $table_info['table_run_info'] = [];
        }

        return $connection->send(json_encode(['code' => 200, 'msg' => '成功', 'data' => $table_info]));
    }

    if (isset($data['code'])) {
        $user_id = str_replace('_', '', $data['user_id']);
        $msg = '';
        if (isset($data['msg'])) {
            $msg = $data['msg'];
        }
        $array = ['code' => $data['code'], 'msg' => $msg, 'data' => $data];
        //约定推送语音消息,user消息推送到台桌
        if ($data['code'] == 205){
            $user_id .='_';
            $ret = sendMessageByUid($user_id, json_encode($array));
            return  $connection->send($ret ? 'ok' : 'fail');// 返回推送结果
        }
        //推送消息到 视频页面
        sendMessageByUid($user_id, json_encode($array));
        return $connection->send(json_encode($array));
    }

};

// 添加定时任务 每秒发送
$worker->onWorkerStart = function ($worker) {

    // 每秒执行的倒计时 
    Timer::add(1, function () use ($worker) {
        // 获取台桌开牌信息
        $newOpen = new CardSettlementService();
        // 每秒遍历所有的链接用户
        foreach ($worker->connections as $key => &$connection) {
            // 获取链接用户数据 主要是用户user_id table_id game_type
            $data = isset($connection->data_info) ? $connection->data_info : '';
            if (empty($data)) { 
                //没有用户数据 直接退出  // 上次通讯时间间隔大于心跳间隔，则认为客户端已经下线，关闭连接
                continue;
            }
            // 获取用户ID
            $user_id = intval(str_replace('_', '', $data['user_id']));
            $WorkerOpenPaiService = new \app\service\WorkerOpenPaiService();


            // 情况1： 发送给 前端用户 倒计时信号
            if (redis()->get('table_set_start_signal_' . $data['table_id'])) {
                // 存在倒计时 开始的信号  不停的 发送 倒计时
                $table_info_time = redis()->get('table_info_' . $data['table_id']);
                if (empty($table_info_time)) {
                    $table_info['table_run_info'] = $WorkerOpenPaiService->get_table_info($data['table_id'], $user_id);
                    redis()->set('table_info_' . $data['table_id'], json_encode($table_info['table_run_info']), $table_info['table_run_info']['end_time'] + 8);
                } else {
                    $info = json_decode($table_info_time, true);
                    $table_info['table_run_info'] = Table::table_opening_count_down_time($info);
                }
                $connection->send(json_encode(['code' => 200, 'msg' => '倒计时信息', 'data' => $table_info]));
                continue;
            }
            
            // 情况2： 发送给 前端用户 开牌信号
            $pai_result = [];
            $pai_result = $newOpen->get_pai_info($data['table_id'], $data['game_type']);
            if (!empty($pai_result)){
                $pai_result['table_info'] = $data;
                $connection->send(json_encode([
                    'code' => 200, 'msg' => '开牌信息',
                    'data' => ['result_info' => $pai_result, 'bureau_number' => bureau_number($data['table_id'])],
                ]));
                continue;
            } 
            
            // 情况3： 发送给 前端用户 中奖信息 
            $pai_result = [];
            $money = $newOpen->get_payout_money($user_id, $data['table_id'], $data['game_type']);
            if ($money){
                $pai_result['money'] = $money;
                $connection->send(json_encode([
                    'code' => 200, 'msg' => '中奖信息',
                    'data' => ['result_info' => $pai_result, 'bureau_number' => bureau_number($data['table_id'])],
                ]));
                continue;
            } 

        }
    });
};


// 当有客户端连接断开时
$worker->onClose = function ($connection) {
    global $worker;
    if (isset($connection->uid)) {
        $connection->close();
        // 连接断开时删除映射
        unset($worker->uidConnections[$connection->uid]);
        echo "断开连接";
    }
};

// 向所有验证的用户推送数据
function broadcast($message)
{
    global $worker;
    foreach ($worker->uidConnections as $connection) {
        $connection->send($message);
    }
}

// 针对uid推送数据
function sendMessageByUid($uid, $message)
{
    global $worker;
    if (isset($worker->uidConnections[$uid])) {
        $connection = $worker->uidConnections[$uid];
        $connection->send($message);
        return true;
    }
    return false;
}

// 运行所有的worker（其实当前只定义了一个）
Worker::runAll();