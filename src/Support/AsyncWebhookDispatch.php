<?php
require_once (__DIR__ . "/../Transport/Webhook/Response.php");
use KitsuneTech\Velox\Transport\Webhook\Response as Response;
// This script is intended to be run via exec() from the \Transport\Webhook\Request class, to enable independent asynchronous execution of webhooks.
// Forking is not used, as the database connection would be retained by the child process and would be killed when the first child exits.

// This script will write all results to STDOUT, which will be read and relayed by \Transport\Webhook\Request.

class asyncResponse {
    function __construct(
        public string $url,
        public string $payload,
        public string $response,
        public string $code,
        public string $identifier,
        public int $attemptCount
    ){}
}

function singleRequest(string $payload, string $url, string $contentTypeHeader) : Response {
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
function requestSession($payloadFile, $url, $contentTypeHeader, $retryAttempts, $retryInterval, $identifier) : void {
    global $successPipe, $errorPipe;
    echo "Opening request for event $identifier to $url...\n";
    $payload = file_get_contents($payloadFile);
    $response = singleRequest($payload,$url,$contentTypeHeader);
    $attemptCount = 1;
    while ($response->code >= 400 && $attemptCount-1 < $retryAttempts){
        fwrite($errorPipe, json_encode(new asyncResponse($url, $payload, $response->text, $response->code, $identifier, $attemptCount)));
        sleep((2 ** $attemptCount) * $retryInterval);
        $response = singleRequest($payload,$url,$contentTypeHeader);
        $attemptCount++;
        if ($response->code < 400){
            break;
        }
    }
    if ($response->code >= 400){
        fwrite($errorPipe, json_encode(new asyncResponse($url, $payload, $response->text, $response->code, $identifier, $attemptCount)));
    }
    else {
        fwrite($successPipe, json_encode(new asyncResponse($url, $payload, $response->text, $response->code, $identifier, $attemptCount)));
    }
}

// Get CLI arguments
// -c: Content type header
// -a: Retry attempts
// -r: Retry interval
// -n: Process name
// -i: Request identifier
// -p: Payload file
// Subscriber urls are passed as arguments after the options above
$opts = "c:a:r:n:i:p";
$options = getopt($opts, null, $optind);
$contentTypeHeader = $options['c'] ?? null;
$retryAttempts = $options['a'] ?? 5;
$retryInterval = $options['r'] ?? 2;
$identifier = $options['i'] ?? null;
$payloadFile = $options['p'] ?? null;
$urls = array_slice($argv, $optind);

// Set process name
$processName = "Velox Webhook Dispatcher (event $identifier)";
$parentPid = getmypid();
cli_set_process_title($processName);
file_put_contents("/proc/$parentPid/comm", $processName);
echo "Opened dispatcher for event $identifier.\n";
// Open fd/3 and fd/4 for writing
$successPipe = fopen('php://fd/3', 'w');
$errorPipe = fopen('php://fd/4', 'w');

// Spawn a new process for each url
$pids = [];
foreach ($urls as $url){
    $pid = pcntl_fork();
    if ($pid == -1){
        // Fork failed
        echo "Fork failed";
    }
    else if ($pid){
        // Parent process
        $pids[] = $pid;
    }
    else {
        // Child process, one for each subscriber
        $thisPid = getmypid();
        // Set process name
        cli_set_process_title("Velox Webhook Request - $url");
        file_put_contents("/proc/$thisPid/comm", "Velox Webhook Request (event $identifier to $url)");
        requestSession($payloadFile, $url, $contentTypeHeader, $retryAttempts, $retryInterval, $identifier);
        exit(0);
    }
}
// Wait for all child processes to finish before exiting
while (count($pids) > 0){
    $child = pcntl_wait($status, WUNTRACED);
    if ($child){
        $pids = array_diff($pids, [$child]);
    }
}
//Once all are done, we can close the pipes, delete the payload file and leave.
fclose($successPipe);
fclose($errorPipe);
unlink($payloadFile);