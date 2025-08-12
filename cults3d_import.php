<?php
class ControllerExtensionModuleCults3dImport extends Controller {
    private $error = array();

    public function install() {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "cults3d_queue` (
                `queue_id` INT(11) NOT NULL AUTO_INCREMENT,
                `url` VARCHAR(2048) NOT NULL,
                `category_id` INT(11) NOT NULL DEFAULT 0,
                `status` ENUM('pending','processing','done','error') NOT NULL DEFAULT 'pending',
                `attempts` INT(11) NOT NULL DEFAULT 0,
                `product_id` INT(11) NULL DEFAULT NULL,
                `message` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`queue_id`),
                KEY `status` (`status`),
                KEY `created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
        ");
    }

    public function uninstall() {
        // Nem töröljük a várólistát, hogy megmaradjanak az előzmények.
    }

    public function index() {
        $this->load->language('extension/module/cults3d_import');
        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');
        $this->load->model('catalog/category');
        $this->load->model('localisation/language');

        // Verzió + changelog
        require_once(DIR_SYSTEM . 'library/cults3d_import_meta.php');
        $data['module_version'] = Cults3DImportMeta::VERSION;
        $data['changelog']      = Cults3DImportMeta::getChangelog();

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('module_cults3d_import', $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('extension/module/cults3d_import', 'user_token=' . $this->session->data['user_token'], $this->isSSL()));
        }

        // űrlap mezők + CRON KEY
        $fields = ['urls','category_id','delay','enable_translation','openai_prompt','openai_api_key','cron_key'];
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

        // Queue add (admin) + Cron minta URL
        $data['queue_add_action'] = $this->url->link('extension/module/cults3d_import/queue_add', 'user_token=' . $this->session->data['user_token'], $ssl);
        $data['queue_add_action_js'] = str_replace('&amp;', '&', $data['queue_add_action']);

        $catalog_base = defined('HTTP_CATALOG') ? HTTP_CATALOG : $this->config->get('config_url');
        $data['cron_url'] = rtrim($catalog_base, '/') . '/index.php?route=extension/module/cults3d_import_cron&key=' . $data['module_cults3d_import_cron_key'] . '&limit=5&sleep=2';

        $data['cancel']     = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', $ssl);
        $data['user_token'] = $this->session->data['user_token'];

        $data['header']      = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer']      = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/module/cults3d_import', $data));
    }

    /**
     * Kézi import – 1 URL / kérés (AJAX)
     */
    public function import_single() {
        if (!$this->user->hasPermission('modify', 'extension/module/cults3d_import')) {
            return $this->json(['ok' => false, 'line' => 'Nincs jogosultság a művelethez.']);
        }

        $url            = isset($this->request->post['url']) ? trim($this->request->post['url']) : '';
        $category_id    = (int)($this->request->post['category_id'] ?? 0);
        $openai_enable  = !empty($this->request->post['enable_translation']);
        $openai_prompt  = $this->request->post['openai_prompt'] ?? '';
        $openai_api_key = $this->request->post['openai_api_key'] ?? '';

        if ($url === '') return $this->json(['ok'=>false,'line'=>'❌ Üres URL']);

        require_once(DIR_SYSTEM . 'library/cults3d_importer.php');
        $importer = new Cults3DImporter($this->registry);

        $res = $importer->processUrl($url, [
            'category_id'   => $category_id,
            'openai_enable' => $openai_enable,
            'openai_prompt' => $openai_prompt,
            'openai_api_key'=> $openai_api_key
        ]);

        // Szerkesztési link, ha sikerült
        if (!empty($res['ok']) && !empty($res['product_id'])) {
            $edit_link = $this->url->link(
                'catalog/product/edit',
                'user_token=' . $this->session->data['user_token'] . '&product_id=' . (int)$res['product_id'],
                $this->isSSL()
            );
            $res['line'] .= ' – <a href="' . $edit_link . '" target="_blank">Szerkesztés</a>';
        }

        return $this->json($res);
    }

    /**
     * Várólista feltöltése adminból (Cronhoz)
     */
    public function queue_add() {
        if (!$this->user->hasPermission('modify', 'extension/module/cults3d_import')) {
            return $this->json(['ok' => false, 'message' => 'Nincs jogosultság.']);
        }
        $urls_raw   = $this->request->post['urls'] ?? '';
        $urls       = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $urls_raw)));
        $category_id= (int)($this->request->post['category_id'] ?? 0);

        if (!$urls) return $this->json(['ok'=>false,'message'=>'Nincs URL.']);

        $added = 0;
        foreach ($urls as $u) {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "cults3d_queue` SET `url`='" . $this->db->escape($u) . "', `category_id`=".(int)$category_id.", `status`='pending', `created_at`=NOW()");
            $added++;
        }
        return $this->json(['ok'=>true, 'message'=> "Hozzáadva a várólistához: {$added} db"]);
    }

    // ===== helpers =====
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
