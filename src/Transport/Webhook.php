<?php
namespace KitsuneTech\Velox\Transport;

use KitsuneTech\Velox\Structures\Model as Model;
use function KitsuneTech\Velox\Transport\Export;

class WebhookResponse {
    public function __construct(public string $text, public int $code){}
}
function WebhookExport(Model|array $models, int $contentType, array $subscribers, int $retryInterval = 30, int $retryAttempts = 10, callable $callback = null, callable $errorHandler = null) : void {
    function sendRequest($payload, $contentType, $url) : WebhookResponse {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); //WebhookExport automatically follows 3xx redirects
        curl_setopt($ch, CURLOPT_POSTREDIR, CURL_REDIR_POST_ALL); //POST should be maintained through redirects
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: ' . $contentType,
            'Content-Length: ' . strlen($payload)
        ]);
        $result = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return new WebhookResponse($result,$code);
    }
    $payload = Export($models,TO_STRING+$contentType);
    $contentTypeHeader = "Content-Type: ";
    switch ($contentType){
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
    foreach ($subscribers as $subscriber){
        //If we can, fork a process for each subscriber to keep laggy subscribers from holding up the others (if we can't, just work each one sequentially)
        $pid = function_exists('pcntl_fork') ? pcntl_fork() : -1;
        $success = false;
        if ($pid < 1){
            $response = sendRequest($payload,$contentTypeHeader,$subscriber);
            $responseCode = $response->code;
            if ($responseCode >= 400){
                $retryCount = 0;
                if (isset($errorHandler)){
                    $errorHandler($subscriber, $responseCode, 1, $response->text);
                }
                //Use exponential backoff for retries
                while ($retryCount < $retryAttempts){
                    sleep((2 ** $retryCount) * $retryInterval);
                    $response = sendRequest($payload,$contentTypeHeader,$subscriber);
                    $responseCode = $response->code;
                    if ($responseCode < 400){
                        $success = true;
                        break;
                    }
                    if (isset($errorHandler)){
                        $errorHandler($subscriber, $responseCode, $retryCount+1, $response->text);
                    }
                    $retryCount++;
                }
            }
            else {
                $success = true;
            }
            if (isset($callback)){
                $callback($subscriber, $success, $response->text);
            }
            if ($pid == 0) break;
        }
    }
}
