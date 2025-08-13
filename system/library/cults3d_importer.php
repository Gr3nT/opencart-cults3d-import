<?php
class Cults3DImporter {
    const HTML_CONNECT_TIMEOUT = 8;
    const HTML_TOTAL_TIMEOUT   = 20;
    const IMG_CONNECT_TIMEOUT  = 8;
    const IMG_TOTAL_TIMEOUT    = 25;
    const IMG_MAX_BYTES        = 6291456;
    const LOW_SPEED_LIMIT      = 10240;
    const LOW_SPEED_TIME       = 15;

    private $registry;
    private $db;
    private $config;
    private $model_product;
    private $model_language;

    public function __construct($registry) {
        $this->registry = $registry;
        $this->db = $registry->get('db');
        $this->config = $registry->get('config');
        $this->registry->get('load')->model('catalog/product');
        $this->registry->get('load')->model('localisation/language');
        $this->model_product  = $this->registry->get('model_catalog_product');
        $this->model_language = $this->registry->get('model_localisation_language');
    }

    public function processUrl($url, $opts) {
        try {
            $html = $this->curl_get_html($url);
            if (!$html) return $this->fail("❌ $url – Nem sikerült letölteni az oldalt.");

            $name = $this->matchOne('/<h1[^>]*>(.*?)<\/h1>/is', $html);
            $name = $name ? strip_tags($name) : '';
            $name = preg_replace('/^\s+/u', '', $name);
            $name = preg_replace('/\s{2,}/u', ' ', $name);
            $name = trim($name);
            if ($name === '') return $this->fail("❌ $url – Nem található terméknév.");

            $description = $this->extractDescription($html);
            if ($description === '') $description = $name;

            $media = $this->extractMedia($html, $url, 3, 3);
            $media_html = $this->renderMedia($media);
            if ($media_html) $description .= "\n\n" . $media_html;

            $design_number = $this->matchOne('/Design\s*number[^<]*<\/[^>]+>\s*<[^>]+>(.*?)<\/[^>]+>/is', $html);
            if (!$design_number) $design_number = $this->matchOne('/Design\s*number\s*:\s*([A-Za-z0-9\-_]+)/is', $html);
            $design_number = $design_number ? trim(strip_tags($design_number)) : '';
            $model = $design_number !== '' ? $design_number : ('CULTS3D-' . substr(md5($url), 0, 10));
            $model = substr($model, 0, 64);

            $overwrite       = !empty($opts['overwrite']);
            $ow              = isset($opts['overwrite_fields']) && is_array($opts['overwrite_fields']) ? $opts['overwrite_fields'] : [];
            $ow_name         = !empty($ow['name']);
            $ow_desc         = !empty($ow['description']);
            $ow_meta         = !empty($ow['meta']);
            $ow_images_mode  = isset($ow['images_mode']) ? $ow['images_mode'] : 'append';
            $ow_category     = !empty($ow['category']);

            $existing_id = $this->findProductIdByModel($model);
            if ($existing_id && !$overwrite) {
                return [
                    'ok' => true,
                    'product_id' => (int)$existing_id,
                    'line' => "⏭ Kihagyva – már létezik (modell: {$model}, ID: {$existing_id})"
                ];
            }

            $candidates = $this->extractImages($html, $url, 6);
            $product_images = [];
            $saved_count = 0;
            $main_image = '';
            $main_ext_ok = ['jpg','jpeg','png'];

            foreach ($candidates as $img) {
                $basename = basename(parse_url($img, PHP_URL_PATH));
                if (!$basename) $basename = 'img_' . md5($img) . '.jpg';
                $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));

                $cl = $this->curl_head_content_length($img);
                if ($cl !== null && $cl > self::IMG_MAX_BYTES) continue;

                $bin = $this->curl_get_binary_limited($img, self::IMG_TOTAL_TIMEOUT, self::IMG_MAX_BYTES);
                if (!$bin) continue;

                $info = @getimagesizefromstring($bin);
                if (!$info) continue;
                $w = (int)$info[0]; $h = (int)$info[1];
                if ($w < 300 || $h < 300) continue;

                $image_name = 'catalog/cults3d/' . $basename;
                $local_path = DIR_IMAGE . $image_name;
                if (!is_dir(dirname($local_path))) @mkdir(dirname($local_path), 0777, true);
                @file_put_contents($local_path, $bin);
                if (!file_exists($local_path)) continue;
                $saved_count++;

                if ($main_image === '' && in_array($ext, $main_ext_ok)) {
                    $main_image = $image_name;
                } else {
                    $product_images[] = ['image' => $image_name, 'sort_order' => count($product_images)+1];
                }
            }

            $languages = $this->model_language->getLanguages();
            $lang_ids = array_map(function($l){ return (int)$l['language_id']; }, $languages);
            $lang_map = [];
            foreach ($languages as $l) $lang_map[(int)$l['language_id']] = $l;

            $name_en = $name;
            $desc_en = $description;
            $meta_en = $this->makeMeta($desc_en);

            $openai_enable = !empty($opts['openai_enable']);
            $openai_prompt = $opts['openai_prompt'] ?? '';
            $openai_api    = $opts['openai_api_key'] ?? '';
            if (!($openai_enable && $openai_api && $openai_prompt)) $openai_enable = false;
            if ($openai_enable) require_once(DIR_SYSTEM . 'library/openai_translate.php');

            $product_description = [];
            foreach ($lang_ids as $lid) {
                $meta = $lang_map[$lid] ?? ['name'=>'','code'=>''];
                $lname = $meta['name'] ?? ('language '.$lid);
                $lcode = $meta['code'] ?? '';

                if ($openai_enable) {
                    $p_name = "You are generating an OpenCart product NAME.\nLANGUAGE: {$lname} ({$lcode})\nSOURCE CONTENT will follow.\n\nOUTPUT RULES:\n - Return ONLY the product name on one line.\n - NO prefixes, NO labels, NO headings (e.g., don't write 'Terméknév:', 'Product name:').\n - NO leading dashes/bullets or quotes.\n - Plain text only (no HTML/markdown).\n\nSTYLE HINTS: concise, specific, appealing.\n\nCONTEXT PROMPT (author guidance):\n{$openai_prompt}";
                    $p_desc = "You are generating an OpenCart product DESCRIPTION.\nLANGUAGE: {$lname} ({$lcode})\nSOURCE CONTENT will follow.\n\nOUTPUT RULES:\n - Return ONLY the description body (no title line).\n - DO NOT include any labels like 'Termékleírás:' or 'Meta leírás:'.\n - You may use simple HTML only: <p>, <ul>, <li>, <strong>, <em>, <br>.\n - No product name repetition as a heading.\n\nSTYLE HINTS: clear structure, benefits first, keep paragraphs short.\n\nCONTEXT PROMPT (author guidance):\n{$openai_prompt}";
                    $p_meta = "You are generating an OpenCart SEO META DESCRIPTION.\nLANGUAGE: {$lname} ({$lcode})\nSOURCE CONTENT will follow.\n\nOUTPUT RULES:\n - Return ONLY a single-sentence meta description.\n - <= 160 characters.\n - NO labels like 'Meta leírás:'. NO quotes.\n - Plain text only.\n\nSTYLE HINTS: benefits + key attributes, natural language.\n\nCONTEXT PROMPT (author guidance):\n{$openai_prompt}";

                    $gen_name = $this->safeTranslate($name_en, $p_name, $openai_api);
                    $gen_desc = $this->safeTranslate($desc_en, $p_desc, $openai_api);
                    $meta_input = $this->plainText($gen_name . "\n\n" . $gen_desc);
                    $gen_meta = $this->safeTranslate($meta_input, $p_meta, $openai_api);

                    $gen_name = $this->sanitizeName($gen_name);
                    $gen_desc = $this->sanitizeDescription($gen_desc);
                    $gen_meta = $this->sanitizeMeta($gen_meta);

                    $product_description[$lid] = [
                        'name' => $gen_name,
                        'description' => $gen_desc,
                        'meta_title' => $gen_name,
                        'meta_description' => $this->truncateMeta($this->plainText($gen_meta)),
                        'meta_keyword' => ''
                    ];
                } else {
                    $clean_name = $this->sanitizeName($name_en);
                    $clean_desc = $this->sanitizeDescription($desc_en);
                    $clean_meta = $this->sanitizeMeta($meta_en);

                    $product_description[$lid] = [
                        'name' => $clean_name,
                        'description' => $clean_desc,
                        'meta_title' => $clean_name,
                        'meta_description' => $this->truncateMeta($this->plainText($clean_meta)),
                        'meta_keyword' => ''
                    ];
                }
            }

            $category_id = (int)($opts['category_id'] ?? 0);

            if ($existing_id) {
                $existing     = $this->model_product->getProduct($existing_id);
                $ex_desc      = $this->model_product->getProductDescriptions($existing_id);
                $ex_images    = $this->model_product->getProductImages($existing_id);
                if (!method_exists($this->model_product, 'getProductStores')) {
                    $ex_stores = [0];
                } else {
                    $ex_stores = $this->model_product->getProductStores($existing_id);
                }

                $final_main = $existing['image'];
                $final_images = $ex_images;

                if ($ow_images_mode === 'replace') {
                    if ($main_image) $final_main = $main_image;
                    $final_images = $product_images;
                } elseif ($ow_images_mode === 'append') {
                    if (!$final_main && $main_image) $final_main = $main_image;
                    $known = [];
                    foreach ($final_images as $pi) $known[$pi['image']] = true;
                    for ($i=0; $i<count($product_images); $i++) {
                        if (count($final_images) >= 6) break;
                        if (empty($known[$product_images[$i]['image']])) {
                            $final_images[] = ['image'=>$product_images[$i]['image'],'sort_order'=>count($final_images)+1];
                            $known[$product_images[$i]['image']] = true;
                        }
                    }
                } // skip -> unchanged

                $final_desc = $ex_desc;
                foreach ($product_description as $lid => $pd) {
                    if (!isset($final_desc[$lid])) $final_desc[$lid] = [
                        'name' => '', 'description' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keyword' => ''
                    ];
                    if ($ow_name)  $final_desc[$lid]['name'] = $pd['name'];
                    if ($ow_desc)  $final_desc[$lid]['description'] = $pd['description'];
                    if ($ow_meta) {
                        $final_desc[$lid]['meta_title'] = $pd['meta_title'];
                        $final_desc[$lid]['meta_description'] = $pd['meta_description'];
                        $final_desc[$lid]['meta_keyword'] = $pd['meta_keyword'];
                    }
                }

                $edit_data = [];
                if ($ow_category && $category_id) $edit_data['product_category'] = [$category_id];

                $edit_data = array_merge([
                    'model'    => $model,
                    'sku'      => $design_number,
                    'location' => $url,
                    'quantity' => isset($existing['quantity']) ? (int)$existing['quantity'] : 1,
                    'minimum'  => isset($existing['minimum']) ? (int)$existing['minimum'] : 1,
                    'subtract' => isset($existing['subtract']) ? (int)$existing['subtract'] : 0,
                    'stock_status_id' => isset($existing['stock_status_id']) ? (int)$existing['stock_status_id'] : 7,
                    'image'    => $final_main,
                    'price'    => isset($existing['price']) ? (float)$existing['price'] : 0.00,
                    'status'   => isset($existing['status']) ? (int)$existing['status'] : 1,
                    'tax_class_id' => isset($existing['tax_class_id']) ? (int)$existing['tax_class_id'] : 0,
                    'sort_order' => isset($existing['sort_order']) ? (int)$existing['sort_order'] : 1,
                    'product_store' => $ex_stores,
                    'product_description' => $final_desc
                ], $edit_data);

                if ($ow_images_mode in ['replace','append']) {
                    $edit_data['product_image'] = $final_images;
                }

                $this->model_product->editProduct((int)$existing_id, $edit_data);
                return ['ok'=>true,'product_id'=>(int)$existing_id,'line'=>"♻️ Felülírva – modell: {$model} (ID: {$existing_id})"];
            }

            $product_data = [
                'model'    => $model,
                'sku'      => $design_number,
                'location' => $url,
                'quantity' => 1,
                'minimum'  => 1,
                'subtract' => 0,
                'stock_status_id' => 7,
                'image' => $main_image,
                'price' => 0.00,
                'status'=> 1,
                'tax_class_id' => 0,
                'sort_order' => 1,
                'product_store' => [0],
                'product_description' => $product_description
            ];
            if ($category_id) $product_data['product_category'] = [$category_id];
            if ($saved_count > 0) $product_data['product_image'] = $product_images;

            $product_id = $this->model_product->addProduct($product_data);
            $lid_success = (int)$this->config->get('config_language_id');
            if (!isset($product_description[$lid_success])) $lid_success = array_key_first($product_description);

            return [
                'ok' => true,
                'product_id' => $product_id,
                'line' => "✅ " . $product_description[$lid_success]['name'] . " – létrehozva (ID: {$product_id})"
            ];
        } catch (\Throwable $e) {
            return $this->fail("❌ $url – Hiba: " . $e->getMessage());
        }
    }

    private function findProductIdByModel($model) {
        $q = $this->db->query("SELECT product_id FROM `" . DB_PREFIX . "product` WHERE `model`='" . $this->db->escape($model) . "' LIMIT 1");
        if ($q->num_rows) return (int)$q->row['product_id'];
        return 0;
    }

    private function sanitizeName($s) {
        $s = strip_tags((string)$s);
        $s = trim($s);
        $s = preg_replace('/^\s*(?:[-–—]\s*)?(?:Terméknév|Product\s*name|Név|Title|Product\s*title)\s*:?\s*/iu', '', $s);
        $s = preg_replace('/^[\'"\-–—\s]+|[\'"\-–—\s]+$/u', '', $s);
        $s = preg_replace('/\s*\r?\n\s*/u', ' ', $s);
        return trim($s);
    }
    private function sanitizeDescription($s) {
        $s = (string)$s;
        $s = preg_replace('/^\s*(?:[-–—]\s*)?(?:<[^>]+>)*\s*(?:Terméknév|Product\s*name|Meta\s*leírás|Meta\s*description)\s*:.*$/imu', '', $s);
        $s = preg_replace('/^\s*(?:[-–—]\s*)?(?:<[^>]+>)*\s*(?:Termékleírás|Product\s*description|Leírás)\s*:?\s*/iu', '', $s);
        $s = preg_replace('/\r\n|\r|\n/u', "\n", $s);
        $s = preg_replace('/\n{3,}/u', "\n\n", $s);
        return trim($s);
    }
    private function sanitizeMeta($s) {
        $s = strip_tags((string)$s);
        $s = preg_replace('/^\s*(?:[-–—]\s*)?(?:Meta\s*leírás|Meta\s*description)\s*:?\s*/iu', '', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    private function extractMedia($html, $page_url, $max_videos = 3, $max_gifs = 3) {
        $videos = []; $gifs = [];
        if (preg_match_all('/<video[^>]*>(.*?)<\/video>/is', $html, $mv)) {
            foreach ($mv[0] as $block) {
                if (preg_match_all('/<source[^>]+src=["\']([^"\']+\.(mp4|webm))["\'][^>]*>/i', $block, $ms)) {
                    foreach ($ms[1] as $src) {
                        $videos[] = $this->absUrl($src, $page_url);
                        if (count($videos) >= $max_videos) break 2;
                    }
                } elseif (preg_match('/<video[^>]+src=["\']([^"\']+\.(mp4|webm))["\']/i', $block, $ms2)) {
                    $videos[] = $this->absUrl($ms2[1], $page_url);
                    if (count($videos) >= $max_videos) break;
                }
            }
        }
        if (count($videos) < $max_videos) {
            if (preg_match_all('/src=["\']([^"\']+\.(mp4|webm))["\']/i', $html, $ms)) {
                foreach (array_unique($ms[1]) as $src) {
                    $videos[] = $this->absUrl($src, $page_url);
                    if (count($videos) >= $max_videos) break;
                }
            }
        }
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+\.gif)["\'][^>]*>/i', $html, $mg)) {
            foreach (array_unique($mg[1]) as $src) {
                if (preg_match('/(avatar|icon|logo|placeholder|thumb)/i', $src)) continue;
                $gifs[] = $this->absUrl($src, $page_url);
                if (count($gifs) >= $max_gifs) break;
            }
        }
        return ['videos'=>$videos, 'gifs'=>$gifs];
    }
    private function renderMedia($media) {
        $videos = $media['videos'] ?? [];
        $gifs   = $media['gifs'] ?? [];
        if (!$videos && !$gifs) return '';
        $html = '<div class="cults3d-media"><h3>Media</h3>';
        foreach ($videos as $v) {
            $type = (preg_match('/\.webm(\?|$)/i', $v) ? 'video/webm' : 'video/mp4');
            $html .= '<div class="c3d-video" style="margin:10px 0;"><video controls preload="metadata" style="max-width:100%;height:auto;"><source src="' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . '" type="' . $type . '"></video></div>';
        }
        foreach ($gifs as $g) {
            $html .= '<div class="c3d-gif" style="margin:10px 0;"><img src="' . htmlspecialchars($g, ENT_QUOTES, 'UTF-8') . '" alt="" style="max-width:100%;height:auto;"></div>';
        }
        $html .= '</div>';
        return $html;
    }

    private function absUrl($src, $page_url) {
        if (preg_match('#^https?://#i', $src)) return $src;
        $base = parse_url($page_url);
        $scheme = isset($base['scheme']) ? $base['scheme'] : 'https';
        $host   = isset($base['host']) ? $base['host'] : '';
        $dir    = isset($base['path']) ? rtrim(dirname($base['path']), '/') : '';
        if (strpos($src, '//') === 0) return $scheme . ':' . $src;
        if (substr($src,0,1) === '/') return $scheme . '://' . $host . $src;
        return $scheme . '://' . $host . $dir . '/' . ltrim($src,'/');
    }

    private function fail($msg){ return ['ok'=>false,'line'=>$msg]; }

    private function safeTranslate($text, $prompt, $apiKey) {
        if (!$apiKey || !$prompt || trim($text) === '') return $text;
        require_once(DIR_SYSTEM . 'library/openai_translate.php');
        $t = new OpenAI_Translate();
        return $t->translate($text, $prompt, $apiKey);
    }

    private function makeMeta($html) {
        $plain = $this->plainText($html);
        return $this->truncateMeta($plain);
    }
    private function truncateMeta($plain) {
        $plain = preg_replace('/\s{2,}/', ' ', trim($plain));
        if (mb_strlen($plain, 'UTF-8') > 160) $plain = mb_substr($plain, 0, 157, 'UTF-8') . '…';
        return $plain;
    }
    private function plainText($html) {
        $txt = strip_tags($html);
        $txt = html_entity_decode($txt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $txt = preg_replace('/\s+/u', ' ', $txt);
        return trim($txt);
    }
    private function matchOne($pattern, $html) { if (preg_match($pattern, $html, $m)) return isset($m[1]) ? $m[1] : ''; return ''; }
    private function extractDescription($html) {
        $candidates = [
            '/<div[^>]*class="[^"]*(?:description|product-description|product__description|content-description)[^"]*"[^>]*>(.*?)<\/div>/is',
            '/<section[^>]*class="[^"]*(?:description|product-description)[^"]*"[^>]*>(.*?)<\/section>/is',
            '/<div[^>]*id="description"[^>]*>(.*?)<\/div>/is',
        ];
        foreach ($candidates as $p) {
            $d = $this->matchOne($p, $html);
            if ($d) return trim($d);
        }
        if (preg_match('/<meta\s+property=["\']og:description["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) return trim($m[1]);
        if (preg_match('/<meta\s+name=["\']description["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) return trim($m[1]);
        return '';
    }
    private function extractImages($html, $page_url, $limit = 6) {
        $out = [];
        if (preg_match_all('/<img[^>]+src="([^"]+)"[^>]*>/i', $html, $mm)) {
            $base = parse_url($page_url);
            $scheme = isset($base['scheme']) ? $base['scheme'] : 'https';
            $host   = isset($base['host']) ? $base['host'] : '';
            $dir    = isset($base['path']) ? rtrim(dirname($base['path']), '/') : '';
            foreach (array_unique($mm[1]) as $src) {
                if (strpos($src, '//') === 0) {
                    $src = $scheme . ':' . $src;
                } elseif (preg_match('#^/#', $src)) {
                    $src = $scheme . '://' . $host . $src;
                } elseif (!preg_match('#^https?://#i', $src)) {
                    $src = $scheme . '://' . $host . $dir . '/' . ltrim($src,'/');
                }
                if (!preg_match('/\.(jpg|jpeg|png|webp|gif)(\?|$)/i', $src)) continue;
                if (preg_match('/(avatar|icon|logo|placeholder|thumb)/i', $src)) continue;
                $out[] = $src;
                if (count($out) >= $limit) break;
            }
        }
        return $out;
    }

    private function curl_get_html($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; OpenCart-Cults3D-Importer)');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::HTML_CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::HTML_TOTAL_TIMEOUT);
        curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, self::LOW_SPEED_LIMIT);
        curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, self::LOW_SPEED_TIME);
        $output = curl_exec($ch);
        curl_close($ch);
        return $output;
    }
    private function curl_head_content_length($url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; OpenCart-Cults3D-Importer)');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::IMG_CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $headers = curl_exec($ch);
        curl_close($ch);
        if (!$headers) return null;
        if (preg_match('/Content-Length:\s*(\d+)/i', $headers, $m)) return (int)$m[1];
        return null;
    }
    private function curl_get_binary_limited($url, $timeout, $max_bytes) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; OpenCart-Cults3D-Importer)');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::IMG_CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, self::LOW_SPEED_LIMIT);
        curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, self::LOW_SPEED_TIME);
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($resource, $dl_total, $dl_now, $ul_total, $ul_now) use ($max_bytes) {
            if ($dl_now > $max_bytes) return 1;
            return 0;
        });
        $output = curl_exec($ch);
        curl_close($ch);
        return $output;
    }
}
