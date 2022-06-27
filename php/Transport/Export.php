<?php
declare(strict_types=1);

namespace KitsuneTech\Velox\Transport;
use KitsuneTech\Velox\VeloxException as VeloxException;
use KitsuneTech\Velox\Structures\Model as Model;

function Export(Model|array $models, int $flags = TO_BROWSER+AS_JSON, ?string $fileName = null, ?int $ignoreRows = 0, bool $noHeader = false) : string|bool {
    function isPowerOf2($num){
        return ($num != 0) && (($num & ($num-1)) == 0);
    }
    //unpack flags
    $destination = $flags & 0x0F;
    $format = $flags & 0xF0;
    if (!isPowerOf2($destination) || !isPowerOf2($format)){
        throw new VeloxException("Invalid flags set",30);
    }
    if ($destination == TO_FILE && !$fileName){
        throw new VeloxException("Filename is missing",31);
    }
    if ($destination == TO_BROWSER && headers_sent()){
        throw new VeloxException("Only one to-browser Export can be called per request.",32);
    }
    $data = [];
    if ($models instanceof Model){
        $models = [$models];
    }
    foreach ($models as $model){
        if (!($model instanceof Model)){
            throw new VeloxException("Array contains elements other than instances of Model",33);
        }
        $details = ['lastQuery'=>$model->lastQuery(), 'columns'=>$model->columns(), 'data'=>$model->data()];
        if ($ignoreRows > 0){
            for ($i=0; $i<$ignoreRows; $i++){
                array_shift($details['data']);
            }
        }
        if ($model->instanceName){
            $data[$model->instanceName] = $details;
        }
        else {
            $data[] = $details;
        }
    }
    $output = "";
    $mostRecent = 0;
    switch ($format){
        case AS_JSON:
            array_walk_recursive($data,
                function(&$v) {
                    if (is_numeric($v)) {
                        $v = strval($v);
                    }
                }
            );
            $output = json_encode($data, JSON_INVALID_UTF8_IGNORE);
            break;
        case AS_XML:
            if (!extension_loaded('xmlwriter')){
                throw new VeloxException("XML export requires the xmlwriter extension",34);
            }
            $xml = xmlwriter_open_memory();
            xmlwriter_start_document($xml);
            xmlwriter_start_element($xml, 'models');
            foreach($data as $instanceName => $details){
                xmlwriter_start_element($xml,'model');
                xmlwriter_start_attribute($xml,'instanceName');
                xmlwriter_text($xml,(string)$instanceName);
                xmlwriter_end_attribute($xml);
                xmlwriter_start_element($xml, 'lastQuery');
                xmlwriter_text($xml,(string)$details['lastQuery']);
                xmlwriter_end_element($xml);
                if (!$noHeader){
                    xmlwriter_start_element($xml,'columns');
                    foreach ($details['columns'] as $column){
                        xmlwriter_start_element($xml,'column');
                        xmlwriter_start_attribute($xml,'name');
                        xmlwriter_text($xml,$column);
                        xmlwriter_end_attribute($xml);
                        xmlwriter_end_element($xml);
                    }
                    xmlwriter_end_element($xml);
                }
                xmlwriter_start_element($xml, 'data');
                foreach ($details['data'] as $row){
                    xmlwriter_start_element($xml,'row');
                    foreach ($row as $column => $data){
                        xmlwriter_start_element($xml,str_replace(' ','-',$column));
                        xmlwriter_text($xml,$data ?? '');
                        xmlwriter_end_element($xml);
                    }
                    xmlwriter_end_element($xml);
                }
                xmlwriter_end_element($xml);
                xmlwriter_end_element($xml);
                if ($details['lastQuery'] > $mostRecent){
                    $mostRecent = $details['lastQuery'];
                }
            }
            xmlwriter_end_element($xml);
            xmlwriter_end_document($xml);
            $output = xmlwriter_output_memory($xml);
            break;
        case AS_HTML:
            $doc = new \DOMDocument;
            $html = $doc->appendChild($doc->createElement('html'));
            $body = $html->appendChild($doc->createElement('body'));
            foreach($data as $instanceName => $details){
                $table = $body->appendChild($doc->createElement('table'));
                $table->setAttribute('class','results');
                $table->setAttribute('id','results_'.$instanceName);
                if (!$noHeader){
                    $thead = $table->appendChild($doc->createElement('thead'));
                    $thead_tr = $thead->appendChild($doc->createElement('tr'));
                    foreach($details['columns'] as $column){
                        $td = $thead_tr->appendChild($doc->createElement('td'));
                        $td->textContent = $column;
                    }
                }
                foreach($details['data'] as $row){
                    $tr = $table->appendChild($doc->createElement('tr'));
                    foreach($details['columns'] as $column){
                        $td = $tr->appendChild($doc->createElement('td'));
                        $td->textContent = $row[$column];
                    }
                }
                $tfoot = $table->appendChild($doc->createElement('tfoot'));
                $tfoot_tr = $tfoot->appendChild($doc->createElement('tr'));
                $tfoot_td = $tfoot_tr->appendChild($doc->createElement('td'));
                $tfoot_td->setAttribute('colspan',(string)count($details['columns']));
                $tfoot_td->textContent = "Last queried at ". gmdate('D, d M Y H:i:s ', $details['lastQuery']) . 'GMT';
                if ($details['lastQuery'] > $mostRecent){
                    $mostRecent = $details['lastQuery'];
                }
            }
            $output = $doc->saveHTML();
            break;
        case AS_CSV:
            if (count($data) > 1){
                throw new VeloxException("A CSV file can have only one worksheet. You will need to export each Model separately.",34);
            }
            $instanceName = array_keys($data)[0];
            $details = $data[$instanceName];
            if (!$noHeader){
                $headerData = [];
                foreach ($details['columns'] as $column){
                    $headerData[] = '"'.$column.'"';
                }
                $output = implode(",",$headerData);
            }
            else {
                $output = "";
            }
            foreach($details['data'] as $row){
                if ($output) {
                    $output .= "\r\n";    //add newline first, if rows already exist
                }
                $rowData = [];
                foreach ($details['columns'] as $column){
                    $rowData[] = '"'.$row[$column] ?? ''.'"';
                }
                $output .= implode(",",$rowData);
            }
            $mostRecent = $details['lastQuery'];
            break;
    }
    switch ($destination){
        case TO_BROWSER:
            switch ($format){
                case AS_JSON:
                    header('Content-Type: application/json');
                    break;
                case AS_XML:
                    header('Content-Type: application/xml');
                    break;
                case AS_HTML:
                    header('Content-Type: text/html');
                    break;
                case AS_CSV:
                    header('Content-Type: text/csv');
                    break;
            }
            if ($fileName){
                header('Content-Disposition: attachment; filename="'.$fileName.'"');
            }
            else {
                header('Content-Disposition: inline');
            }
            //header('Content-Length: '.mb_strlen($output,'UTF-8'));
            header('Last-Modified: '.gmdate('D, d M Y H:i:s ', $mostRecent) . 'GMT');
            echo $output;
            return true;
        case TO_FILE:
            $handle = fopen($fileName,"w");
            fwrite($handle,$output);
            fclose($handle);
            return true;
        case TO_STRING:
            return $output;
        case TO_STDOUT:
            fwrite(STDOUT,$output);
            return true;
    }
}
