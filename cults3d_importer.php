<?php
class Cults3DImporter {
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
            $html = $this->curl_get($url);
            if (!$html) return $this->fail("❌ $url – Nem sikerült letölteni az oldalt.");

            // név
            $name = $this->matchOne('/<h1[^>]*>(.*?)<\/h1>/is', $html);
            $name = $name ? strip_tags($name) : '';
            $name = preg_replace('/^\s+/u', '', $name);
            $name = preg_replace('/\s{2,}/u', ' ', $name);
            $name = trim($name);
            if ($name === '') return $this->fail("❌ $url – Nem található terméknév.");

            // leírás
            $description = $this->extractDescription($html);
            if ($description === '') $description = $name;

            // design number → model + sku
            $design_number = $this->matchOne('/Design\s*number[^<]*<\/[^>]+>\s*<[^>]+>(.*?)<\/[^>]+>/is', $html);
            if (!$design_number) $design_number = $this->matchOne('/Design\s*number\s*:\s*([A-Za-z0-9\-_]+)/is', $html);
            $design_number = $design_number ? trim(strip_tags($design_number)) : '';
            $model = $design_number !== '' ? $design_number : ('CULTS3D-' . substr(md5($url), 0, 10));
            $model = substr($model, 0, 64);

            // képek
            $candidates = $this->extractImages($html, $url, 6);
            if (!$candidates) return $this->fail("❌ $url – Nem található használható kép.");

            $product_images = [];
            $saved_count = 0;
            $main_image = '';
            $main_ext_ok = ['jpg','jpeg','png'];

            foreach ($candidates as $img) {
                $basename = basename(parse_url($img, PHP_URL_PATH));
                if (!$basename) $basename = 'img_' . md5($img) . '.jpg';
                $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));

                $bin = $this->curl_get($img);
                if (!$bin) continue;

                $info = @getimagesizefromstring($bin);
                if (!$info) continue;
                $w = (int)$info[0]; $h = (int)$info[1];
                if ($w < 300 || $h < 300) continue;

                $image_name = 'catalog/cults3d/' . $basename;
                $local_path = DIR_IMAGE . $image_name;
                if (!is_dir(dirname($local_path))) @mkdir(dirname($local_path), 0777, true);
                file_put_contents($local_path, $bin);
                $saved_count++;

                if ($main_image === '' && in_array($ext, $main_ext_ok)) {
                    $main_image = $image_name;
                } else {
                    $product_images[] = ['image' => $image_name, 'sort_order' => count($product_images)+1];
                }
            }

            if ($saved_count === 0) return $this->fail("❌ $url – Nem találtunk 300×300-nál nagyobb letölthető képet.");

            // nyelvek
            $languages = $this->model_language->getLanguages();
            $lang_ids = array_map(function($l){ return (int)$l['language_id']; }, $languages);
            $lang_map = [];
            foreach ($languages as $l) $lang_map[(int)$l['language_id']] = $l;

            // alap angol
            $name_en = $name;
            $desc_en = $description;
            $meta_en = $this->makeMeta($desc_en);

            // OpenAI
            $openai_enable = !empty($opts['openai_enable']);
            $openai_prompt = $opts['openai_prompt'] ?? '';
            $openai_api    = $opts['openai_api_key'] ?? '';

            if ($openai_enable && $openai_api && $openai_prompt) {
                require_once(DIR_SYSTEM . 'library/openai_translate.php');
            } else {
                $openai_enable = false;
            }

            // product_description
            $product_description = [];
            foreach ($lang_ids as $lid) {
                $meta = $lang_map[$lid] ?? ['name'=>'','code'=>''];
                $lname = $meta['name'] ?? ('language '.$lid);
                $lcode = $meta['code'] ?? '';

                if ($openai_enable) {
                    $p_name = $openai_prompt . "\n\nGenerate a high-quality PRODUCT NAME in {$lname} ({$lcode}). Return ONLY the name.";
                    $p_desc = $openai_prompt . "\n\nWrite a detailed, well-structured PRODUCT DESCRIPTION in {$lname} ({$lcode}). Keep simple HTML (<p>, <ul>, <li>, <strong>) where useful. Return ONLY the description.";
                    $p_meta = $openai_prompt . "\n\nCreate an SEO META DESCRIPTION (<=160 characters) in {$lname} ({$lcode}) based on the product name and description. Return ONLY the meta description.";

                    $gen_name = $this->safeTranslate($name_en, $p_name, $openai_api);
                    $gen_desc = $this->safeTranslate($desc_en, $p_desc, $openai_api);

                    $meta_input = $this->plainText($gen_name . "\n\n" . $gen_desc);
                    $gen_meta = $this->safeTranslate($meta_input, $p_meta, $openai_api);
                    $gen_meta = $this->truncateMeta($this->plainText($gen_meta));

                    $product_description[$lid] = [
                        'name' => $gen_name,
                        'description' => $gen_desc,
                        'meta_title' => $gen_name,
                        'meta_description' => $gen_meta,
                        'meta_keyword' => ''
                    ];
                } else {
                    $product_description[$lid] = [
                        'name' => $name_en,
                        'description' => $desc_en,
                        'meta_title' => $name_en,
                        'meta_description' => $meta_en,
                        'meta_keyword' => ''
                    ];
                }
            }

            $category_id = (int)($opts['category_id'] ?? 0);

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
                'product_description' => $product_description,
                'product_category' => $category_id ? [$category_id] : [],
                'product_image' => $product_images
            ];

            $product_id = $this->model_product->addProduct($product_data);

            return [
                'ok' => true,
                'product_id' => $product_id,
                'line' => "✅ {$product_description[1]['name']} – létrehozva (ID: {$product_id})"
            ];

        } catch (\Throwable $e) {
            return $this->fail("❌ $url – Hiba: " . $e->getMessage());
        }
    }

    // utilok
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
        if (mb_strlen($plain, 'UTF-8') > 160) {
            $plain = mb_substr($plain, 0, 157, 'UTF-8') . '…';
        }
        return $plain;
    }

    private function plainText($html) {
        $txt = strip_tags($html);
        $txt = html_entity_decode($txt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $txt = preg_replace('/\s+/u', ' ', $txt);
        return trim($txt);
    }

    private function matchOne($pattern, $html) {
        if (preg_match($pattern, $html, $m)) return isset($m[1]) ? $m[1] : '';
        return '';
    }

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
        if (preg_match('/<meta\s+property=["\']og:description["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/<meta\s+name=["\']description["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
            return trim($m[1]);
        }
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
                if (strpos($src, '//') === 0) $src = $scheme . ':' . $src;
                elseif (preg_match('#^/#', $src)) $src = $scheme . '://' . $host . $src;
                elseif (!preg_match('#^https?://#i', $src)) $src = $scheme . '://' . $host . $dir . '/' . $src;

                if (!preg_match('/\.(jpg|jpeg|png|webp|gif)(\?|$)/i', $src)) continue;
                if (preg_match('/(avatar|icon|logo|placeholder|thumb)/i', $src)) continue;

                $out[] = $src;
                if (count($out) >= $limit) break;
            }
        }
        return $out;
    }

    private function curl_get($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; OpenCart-Cults3D-Importer)');
        $output = curl_exec($ch);
        curl_close($ch);
        return $output;
    }
}
