<?php
namespace KitsuneTech\Velox\Transport\Webhook;

use KitsuneTech\Velox\Structures\Model as Model;
use KitsuneTech\Velox\Transport\Webhook\Response as Response;
use function KitsuneTech\Velox\Transport\Export;

class Request {
    public ?\Closure $callback = null;
    public ?\Closure $errorHandler = null;
    function __construct(private Model|array &$models, public array $subscribers = [], public int $contentType = AS_JSON, public int $retryInterval = 5, public int $retryAttempts = 10, public $identifier = null){}
    public function setCallback(callable $callback) : void {
        $this->callback = \Closure::fromCallable($callback);
    }
    public function setErrorHandler(callable $errorHandler) : void {
        $this->errorHandler = \Closure::fromCallable($errorHandler);
    }
    public function setSubscribers(array $subscribers) : void {
        $this->subscribers = $subscribers;
    }
    private function sendRequest(string $payload, string $url, string $contentTypeHeader) : Response {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); //WebhookExport automatically follows 3xx redirects
        curl_setopt($ch, CURLOPT_POSTREDIR, CURL_REDIR_POST_ALL); //POST should be maintained through redirects
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: ' . $contentTypeHeader,
            'Content-Length: ' . strlen($payload)
        ]);
        $result = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return new Response($result,$code);
    }
    public function dispatch() : void {
        $payload = Export($this->models, TO_STRING+$this->contentType, noHeader: true);
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
        foreach ($this->subscribers as $subscriber){
            //If we can, fork a process for each subscriber to keep laggy subscribers from holding up the others (if we can't, just work each one sequentially)
            $pid = function_exists('pcntl_fork') ? pcntl_fork() : -1;
            $success = false;
            if ($pid < 1){
                $response = $this->sendRequest($payload,$subscriber,$contentTypeHeader);
                $responseCode = $response->code;
                if ($responseCode >= 400){
                    $retryCount = 0;
                    if (isset($errorHandler)){
                        $errorHandler($subscriber, $responseCode, 1, $response->text, $this->identifier);
                    }
                    //Use exponential backoff for retries
                    while ($retryCount < $this->retryAttempts){
                        sleep((2 ** $retryCount) * $this->retryInterval);
                        $response = $this->sendRequest($payload,$subscriber,$contentTypeHeader);
                        $responseCode = $response->code;
                        if ($responseCode < 400){
                            $success = true;
                            break;
                        }
                        if (isset($this->errorHandler)){
                            $this->errorHandler->call($this, $subscriber, $responseCode, $retryCount+2, $response->text, $this->identifier);
                        }
                        $retryCount++;
                    }
                }
                else {
                    $success = true;
                }
                if (isset($this->callback)){
                    $this->callback->call($this, $subscriber, $success, $response->text, $this->identifier);
                }
                if ($pid == 0) break;
            }
        }
    }
}