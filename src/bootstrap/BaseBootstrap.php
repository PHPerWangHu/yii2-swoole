<?php
/**
 * Created by PhpStorm.
 * User: tsingsun
 * Date: 2017/9/6
 * Time: 下午3:45
 */

namespace tsingsun\swoole\bootstrap;

use Swoole\Http\Request as SwooleRequest;
use tsingsun\swoole\di\Container;
use tsingsun\swoole\di\ContainerDecorator;
use tsingsun\swoole\di\Context;
use tsingsun\swoole\server\Server;
use Yii;

abstract class BaseBootstrap implements BootstrapInterface
{
    public $index = '/index.php';
    /**
     * @var Server
     */
    protected $server;

    public $appConfig;

    /**
     * @var callable
     */
    public $init;
    /**
     * @var int worker线程的ID
     */
    protected $workerId;
    /**
     * @var Container
     */
    protected static $container;

    public function __construct(Server $server = null)
    {
        $this->server = $server;
        $this->init();
    }

    public function init()
    {
    }

    public static function getContainer()
    {
        return static::$container;
    }

    public function getServer()
    {
        return $this->server;
    }

    /**
     * 在该方法中实际处理请求
     * @param $request
     * @param $response
     * @return mixed
     */
    public abstract function handleRequest($request, $response);

    /**
     * 根据请求,构建Yii的环境变量
     * @param SwooleRequest $request
     * @return mixed
     */
    protected function initRequest($request)
    {
        //websocket时,$request为null
        if ($request) {
            $request->get = isset($request->get) ? $request->get : [];
            $request->post = isset($request->post) ? $request->post : [];
            $request->header = isset($request->header) ? $request->header : [];
            $request->cookie = isset($request->cookie) ? $request->cookie : [];
            $request->files = isset($request->files) ? $request->files : [];
            $request->server = isset($request->server) ? $request->server : [];
            $request->server['REQUEST_URI'] = isset($request->server['request_uri']) ? $request->server['request_uri'] : '';

            $request->server = array_change_key_case($request->server, CASE_UPPER);
        }
    }

    public function onWorkerStart($server, $worker_id)
    {
        $this->workerId = $worker_id;
        $initFunc = $this->init;
        if ($initFunc instanceof \Closure) {
            $initFunc($this);
        }

        $this->initServerVars();
        //在进程中保持引用关系,使持久化类不受context->removeCoroutineData影响,而被回收
        self::$container = new Container();
        Yii::$container = new ContainerDecorator();
        Yii::$context = new Context();
    }

    /**
     * @inheritdoc
     */
    public function onRequest($request, $response)
    {
        try {
            $this->initRequest($request);
            //每次都初始化容器,以做协程隔离
            Yii::$context->setContainer(new Container());
            return $this->handleRequest($request, $response);
        } catch (\Throwable $throwable) {
            //handleRequest要求做导常处理,到外层已经很少了.
            throw $throwable;
        } finally {
            $this->onRequestEnd();
            Yii::$context->removeCurrentCoroutineData();
        }

    }

    /**
     * @inheritdoc
     */
    public function onRequestEnd()
    {
        $logger = Yii::getLogger();
        $logger->flush();
    }

    public function onWorkerError($swooleServer, $workerId, $workerPid, $exitCode, $sigNo)
    {
        Yii::error("worker error happening [workerId=$workerId, workerPid=$workerPid, exitCode=$exitCode, signalNo=$sigNo]...", 'monitor');
    }


    public function onTask($server, $taskId, $srcWorkerId, $data)
    {
        $func = array_shift($data);
        if (is_callable($func)) {
            $params[] = array_shift($data);
            call_user_func_array($func, $params);
        }
        return 1;
    }

    public function onFinish($server, $taskId, $data)
    {
        //echo $data;
    }

    /**
     * @param \Swoole\Server $server
     * @param $worker_id
     */
    public function onWorkerStop($server, $worker_id)
    {
        $logger = Yii::getLogger();
        $logger->flush(true);
    }

    /**
     * 初始化Yii框架所需要的$_SERVER变量以及执行过程中所需要的通用变量
     */
    protected function initServerVars()
    {
        //使application运行时不会报错
        $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] = $_SERVER['DOCUMENT_URI'] = $this->index;
        $_SERVER['SCRIPT_FILENAME'] = ($this->server ? $this->server->root : '') . $this->index;

        $_SERVER['WORKER_ID'] = $this->workerId;
    }
}