<?php
require_once dirname(__DIR__) . '/src/Public/AiPublicKnowledgeRestController.php';

class WP_REST_Response {
    public $data;
    public $status;
    public $headers = array();
    public function __construct($data, $status) { $this->data = $data; $this->status = $status; }
    public function header($key, $value) { $this->headers[$key] = $value; }
}
$response = \Delnavazan\Platform\Public\AiPublicKnowledgeRestController::read();
if ($response->status !== 200 || $response->data !== array('schema_version' => 1, 'entries' => array())) {
    throw new RuntimeException('Public AI endpoint must return empty approved catalogue by default');
}
$bootstrap = file_get_contents(dirname(__DIR__) . '/delnavazan-platform.php');
if (strpos($bootstrap, 'AiPublicKnowledgeRestController') === false) {
    throw new RuntimeException('Public AI route is not registered');
}
echo "AI public knowledge source contract passed\n";
