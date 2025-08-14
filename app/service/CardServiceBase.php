<?php

namespace app\service;
use app\controller\common\LogHelper;
use think\facade\Db;

/**
 * ========================================
 * 卡牌游戏基础服务类
 * ========================================
 * 
 * 功能说明：
 * - 提供卡牌游戏的基础功能服务
 * - 处理开牌信息的缓存读取
 * - 管理用户派彩金额的临时存储
 * - 记录开牌历史数据
 * 
 * 使用场景：
 * - Workerman推送开牌结果时调用
 * - 用户查询派彩结果时调用
 * - 开牌数据持久化存储
 * 
 * @package app\service
 * @author  系统开发团队
 */
class CardServiceBase
{
    /**
     * ========================================
     * 从Redis获取开牌展示信息
     * ========================================
     * 
     * 从Redis缓存中读取指定台桌的开牌数据，并根据游戏类型
     * 调用相应的解析服务生成前端显示所需的牌面信息
     * 
     * Redis存储说明：
     * - Key格式: table_id_{台桌ID}_{游戏类型}
     * - 存储时间: 开牌后5秒（用于实时推送）
     * - 数据格式: JSON字符串，包含所有牌面信息
     * 
     * @param int $table_id 台桌ID
     * @param int $game_type 游戏类型 (1=牛牛, 2=龙虎, 3=骰宝)
     * @return array|false 开牌信息数组，无数据时返回false
     */
    public function get_pai_info($table_id, $game_type)
    {
        // 构建Redis键名
        $redis_key = 'table_id_' . $table_id . '_' . $game_type;
        
        // 从Redis获取开牌数据
        $pai_data = redis()->get($redis_key);
        
        // 检查数据是否存在
        if (empty($pai_data)) {
            return false;
        }
        
        // 用一次删除 
        redis()->del($redis_key);
        // 初始化开牌服务
        $service = new WorkerOpenPaiService();
        
        // 根据游戏类型调用相应的处理方法
        switch ($game_type) {
            case 9: // 骰宝游戏
                return $service->get_pai_info_sicbo($pai_data);
                break;
                
            default:
                // 未知游戏类型
                return [];
        }
        
        return [];
    }

    /**
     * ========================================
     * 获取用户派彩金额
     * ========================================
     * 
     * 从Redis中获取指定用户在指定台桌的派彩金额
     * 获取后立即删除，确保派彩金额只能领取一次
     * 
     * Redis存储说明：
     * - Key格式: user_{用户ID}_table_id_{台桌ID}_{游戏类型}
     * - 存储时间: 开牌结算后5秒（用于派彩显示）
     * - 数据内容: 用户本局的输赢金额（正数=赢钱，负数=输钱）
     * 
     * @param int $user 用户ID
     * @param int $table_id 台桌ID
     * @param int $game_type 游戏类型
     * @return int|false 派彩金额，无数据时返回false
     */
    public function get_payout_money($user, $table_id, $game_type)
    {
        // 构建Redis键名
        $redis_key = 'user_' . $user . '_table_id_' . $table_id . '_' . $game_type;
        
        // 从Redis获取派彩金额
        $money = redis()->get($redis_key);
        
        // 检查数据是否存在（注意：金额可能为0，所以用===判断）
        if ($money === null) {
            return false;
        }
        
        // 获取后立即删除，防止重复领取
        redis()->del($redis_key);
        
        return $money;
    }

    /**
     * ========================================
     * 记录开牌信息到数据库
     * ========================================
     * 
     * 将开牌结果和对应的露珠ID保存到开牌记录表中
     * 用于历史查询和数据分析
     * 
     * 数据表说明：
     * - 表名: dianji_lu_zhu_open_pai
     * - 字段: open_pai(开牌结果JSON), luzhu_id(露珠记录ID)
     * - 用途: 开牌历史查询、数据统计分析
     * 
     * @param string $pai_result 开牌结果JSON字符串
     * @param int $id 露珠记录ID
     * @return void
     */
    public function get_open_pai_info($pai_result, $id)
    {
        // 组装插入数据
        $pai = [
            'open_pai'  => $pai_result,  // 开牌结果JSON数据
            'luzhu_id'  => $id           // 关联的露珠记录ID
        ];
        
        // 插入到开牌记录表
        Db::name('dianji_lu_zhu_open_pai')->insert($pai);
    }
}

