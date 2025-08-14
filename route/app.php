<?php
use think\facade\Route;

// 获取露珠列表数据
Route::rule('bjl/get_table/get_data$', '/game.GetForeignTableInfo/get_lz_list');

// 获取荷官端露珠数据
Route::rule('bjl/get_table/get_hg_data$', '/game.GetForeignTableInfo/get_hg_lz_list');

// 获取电投端露珠数据
Route::rule('api/diantou/table/getData', '/game.GetForeignTableInfo/get_hg_data_list');

// 获取电投端视频流地址
Route::rule('api/diantou/table/getTableVideo', '/game.GetForeignTableInfo/get_hg_video_list');

// 获取台桌视频流信息
Route::rule('bjl/get_table/get_table_video', '/game.GetForeignTableInfo/get_table_video');

// 获取台桌列表
Route::rule('bjl/get_table/list$', '/game.GetForeignTableInfo/get_table_list');

// 获取台桌统计信息（庄闲和次数等）
Route::rule('bjl/get_table/get_table_count$', '/game.GetForeignTableInfo/get_table_count');

// 获取当前台桌详细信息（靴号、铺号等）
Route::rule('bjl/get_table/table_info$', '/game.GetForeignTableInfo/get_table_info');

// 获取当前台桌详细信息（全部信息）
Route::rule('bjl/table/info$', '/game.GetForeignTableInfo/get_table_info_for_bet');

// 获取单个用户详细信息 
Route::rule('bjl/user/info$', '/game.GetForeignTableInfo/get_user_info');

// 获取用户投注历史记录
Route::rule('bjl/bet/history$', '/game.GameInfo/get_user_bet_history');

// 获取投注记录详情
Route::rule('bjl/bet/detail/(\d+)$', '/game.GameInfo/get_bet_detail');

// 荷官手动开牌设置露珠数据
Route::rule('bjl/get_table/post_data$', '/game.GetForeignTableInfo/set_post_data');

// 获取扑克牌详细信息
Route::rule('bjl/pai/info$', '/game.GetForeignTableInfo/get_pai_info');

// 发送开局信号（开始投注倒计时）
Route::rule('bjl/start/signal$', '/game.GetForeignTableInfo/set_start_signal');

// 发送结束信号（停止投注）
Route::rule('bjl/end/signal$', '/game.GetForeignTableInfo/set_end_signal');

// 设置洗牌状态
Route::rule('bjl/get_table/wash_brand$', '/game.GetForeignTableInfo/get_table_wash_brand');

// 手动设置靴号（新一轮游戏开始）
Route::rule('bjl/get_table/add_xue$', '/game.GetForeignTableInfo/set_xue_number');

// 删除指定露珠记录
Route::rule('bjl/get_table/clear_lu_zhu$', '/game.GetForeignTableInfo/lz_delete');

// 清空指定台桌的所有露珠记录
Route::rule('bjl/get_table/clear_lu_zhu_one_table$', '/game.GetForeignTableInfo/lz_table_delete');

// 用户下注接口
Route::rule('bjl/bet/order$', '/order.Order/user_bet_order');

// 获取用户当前投注记录
Route::rule('bjl/current/record$', '/order.Order/order_current_record');

// 获取指定露珠的扑克牌型信息
Route::rule('bjl/game/poker$', '/game.GameInfo/get_poker_type');

// 测试露珠数据接口
Route::rule('api/test/luzhu', '/game.GetForeignTableInfo/testluzhu');

// 测试开牌数据设置接口  
Route::rule('bjl/get_table/post_data_test$', '/game.GetForeignTableInfo/set_post_data_test');

// 首页路由
Route::rule('/$', '/index/index');

/**
* ========================================
* 路由功能说明
* ========================================
* 
* 【核心术语解释】
* - 露珠(LuZhu)：记录每局游戏结果的历史数据表格，用于分析游戏趋势
* - 台桌(Table)：游戏桌，系统可同时运行多个台桌
* - 靴号(XueNumber)：一副新牌的编号，标识游戏场次
* - 铺号(PuNumber)：当前靴内的局数编号，从1开始递增
* - 荷官(Dealer)：负责发牌开牌的现场操作员
* - 电投(DianTou)：电子投注终端，供玩家远程投注
* - 百家乐(BJL)：主要游戏类型，庄家与闲家对战
* 
* 【游戏流程】
* 1. 荷官发送开局信号，启动投注倒计时
* 2. 玩家在倒计时期间进行投注下注
* 3. 倒计时结束后荷官开始发牌开牌
* 4. 系统根据牌面自动计算游戏结果
* 5. 进行投注结算，更新玩家账户余额
* 6. 将本局结果记录到露珠历史数据中
* 
* 【路由分类】
* - 露珠数据：历史记录查询和管理
* - 台桌信息：桌台状态和基础信息
* - 荷官操作：开牌、洗牌、信号控制
* - 用户投注：下注、记录查询
* - 系统测试：开发调试专用接口
*/