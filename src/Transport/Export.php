<?php
declare(strict_types=1);

namespace KitsuneTech\Velox\Transport;
use KitsuneTech\Velox\VeloxException as VeloxException;
use KitsuneTech\Velox\Structures\Model as Model;
use function KitsuneTech\Velox\Utility\isPowerOf2;
use function KitsuneTech\Velox\Utility\validateURLPath;

/** Exports the specified Model(s) in a specified format, to a specified destination.
 *
 * The Export function acts on one or more Models, translating the underlying data into one of several formats (JSON,
 * XML, HTML, and CSV are supported) and then sending the data to the specified destination (the browser, a locally-stored
 * file, stdout, or a returned string). These options can be specified by passing as the second argument one constant each
 * from the following categories, added together:
 *
 * Destination flags:
 *  * TO_BROWSER
 *  * TO_FILE
 *  * TO_STRING
 *  * TO_STDOUT
 *
 * Format flags:
 *  * AS_JSON
 *  * AS_CSV
 *  * AS_XML
 *  * AS_HTML
 *
 * **Important security note:** The final parameter, $css, is provided as an optional means to apply a stylesheet to
 * exported HTML. This is perfectly safe when supplied with a known-good URL or CSS snippet; however, no validation is
 * performed to check whether what is supplied may be malicious. If using this parameter, **do not** pass user input
 * directly to it without first ensuring that the contents are safe and valid.
 *
 * @param Model|array $models The Model(s) whose data is to be exported
 * @param int $flags A bitmask indicating the destination type and format of the exported data.
 * @param string|null $location The path and/or filename to which the data will be exported (required for TO_FILE but ignored for TO_STRING and TO_STDOUT)
 * @param int|null $ignoreRows The number of data rows (if any) to be skipped at the beginning
 * @param bool $noHeader If passed as true, no column headers will be included with the exported data
 * @param string $css A string containing either CSS rules to be applied to the exported HTML, or a URI (absolute or relative)
 *      pointing to a CSS resource. (This only applies to AS_HTML exports.)
 *
 * @return string|bool If exported to string, the result will be returned. Otherwise, this will be a boolean indicating success.
 * @throws VeloxException
 * @throws \DOMException
 *
 * @version 1.0.0
 * @since 1.0.0-alpha
 * @license https://www.mozilla.org/en-US/MPL/2.0/ Mozilla Public License 2.0
 */
function Export(Model|array $models, int $flags = TO_BROWSER+AS_JSON, ?string $location = null, ?int $ignoreRows = 0, bool $noHeader = false, string $css = '') : string|bool {
    //unpack flags
    $destination = $flags & 0x0F;  //First 5 bits
    $format = $flags & 0xF0;       //Next 4 bits
    if (!isPowerOf2($destination) || !isPowerOf2($format)){
        throw new VeloxException("Invalid flags set",30);
    }
    if ($destination == TO_FILE && !$location){
        throw new VeloxException("Filename is missing",31);
    }
    if ($destination == TO_BROWSER && headers_sent()){
        throw new VeloxException("Only one to-browser Export can be called per request.",32);
    }
    $data = [];
    $titles = [];
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
            $titles[] = $model->instanceName;
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
                        xmlwriter_text($xml,strval($data));
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
            $head = $html->appendChild($doc->createElement('head'));
            if (count($titles) > 0){
                $title = $head->appendChild($doc->createElement('title'));
                $title->textContent = implode(", ",$titles);
            }
            if ($css){
                if (validateURLPath($css)){
                    $link = $head->appendChild($doc->createElement('link'));
                    $link->setAttribute('rel','stylesheet');
                    $link->setAttribute('type','text/css');
                    $link->setAttribute('href',$css);
                }
                else {
                    $style = $head->appendChild($doc->createElement('style'));
                    $style->setAttribute('type','text/css');
                    $style->textContent = $css;
                }
            }
            $body = $html->appendChild($doc->createElement('body'));
            foreach($data as $instanceName => $details){
                $table = $body->appendChild($doc->createElement('table'));
                $table->setAttribute('class','results');
                $table->setAttribute('id','results_'.$instanceName);
                if (!$noHeader){
                    $thead = $table->appendChild($doc->createElement('thead'));
                    $thead_tr = $thead->appendChild($doc->createElement('tr'));
                    foreach($details['columns'] as $column){
                        $th = $thead_tr->appendChild($doc->createElement('th'));
                        $th->textContent = $column;
                    }
                }
                foreach($details['data'] as $row){
                    $tr = $table->appendChild($doc->createElement('tr'));
                    foreach($details['columns'] as $column){
                        $td = $tr->appendChild($doc->createElement('td'));
                        $td->textContent = strval($row[$column]);
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
                    $header = str_replace('"','""',strval($column));
                    $headerData[] = '"'.$header.'"';
                }
                $output = implode(",",$headerData);
            }
            foreach($details['data'] as $row){
                if ($output) {
                    $output .= "\r\n";    //add newline first, if rows already exist
                }
                $rowData = [];
                foreach ($details['columns'] as $column){
                    //First quote any double-quotes
                    $value = $row[$column] ? str_replace('"','""',strval($row[$column])) : '';
                    $rowData[] = '"'.$value.'"';
                }
                $output .= implode(",",$rowData);
            }
            $mostRecent = $details['lastQuery'];
            break;
    }
    $contentType = "Content-Type: ";
    switch ($format){
        case AS_JSON:
            $contentType .= "application/json";
            break;
        case AS_XML:
            $contentType .= "application/xml";
            break;
        case AS_HTML:
            $contentType .= "text/html";
            break;
        case AS_CSV:
            $contentType .= "text/csv";
            break;
    }
    switch ($destination){
        case TO_BROWSER:
            header($contentType);
            if ($location){
                header('Content-Disposition: attachment; filename="'.$location.'"');
            }
            else {
                header('Content-Disposition: inline');
            }
            header('Last-Modified: '.gmdate('D, d M Y H:i:s ', $mostRecent) . 'GMT');
            echo $output;
            return true;
        case TO_FILE:
            $handle = fopen($location,"w");
            fwrite($handle,$output);
            fclose($handle);
            return true;
        case TO_STRING:
            return $output;
        case TO_STDOUT:
            fwrite(STDOUT,$output);
            return true;
        default:
            return false; //Not a valid destination
    }
}
