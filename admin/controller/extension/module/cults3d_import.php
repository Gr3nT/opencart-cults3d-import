<?php
class ControllerExtensionModuleCults3dImport extends Controller {
    private $error = array();

    public function install() {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "cults3d_queue` (
                `queue_id` INT(11) NOT NULL AUTO_INCREMENT,
                `url` VARCHAR(2048) NOT NULL,
                `category_id` INT(11) NOT NULL DEFAULT 0,
                `batch` VARCHAR(64) DEFAULT NULL,
                `status` ENUM('pending','processing','done','error') NOT NULL DEFAULT 'pending',
                `attempts` INT(11) NOT NULL DEFAULT 0,
                `product_id` INT(11) NULL DEFAULT NULL,
                `message` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`queue_id`),
                KEY `status` (`status`),
                KEY `created_at` (`created_at`),
                KEY `batch` (`batch`),
                KEY `category_id` (`category_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
        ");
        $cols = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "cults3d_queue` LIKE 'batch'");
        if (!$cols->num_rows) {
            $this->db->query("ALTER TABLE `" . DB_PREFIX . "cults3d_queue` ADD `batch` VARCHAR(64) NULL AFTER `category_id`, ADD KEY `batch` (`batch`)");
        }
    }

    public function uninstall() {}

    public function index() {
        $this->load->language('extension/module/cults3d_import');
        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');
        $this->load->model('catalog/category');
        $this->load->model('localisation/language');

        require_once(DIR_SYSTEM . 'library/cults3d_import_meta.php');
        $data['module_version'] = Cults3DImportMeta::VERSION;
        $data['changelog']      = Cults3DImportMeta::getChangelog();

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('module_cults3d_import', $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('extension/module/cults3d_import', 'user_token=' . $this->session->data['user_token'], $this->isSSL()));
        }

        $fields = [
            'urls','category_id','delay','enable_translation','openai_prompt','openai_api_key',
            'cron_key','queue_batch','overwrite_existing',
            ''
        ];
        foreach ($fields as $field) {
            $key = 'module_cults3d_import_' . $field;
            $data[$key] = isset($this->request->post[$key]) ? $this->request->post[$key] : $this->config->get($key);
        }
        if (empty($data['module_cults3d_import_cron_key'])) {
            $data['module_cults3d_import_cron_key'] = bin2hex(random_bytes(16));
        }
        $data['categories'] = $this->model_catalog_category->getCategories([]);
        $data['languages']  = $this->model_localisation_language->getLanguages();

        $ssl = $this->isSSL();
        $data['action']               = $this->url->link('extension/module/cults3d_import', 'user_token=' . $this->session->data['user_token'], $ssl);
        $data['import_single_action'] = $this->url->link('extension/module/cults3d_import/import_single', 'user_token=' . $this->session->data['user_token'], $ssl);
        $data['import_single_action_js'] = str_replace('&amp;', '&', $data['import_single_action']);
        $data['queue_add_action']     = $this->url->link('extension/module/cults3d_import/queue_add', 'user_token=' . $this->session->data['user_token'], $ssl);
        $data['queue_add_action_js']  = str_replace('&amp;', '&', $data['queue_add_action']);

        $catalog_base = defined('HTTP_CATALOG') ? HTTP_CATALOG : $this->config->get('config_url');
        $data['cron_url'] = rtrim($catalog_base, '/') . '/index.php?route=extension/module/cults3d_import_cron&key=' . $data['module_cults3d_import_cron_key'] . '&limit=5&sleep=2';

        $data['cancel']     = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', $ssl);
        $data['user_token'] = $this->session->data['user_token'];

        $data['header']      = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer']      = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/module/cults3d_import', $data));
    }

    public function import_single() {
        if (!$this->user->hasPermission('modify', 'extension/module/cults3d_import')) {
            return $this->json(['ok' => false, 'line' => 'Nincs jogosultság a művelethez.']);
        }

        @ini_set('max_execution_time', 120);
        @set_time_limit(120);

        $url            = isset($this->request->post['url']) ? trim($this->request->post['url']) : '';
        $category_id    = (int)($this->request->post['category_id'] ?? 0);
        $openai_enable  = !empty($this->request->post['enable_translation']);
        $openai_prompt  = $this->request->post['openai_prompt'] ?? '';
        $openai_api_key = $this->request->post['openai_api_key'] ?? '';

        $overwrite      = $this->config->get('module_cults3d_import_overwrite_existing') ? true : false;
        if ($url === '') return $this->json(['ok'=>false,'line'=>'❌ Üres URL']);

        require_once(DIR_SYSTEM . 'library/cults3d_importer.php');
        $importer = new Cults3DImporter($this->registry);

        $res = $importer->processUrl($url, [
            'category_id'   => $category_id,
            'openai_enable' => $openai_enable,
            'openai_prompt' => $openai_prompt,
            'openai_api_key'=> $openai_api_key,
            'overwrite'     => $overwrite,
            'overwrite_fields' => $ow_fields
        ]);

        if (!empty($res['ok']) && !empty($res['product_id'])) {
            $edit_link = $this->url->link('catalog/product/edit', 'user_token=' . $this->session->data['user_token'] . '&product_id=' . (int)$res['product_id'], $this->isSSL());
            $res['line'] .= ' – <a href="' . $edit_link . '" target="_blank">Szerkesztés</a>';
        }

        return $this->json($res);
    }

    public function queue_add() {
        if (!$this->user->hasPermission('modify', 'extension/module/cults3d_import')) {
            return $this->json(['ok' => false, 'message' => 'Nincs jogosultság.']);
        }

        $this->load->model('catalog/category');

        $urls_raw       = $this->request->post['urls'] ?? '';
        $default_cat_id = (int)($this->request->post['category_id'] ?? 0);
        $fallback_batch = trim($this->request->post['batch'] ?? $this->config->get('module_cults3d_import_queue_batch') ?? '');

        $lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $urls_raw)));
        if (!$lines) return $this->json(['ok'=>false,'message'=>'Nincs URL.']);

        $default_cat_name = '';
        if ($default_cat_id > 0) {
            $c = $this->model_catalog_category->getCategory($default_cat_id);
            if ($c && !empty($c['name'])) $default_cat_name = $c['name'];
        }

        $added = 0;
        $batches_used = [];

        foreach ($lines as $line) {
            $cat_id = $default_cat_id;
            $cat_name = $default_cat_name;
            $bt = '';

            if (preg_match('/\[cat:(\d+)\]/i', $line, $m)) {
                $cat_id = (int)$m[1];
                $line   = trim(str_replace($m[0], '', $line));
                $c = $this->model_catalog_category->getCategory($cat_id);
                $cat_name = ($c && !empty($c['name'])) ? $c['name'] : '';
            }
            if (preg_match('/\[batch:([^\]]+)\]/i', $line, $m)) {
                $bt   = trim($m[1]);
                $line = trim(str_replace($m[0], '', $line));
            }

            if ($bt === '') {
                if ($cat_id > 0 && $cat_name !== '') {
                    $bt = $cat_name;
                } elseif ($fallback_batch !== '') {
                    $bt = $fallback_batch;
                } else {
                    $bt = null;
                }
            }

            $u = trim($line);
            if ($u === '') continue;

            $sql = "INSERT INTO `" . DB_PREFIX . "cults3d_queue` SET `url`='" . $this->db->escape($u) . "', `category_id`=".(int)$cat_id.", ";
            if ($bt === null) {
                $sql .= "`batch`=NULL, ";
            } else {
                $bt64 = mb_substr($bt, 0, 64, 'UTF-8');
                $sql .= "`batch`='" . $this->db->escape($bt64) . "', ";
                $batches_used[$bt64] = true;
            }
            $sql .= "`status`='pending', `created_at`=NOW()";

            $this->db->query($sql);
            $added++;
        }

        $list = $batches_used ? implode(', ', array_keys($batches_used)) : 'n/a';
        return $this->json([ 'ok'=>true, 'message'=> "Várólistához hozzáadva: {$added} db. Használt batch-ek: {$list}" ]);
    }

    private function json($arr) {
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        $this->response->setOutput(json_encode($arr));
        return;
    }

    private function isSSL() {
        return !empty($this->request->server['HTTPS']) && $this->request->server['HTTPS'] != 'off';
    }

    protected function validate() {
        if (!$this->user->hasPermission('modify', 'extension/module/cults3d_import')) {
            $this->error['warning'] = 'Nincs jogosultságod módosítani a modult.';
        }
        return !$this->error;
    }
}
