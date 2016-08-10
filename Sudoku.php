<?php
error_reporting(-1);
declare(ticks = 1);

class Sudoku{
    protected $parent_pid = 0;
    protected $pid = 0;
    protected $children = array();

    protected $all_nums = array();
    protected $rows = array();
    protected $columns = array();
    protected $blocks = array();


    public function __construct($matrix){
        $this->register_ticks();

        $this->pid = $this->parent_pid = getmypid();

        $temp = explode("\n", $matrix);
        foreach($temp as $value){
            $this->all_nums = array_merge($this->all_nums, explode(',', $value));
        }

        $this->all_nums = array_map('intval', $this->all_nums);

        foreach($this->all_nums as $m => &$n){
            if($n == 0){
                $n = array(1, 2, 3, 4, 5, 6, 7, 8, 9);
            }
            $this->rows[intval($m / 9)][] = &$n;
            $this->columns[$m % 9][] = &$n;
            $this->blocks[intval(intval($m / 9) / 3) * 3 + intval(($m % 9) / 3)][] = &$n;
        }
    }

    /**
     * 计算数独
     */
    public function run(){
        //开始处理
        $this->dispose();

        //没完成开始猜
        $this->choice();

        //等待子进程
        $this->process_loop();
    }

    protected function dispose(){
        do{
            $update = false;
            foreach($this->all_nums as $m => &$n){
                if(!is_array($n)){
                    continue;
                }

                //通过已经确定的数字，筛选出每个空可以填的数
                $tmp = array_values(array_diff($n, array_filter($this->rows[intval($m / 9)], 'is_int'), array_filter($this->columns[$m % 9], 'is_int'), array_filter($this->blocks[intval(intval($m / 9) / 3) * 3 + intval(($m % 9) / 3)], 'is_int')));
                if(count($tmp) != count($n)){
                    $update = true;
                    switch(count($tmp)){
                        case 1:
                            $n = current($tmp);
                            //echo "第{$m}位填{$n}\n";
                            continue 2;
                        case 0:
                            //echo getmypid() . "空无数字可填\n";
                            exit;
                        default:
                            $n = $tmp;
                            //echo "第{$m}位填" . json_encode($n) . "\n";
                            break;
                    }
                }


                //通过尚未确定的数字，筛选出每个空可以填的数
                //当前空与其他空是否有不同的数字
                $blocks_maybe_arr = $this->get_other_space_maybe_arr($this->blocks[intval(intval($m / 9) / 3) * 3 + intval(($m % 9) / 3)], ($m % 9) % 3 + (intval($m / 9) % 3) * 3);
                $rows_maybe_arr = $this->get_other_space_maybe_arr($this->rows[intval($m / 9)], $m % 9);
                $columns_maybe_arr = $this->get_other_space_maybe_arr($this->columns[$m % 9], intval($m / 9));
                $tmp = array_values(array_diff($n, $blocks_maybe_arr, $rows_maybe_arr, $columns_maybe_arr));
                switch(count($tmp)){
                    case 0:
                        break;
                    case 1:
                        $update = true;
                        $n = current($tmp);
                        //echo "第{$m}位填{$n}\n";
                        continue 2;
                    default:
                        //echo getmypid() . "值比空多\n";
                        exit;

                }

                //处理相同后选值的空
                $update |= $this->dispose_same_maybe_space($this->blocks[intval(intval($m / 9) / 3) * 3 + intval(($m % 9) / 3)], ($m % 9) % 3 + (intval($m / 9) % 3) * 3);
                $update |= $this->dispose_same_maybe_space($this->rows[intval($m / 9)], $m % 9);
                $update |= $this->dispose_same_maybe_space($this->columns[$m % 9], intval($m / 9));

            }
        }while($update && !$this->is_complete());
    }

    /**
     * 选则一个可选值最少的空，开始猜
     */
    protected function choice(){
        $i = 10;

        foreach($this->all_nums as $key => $v){
            if(is_array($v) && count($v) < $i){
                $i = count($v);
                $choice = $key;
            }
        }

        if(!isset($choice)){
            //echo getmypid() . "出错了\n";
            exit;
        }
        $maybe = $this->all_nums[$choice];
        //echo getmypid() . '选择' . $choice . '=' . print_r($maybe, true) . "\n";
        foreach($maybe as $value){
            $pid = pcntl_fork();
            switch($pid){
                case 0:
                    //echo getmypid() . "启动\n";
                    $this->parent_pid = $this->pid;
                    $this->pid = getmypid();
                    $this->children = array();
                    $this->all_nums[$choice] = $value;
                    $this->run();
                    //echo getmypid() . "run 完了\n";
                    exit;
                    break;
                case -1:
                    echo "fork failed\n";
                    posix_kill($this->parent_pid, SIGUSR1);
                    break 2;
                default:
                    $this->children[$pid] = "the process set \$all_nums[$choice] = $value";
            }
        }
    }


    /**
     * 取得其他空的可能数值
     * @param $arr
     * @param $index
     * @return array
     */
    protected function get_other_space_maybe_arr($arr, $index){
        unset($arr[$index]);
        $res = array();
        foreach($arr as $x => $y){
            if(is_array($y)){
                $res = array_merge($res, $y);
            }
        }
        $res = array_values(array_unique($res));

        return $res;
    }

    /**
     * 处理相同后选值的空
     * 如果有三个空可以填三个同样的数字，那其他空一定不能填这三个数字
     */
    protected function dispose_same_maybe_space(&$arr, $index){
        $same_key = array_keys($arr, $arr[$index]);

        if(count($same_key) < count($arr[$index])){
            return false;
        }elseif(count($same_key) > count($arr[$index])){
            //echo getmypid() . "空比值多\n";
            exit;
        }

        $update = false;
        for($i = 0; $i < 9; $i++){
            if(!is_array($arr[$i]) || in_array($i, $same_key)){
                continue;
            }

            $tmp = array_values(array_diff($arr[$i], $arr[$index]));
            if(count($tmp) != count($arr[$i])){
                $update = true;
                if(count($tmp) == 1){
                    $arr[$i] = current($tmp);
                }else{
                    $arr[$i] = $tmp;
                }
            }
        }

        return $update;
    }

    /**
     * 是否完成
     * @return bool
     */
    protected function is_complete(){
        foreach($this->all_nums as $v){
            if(empty($v)){
                //echo getmypid() . "空无数字可填\n";
                exit;
            }elseif(!is_int($v)){
                $return = 0;
            }
        }

        if(isset($return)){
            return 0;
        }

        foreach(array($this->rows, $this->columns, $this->blocks) as $m){
            foreach($m as $n){
                if(count(array_unique($n)) != count($n)){
                    //echo getmypid() . "有数字填重复了\n";
                    exit;
                }
            }
        }

        //完成了打印出来，并通知父进程成功了
        $this->output($this->all_nums);
        posix_kill($this->parent_pid, SIGUSR2);
        exit;
    }

    /**
     * 输出
     */
    protected function output(){
        for($i = 0; $i < 81; $i++){
            if(is_array($this->all_nums[$i])){
                echo json_encode($this->all_nums[$i]);
            }else{
                echo $this->all_nums[$i];
            }
            if($i % 9 == 8){
                echo "\n";
            }else{
                echo ',';
            }
        }
    }

    /**
     * 信号处理
     * @param $signal
     */
    protected function signal($signal){
        switch($signal){
            case SIGUSR1://用于程序出错通知
            case SIGUSR2://用于计算出结果通知
                //echo getmypid() . "收到成功消息\n";
                if($this->parent_pid == getmypid()){
                    //是主进程，则开始停止子进程
                    $this->stop_children();
                }else{
                    //是子进程继续通知父进程
                    posix_kill($this->parent_pid, $signal);
                }
                break;
            case SIGINT:
            case SIGTERM:
                //echo getmypid() . "收到退出命令\n";
                if(empty($this->children)){
                    //没有子进程，则退出
                    exit;
                }else{
                    //有子进程，则继续停子进程
                    $this->stop_children();
                }
                break;
            case SIGHUP:
                if($this->parent_pid == getmypid()){
                    $this->stop_children();
                }
                break;
            default:
                // handle all other signals
        }

    }

    /**
     * 注册信号处理
     */
    protected function register_ticks(){
        pcntl_signal(SIGTERM, array($this, 'signal'));
        pcntl_signal(SIGINT, array($this, 'signal'));
        pcntl_signal(SIGUSR1, array($this, 'signal'));
        pcntl_signal(SIGUSR2, array($this, 'signal'));
        pcntl_signal(SIGCONT, array($this, 'signal'));
        pcntl_signal(SIGHUP, array($this, 'signal'));
    }

    /**
     * 停止子进程
     * @param int $signal
     */
    protected function stop_children($signal = SIGTERM){
        foreach($this->children as $pid => $child){
            posix_kill($pid, $signal);
        }
    }

    /**
     * 等待
     */
    protected function process_loop(){
        while(count($this->children)){
            //echo getmypid() . print_r($this->children, true);
            $pid = pcntl_wait($status, WNOHANG);
            if($pid && isset($this->children[$pid])){
                //echo $pid . "退出\n";
                unset($this->children[$pid]);
            }
            usleep(50000);
        }
        //echo getmypid() . "结束\n";
        exit;
    }
}