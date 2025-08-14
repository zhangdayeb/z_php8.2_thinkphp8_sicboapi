<?php
declare (strict_types = 1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;

class Worker extends Command
{
    protected function configure()
    {
        $this->setName('ws')
            ->addArgument('action', Argument::OPTIONAL, 'start|stop|restart|reload|status', 'start')
            ->addArgument('option', Argument::OPTIONAL, '-d')
            ->setDescription('Start WebSocket Worker Server');
    }

    protected function execute(Input $input, Output $output)
    {
        $action = $input->getArgument('action') ?: 'start';
        $option = $input->getArgument('option');
        
        // 设置命令行参数
        global $argv;
        $argv = ['worker', $action];
        if ($option === '-d') {
            $argv[] = '-d';
        }
        
        $output->writeln("Starting WebSocket Worker Server...");
        
        // 初始化框架组件
        $this->initializeFramework();
        
        // 执行 Worker.php
        require __DIR__ . '/../http/Worker.php';
    }
    
    /**
     * 初始化框架组件
     */
    protected function initializeFramework()
    {
        // 1. 数据库配置已自动加载
        echo "Database config loaded (MySQL: " . env('database.hostname') . "/" . env('database.database') . ")\n";
        
        // 2. 定义 redis() 函数 - 使用密码版本
        if (!function_exists('redis')) {
            function redis() {
                static $redis = null;
                if ($redis === null) {
                    try {
                        $redis = new \Redis();
                        
                        // 从 env 获取 Redis 配置
                        $host = env('redis.host', '127.0.0.1');
                        $port = intval(env('redis.port', 6379));
                        $password = env('redis.pwd', '');  // pwd 字段
                        
                        // 连接 Redis
                        if (!$redis->connect($host, $port)) {
                            throw new \Exception("Cannot connect to Redis at {$host}:{$port}");
                        }
                        
                        // 如果有密码则认证
                        if (!empty($password)) {
                            if (!$redis->auth($password)) {
                                throw new \Exception("Redis authentication failed");
                            }
                            echo "Redis connected to {$host}:{$port} (authenticated)\n";
                        } else {
                            echo "Redis connected to {$host}:{$port} (no auth)\n";
                        }
                        
                    } catch (\Exception $e) {
                        echo "Redis connection failed: " . $e->getMessage() . "\n";
                        echo "Using mock Redis object to prevent crashes\n";
                        
                        // 返回一个模拟对象，避免程序崩溃
                        $redis = new class {
                            public function __call($name, $arguments) {
                                return null;
                            }
                            public function get($key) { 
                                return null; 
                            }
                            public function set($key, $value, $ttl = null) { 
                                return false; 
                            }
                            public function setex($key, $ttl, $value) {
                                return false;
                            }
                        };
                    }
                }
                return $redis;
            }
        }
        
        // 3. 加载公共函数文件
        $commonFile = $this->app->getBasePath() . 'common.php';
        if (file_exists($commonFile)) {
            require_once $commonFile;
            echo "Common functions loaded.\n";
        }
        
        // 4. 定义 bureau_number 函数（如果不存在）
        if (!function_exists('bureau_number')) {
            function bureau_number($table_id) {
                // 生成局号：日期时间 + 台桌ID
                return date('YmdHis') . '_' . $table_id;
            }
        }
        
        // 5. 显示 Worker 配置信息
        if (env('zonghepan.enable')) {
            echo "综合盘已启用，游戏URL: " . env('zonghepan.game_url') . "\n";
        }
        
        echo "Framework initialization completed.\n";
        echo "----------------------------------------\n";
    }
}