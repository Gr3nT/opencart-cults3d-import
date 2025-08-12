<?php
class ControllerExtensionModuleCults3dImportCron extends Controller {

    public function index() {
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');

        $key = $this->request->get['key'] ?? '';
        $cfg_key = $this->config->get('module_cults3d_import_cron_key');

        if (!$cfg_key || !$key || !hash_equals($cfg_key, $key)) {
            $this->response->setOutput(json_encode(['ok'=>false,'message'=>'Invalid key']));
            return;
        }

        $limit = (int)($this->request->get['limit'] ?? 5);
        if ($limit <= 0 || $limit > 50) $limit = 5;
        $sleep = (int)($this->request->get['sleep'] ?? 0);
        if ($sleep < 0 || $sleep > 30) $sleep = 0;

        // beállítások
        $openai_enable = $this->config->get('module_cults3d_import_enable_translation') ? true : false;
               $openai_prompt = $this->config->get('module_cults3d_import_openai_prompt') ?: '';
        $openai_api    = $this->config->get('module_cults3d_import_openai_api_key') ?: '';

        require_once(DIR_SYSTEM . 'library/cults3d_importer.php');
        $importer = new Cults3DImporter($this->registry);

        $done = 0; $errors = 0; $items = [];

        for ($i=0; $i<$limit; $i++) {
            $row = $this->db->query("SELECT * FROM `" . DB_PREFIX . "cults3d_queue` WHERE `status`='pending' ORDER BY `queue_id` ASC LIMIT 1")->row;
            if (!$row) break;

            $this->db->query("UPDATE `" . DB_PREFIX . "cults3d_queue` SET `status`='processing', `attempts`=`attempts`+1, `updated_at`=NOW() WHERE `queue_id`=".(int)$row['queue_id']);

            $res = $importer->processUrl($row['url'], [
                'category_id'   => (int)$row['category_id'],
                'openai_enable' => $openai_enable,
                'openai_prompt' => $openai_prompt,
                'openai_api_key'=> $openai_api
            ]);

            if (!empty($res['ok'])) {
                $done++;
                $this->db->query("UPDATE `" . DB_PREFIX . "cults3d_queue` SET `status`='done', `product_id`=".(int)$res['product_id'].", `message`='" . $this->db->escape($res['line']) . "', `updated_at`=NOW() WHERE `queue_id`=".(int)$row['queue_id']);
            } else {
                $errors++;
                $this->db->query("UPDATE `" . DB_PREFIX . "cults3d_queue` SET `status`='error', `message`='" . $this->db->escape($res['line']) . "', `updated_at`=NOW() WHERE `queue_id`=".(int)$row['queue_id']);
            }

            $items[] = $res['line'];

            if ($sleep > 0) sleep($sleep);
        }

        $this->response->setOutput(json_encode([
            'ok' => true,
            'processed' => $done + $errors,
            'done' => $done,
            'errors' => $errors,
            'items' => $items
        ]));
    }
}
