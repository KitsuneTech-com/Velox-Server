<?php
namespace KitsuneTech\Velox\Transport\Webhook;
use KitsuneTech\Velox\Structures\Model as Model;
use KitsuneTech\Velox\VeloxException;
use function KitsuneTech\Velox\Transport\Export;

class RequestController {
    public ?\Closure $callback = null;
    public ?\Closure $errorHandler = null;
    private $process = null;
    private array $pipes = [];
    private ?string $payloadFile = null;
    private \EventBase $base;
    private array $events = [];
    function __construct(private Model|array &$models, public array $subscribers = [], public int $contentType = AS_JSON, public int $retryInterval = 5, public int $retryAttempts = 10, public $identifier = null, public $processName = null){
        pcntl_async_signals(true);
        pcntl_signal(SIGPOLL,[$this,"pollHandler"]);
        $baseConfig = new \EventConfig();
        $baseConfig->requireFeatures(\EventConfig::FEATURE_FDS);
        $this->base = new \EventBase();
    }
    private function pollHandler(int $signo, mixed $siginfo) : void {
        print_r($siginfo);
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
        $command = "php $dispatchScript -c'$contentTypeHeader' -a".$this->retryAttempts . " -r" . $this->retryInterval . $identifierOption. " -p" . escapeshellarg($this->payloadFile) . " " . implode(" ",array_map("escapeshellarg",$this->subscribers));
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
        if (!is_resource($this->process)){
            throw new VeloxException("Unable to start webhook dispatcher", 67);
        }
        $this->events['information'] = new \Event($this->base, $this->pipes[1], \Event::READ | \Event::PERSIST, function($fd){
            echo stream_get_contents($fd);
        });
        $this->events['success'] = new \Event($this->base, $this->pipes[3], \Event::READ | \Event::PERSIST, function($fd){
            $data = json_decode(stream_get_contents($fd));
            $this->callback->call($this,$data);
        });
        $this->events['error'] = new \Event($this->base, $this->pipes[4], \Event::READ | \Event::PERSIST, function($fd){
            $data = json_decode(stream_get_contents($fd));
            $this->errorHandler->call($this,$data);
        });
        $this->events['dispatchend'] = new \Event($this->base, $this->pipes[5], \Event::READ | \Event::PERSIST, function($fd){
            //This pipe only gets written to when the dispatcher process exits. This means we're done.
            $this->base->exit();
        });
        foreach ($this->events as $event){
            $event->add(1);
        }
        $this->base->loop();
    }
    public function close() : void {
        foreach ($this->events as $event){
            $event->free();
        }
        while (count($this->pipes) > 0){
            $pipe = array_shift($this->pipes);
            fclose($pipe);
        }
        if ($this->process){
            proc_close($this->process);
        }
        if (file_exists($this->payloadFile)){
            unlink($this->payloadFile);
        }
    }
    public function __destruct() {
        $this->close();
    }
}