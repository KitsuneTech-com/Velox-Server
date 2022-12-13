<?php
require_once (__DIR__ . "/../Transport/Webhook/Response.php");
use KitsuneTech\Velox\Transport\Webhook\Response as Response;
// This script is intended to be run via exec() from the \Transport\Webhook\Request class, to enable independent asynchronous execution of webhooks.
// Forking is not used, as the database connection would be retained by the child process and would be killed when the first child exits.

// Dispatch responses are sent through either of two custom pipes (php://fd/3 is the success pipe, while php://fd/4 is the error pipe); these are
// monitored by RequestController, which calls the appropriate callback when data is received on either.

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

set_error_handler(function($code, $message, $errfile, $line){
    global $callerPID;
    $stderr = fopen("php://fd/2","w");
    fwrite($stderr,"Dispatcher error $code ($line): $message");
    fclose($stderr);
    posix_kill($callerPID, SIGUSR1);
    posix_kill($callerPID, SIGUSR2);
});

set_exception_handler(function($ex){
    global $callerPID;
    $stderr = fopen("php://fd/3","w");
    fwrite($stderr, "Dispatcher exception: ".$ex);
    posix_kill($callerPID, SIGUSR1);
    posix_kill($callerPID, SIGUSR2);
});

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
    global $callerPID, $successPipe, $errorPipe;
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
    $writePipe = $response->code >= 400 ? $errorPipe : $successPipe;
    fwrite($writePipe, json_encode(new asyncResponse($url, $payload, $response->text, $response->code, $identifier, $attemptCount)));
    posix_kill($callerPID, SIGUSR1);
}

function shutdown() : void {
    global $completionPipe, $successPipe, $errorPipe, $payloadFile, $callerPID;
    // Once we're done, write to the completion pipe to signal that we're done, then close the pipes, delete the payload file, and exit
    if (is_resource($completionPipe)){
        fclose($completionPipe);
    }
    if (is_resource($successPipe)){
        fclose($successPipe);
    }
    if (is_resource($errorPipe)){
        fclose($errorPipe);
    }
    if (file_exists($payloadFile)) unlink($payloadFile);
    // Finally, send SIGUSR2 to the calling process to signal that we're done
    posix_kill($callerPID, SIGUSR2);
}
register_shutdown_function("shutdown"); //Define as shutdown function so that it will be called no matter what, so the controller doesn't hang

// Get CLI arguments
// -c: Content type header
// -a: Retry attempts
// -r: Retry interval
// -n: Process name
// -i: Request identifier
// -p: Payload file
// Subscriber urls are passed as arguments after the options above
$opts = "c:a:r::i:p:f:";
$optind = 0;
$options = getopt($opts, rest_index: $optind);
$contentTypeHeader = $options['c'] ?? null;
$retryAttempts = $options['a'] ?? 5;
$retryInterval = $options['r'] ?? 2;
$identifier = $options['i'] ?? null;
$callerPID = $options['p'] ?? null;
$payloadFile = $options['f'] ?? null;
$urls = array_slice($argv, $optind);

// Set process name
$processName = "Velox Webhook Dispatcher (event $identifier)";
$parentPid = getmypid();
cli_set_process_title($processName);
file_put_contents("/proc/$parentPid/comm", $processName);

$stdout = fopen("php://stdout", "w");
fwrite($stdout, "Opened dispatcher for event $identifier...\n");
// Open pipes (if the file descriptors exist, use them; otherwise default to stdout and stderr)
$successPipe = @fopen('php://fd/3', 'w');
if (!$successPipe){
    echo "Could not open success pipe. Defaulting to stdout...\n";
    $successPipe = fopen('php://stdout', 'w');
}
$errorPipe = @fopen('php://fd/4', 'w');
if (!$errorPipe){
    echo "Could not open error pipe. Defaulting to stderr...\n";
    $errorPipe = fopen('php://stderr', 'w');
}
$completionPipe = @fopen('php://fd/5', 'w');
if (!$completionPipe){
    echo "Could not open completion pipe. Defaulting to stdout...\n";
    $completionPipe = fopen('php://stdout', 'w');
}

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