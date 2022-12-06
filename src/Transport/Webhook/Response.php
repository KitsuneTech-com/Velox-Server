<?php
namespace KitsuneTech\Velox\Transport\Webhook;
class Response {
    public function __construct(public string $text, public int $code){}
}
