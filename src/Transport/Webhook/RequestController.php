<?php
namespace KitsuneTech\Velox\Transport\Webhook;
use KitsuneTech\Velox\Structures\Model as Model;
use KitsuneTech\Velox\VeloxException;
use function KitsuneTech\Velox\Transport\Export;

class RequestController {
    private static array $instances = [];
    private int $instanceKey = 0;
    public ?\Closure $callback = null;
    public ?\Closure $errorHandler = null;
    private $process = null;
    private $dispatchPID = null;
    private array $pipes = [];
    private ?string $payloadFile = null;

    function __construct(private Model|array &$models, public array $subscribers = [], public int $contentType = AS_JSON, public int $retryInterval = 5, public int $retryAttempts = 10, public $identifier = null, public $processName = null){
        if (count(self::$instances) == 0){
            //Initialize the signal handlers
            pcntl_async_signals(true);
            pcntl_signal(SIGUSR1,[self,"signalReceived"]);
            pcntl_signal(SIGUSR2,[self,"signalReceived"]);
        }
        $this->instanceKey = spl_object_id($this);
        self::$instances[$this->instanceKey] = $this;
    }
    public static function signalReceived(int $signo, mixed $siginfo) : void
    {
        //Find the instance having the dispatchPID that matches the PID of the process that sent the signal
        $targetInstance = null;
        foreach (self::$instances as $inst) {
            if ($inst->dispatchPID == $siginfo["pid"]) {
                $targetInstance = $inst;
                break;
            }
        }
        if (!$targetInstance) {
            //Could not find the matching instance, so leave
            return;
        }
        switch ($signo) {
            case SIGUSR1:
                //Pipe data received
                $targetInstance->pipeReceived();
                break;
            case SIGUSR2:
                //Process closed
                $targetInstance->close();
                break;
        }
    }
    public function pipeReceived() : void {
        $pipesArray = $this->pipes;
        if (count($pipesArray) == 0) {
            //Pipes are closed, so there's nothing to do here
            return;
        }

        $empty = null;
        $readyCount = stream_select($pipesArray, $empty, $empty, 0);
        if ($readyCount === false) {
            //stream_select() can't be used on proc_open() pipes in Windows. TODO: implement a workaround for Windows.
        }
        elseif ($readyCount > 0) {
            foreach ($pipesArray as $key => $pipe) {
                switch ($key) {
                    case 1: //STDOUT
                    case 2: //STDERR
                        echo stream_get_contents($pipe);
                        break;
                    case 3: // Success pipe
                        $data = json_decode(stream_get_contents($pipe));
                        if ($data && $this->callback) {
                            ($this->callback)($data);
                        }
                        break;
                    case 4: // Error pipe
                        $data = json_decode(stream_get_contents($pipe));
                        if ($data && $this->errorHandler) {
                            ($this->errorHandler)($data);
                        }
                        break;
                    case 5:
                        $data = stream_get_contents($pipe);
                        if ($data) {
                            $this->close();
                        }
                        break;
                }
            }
        }
    }
    public function setCallback(callable $callback) : void {
        $callback = \Closure::fromCallable($callback);
        $this->callback = $callback->bindTo($this);
    }
    public function setErrorHandler(callable $errorHandler) : void {
        $errorHandler = \Closure::fromCallable($errorHandler);
        $this->errorHandler = $errorHandler->bindTo($this);
    }
    public function setSubscribers(array $subscribers) : void {
        $this->subscribers = $subscribers;
    }
    public function dispatch() : void {
        //Write the exported Model data to a file
        $this->payloadFile = @tempnam(sys_get_temp_dir(), "vx-webhook-"); //Normally using @ is a bad idea, but we don't care if tempnam falls back to the system default, so we're suppressing that warning
        if (!$this->payloadFile){
            throw new VeloxException("Unable to create temporary file for webhook payload", 66);
        }
        Export($this->models, TO_FILE+$this->contentType, location: $this->payloadFile, noHeader: true);
        $contentTypeHeader = "Content-Type: ";
        switch ($this->contentType){
            case AS_JSON:
                $contentTypeHeader .= "application/json";
                break;
            case AS_XML:
                $contentTypeHeader .= "application/xml";
                break;
            case AS_HTML:
                $contentTypeHeader .= "text/html";
                break;
            case AS_CSV:
                $contentTypeHeader .= "text/csv";
                break;
        }
        //Build CLI command
        // -c: Content type header
        // -a: Retry attempts
        // -r: Retry interval
        // -i: Identifier
        // -p: Payload
        // Subscriber URLs are appended after options above
        $dispatchScript = __DIR__ . "/../../Support/AsyncWebhookDispatch.php";
        $identifierOption = $this->identifier ? " -i" . escapeshellarg($this->identifier) : "";
        $command = "php $dispatchScript -p".getmypid()." -c'$contentTypeHeader' -a".$this->retryAttempts . " -r" . $this->retryInterval . $identifierOption. " -f" . escapeshellarg($this->payloadFile) . " " . implode(" ",array_map("escapeshellarg",$this->subscribers));
        // php://fd/3 is the success pipe
        // php://fd/4 is the error pipe
        // php://fd/5 is the process exit pipe
        $this->process = proc_open($command,[
            0 => ["pipe","r"],
            1 => ["pipe","w"],
            2 => ["pipe","w"],
            3 => ["pipe","w"],
            4 => ["pipe","w"],
            5 => ["pipe","w"]
        ],$this->pipes);
        $this->dispatchPID = proc_get_status($this->process)["pid"];
        foreach ($this->pipes as $pipe){
            stream_set_blocking($pipe,false);
        }
        if (!is_resource($this->process)){
            throw new VeloxException("Unable to start webhook dispatcher", 67);
        }
    }
    public function close() : void {
        foreach ($this->pipes as $pipe){
            fclose($pipe);
        }
        $this->pipes = [];
        if (is_resource($this->process)){
            proc_close($this->process);
        }
        if (file_exists($this->payloadFile)){
            unlink($this->payloadFile);
        }
        //If this is the last instance, remove the signal handlers
        if (count(self::$instances) == 1){
            pcntl_signal(SIGUSR1, SIG_DFL);
            pcntl_signal(SIGUSR2, SIG_DFL);
        }
        //Remove this instance from the list of instances using $this->instanceKey as the key
        unset(self::$instances[$this->instanceKey]);
    }
    public function __destruct() {
        $this->close();
    }
}