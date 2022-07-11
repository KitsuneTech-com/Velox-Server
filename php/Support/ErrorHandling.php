<?php
declare(strict_types=1);
namespace KitsuneTech\Velox;

if (!isset($GLOBALS['Velox']['ErrorReportingMode'])){
    $GLOBALS['Velox']['ErrorReportingMode'] = VELOX_ERR_STDERR + VELOX_ERR_STACKTRACE;
}
function VeloxExceptionHandler(\Throwable $ex) : void {
    if ($GLOBALS['Velox']['ErrorReportingMode'] === VELOX_ERR_NONE){
    	echo $ex->code;
    }
    $exObj = (object)['timestamp'=>time(), 'class'=>get_class($ex), 'code'=>$ex->getCode(), 'file'=>$ex->getFile(), 'line'=>$ex->getLine(), 'message'=>$ex->getMessage()];
    if ($GLOBALS['Velox']['ErrorReportingMode'] & VELOX_ERR_STACKTRACE){
	$exObj->trace = $ex->getTrace();
    }
    $exObj->previous = $ex->getPrevious();
    $outputObj = (object)['Exception'=>$exObj];
    if ($GLOBALS['Velox']['ErrorReportingMode'] & VELOX_ERR_STDERR){
	fwrite(STDERR,$exObj->class." [".$exObj->code."] encountered in ".$exObj->file." (line ".$exObj->line."): ".$exObj->message);
	if (isset($exObj->trace)){
	    $traceStr = 'Stack trace:\n';
	    foreach ($exObj->trace as $item){
		$traceStr .= "Function ".$item['function']." in ".$item['file']." , line ".$item['line']."\n";
	    }
	}
    }
    if ($GLOBALS['Velox']['ErrorReportingMode'] & VELOX_ERR_JSONOUT){
	header('Content-Type: application/json');
	echo json_encode($outputObj, JSON_PRETTY_PRINT);
    }
}

set_exception_handler('\KitsuneTech\Velox\VeloxExceptionHandler');

$errorLevel = VELOX_ERR_STDERR + VELOX_ERR_STACKTRACE;

function veloxErrorReporting(int $errorLevel) : void {
    $GLOBALS['Velox']['ErrorReportingMode'] = $errorLevel;
}

