<?php
/** AI SEO Assistant for OpenCart 3.0.3.x */
class ControllerExtensionModuleAiSeo extends Controller {
	private $error = array();

	public function index() {
		$this->load->language('extension/module/ai_seo');
		$this->document->setTitle($this->language->get('heading_title'));
		$this->load->model('setting/setting');
		$this->load->model('localisation/language');
		$this->ensureAiSeoTables();
		$data = $this->languageData();
		$data['action'] = $this->url->link('extension/module/ai_seo/save', 'user_token=' . $this->session->data['user_token'], true);
		$data['scan'] = $this->url->link('extension/module/ai_seo/scan', 'user_token=' . $this->session->data['user_token'], true);
		$data['models'] = $this->url->link('extension/module/ai_seo/models', 'user_token=' . $this->session->data['user_token'], true);
		$data['products_url'] = $this->url->link('catalog/product', 'user_token=' . $this->session->data['user_token'], true);
		$data['how_url'] = $this->url->link('extension/module/ai_seo/how', 'user_token=' . $this->session->data['user_token'], true);
		$data['history_url'] = $this->url->link('extension/module/ai_seo/history', 'user_token=' . $this->session->data['user_token'], true);
		$data['tasks_url'] = $this->url->link('extension/module/ai_seo/tasks', 'user_token=' . $this->session->data['user_token'], true);
		$data['category_audit'] = $this->url->link('extension/module/ai_seo/categoryAudit', 'user_token=' . $this->session->data['user_token'], true);
		$data['categories_url'] = $this->url->link('catalog/category', 'user_token=' . $this->session->data['user_token'], true);
		$data['extension_version'] = $this->extensionVersion();
		$data['update_status_url'] = $this->url->link('extension/module/ai_seo/updateStatus', 'user_token=' . $this->session->data['user_token'], true);
		$data['update_install_url'] = $this->url->link('extension/module/ai_seo/update', 'user_token=' . $this->session->data['user_token'], true);
		$data['settings'] = $this->model_setting_setting->getSetting('module_ai_seo');
		$data['history_summary'] = $this->historySummary();
		$data['site_profile'] = !empty($data['settings']['module_ai_seo_site_profile']) ? json_decode($data['settings']['module_ai_seo_site_profile'], true) : array();
		$data['scan_log'] = !empty($data['settings']['module_ai_seo_scan_log']) ? json_decode($data['settings']['module_ai_seo_scan_log'], true) : array();
		$data['brand_analysis'] = !empty($data['settings']['module_ai_seo_brand_analysis']) ? json_decode($data['settings']['module_ai_seo_brand_analysis'], true) : array();
		if (!is_array($data['site_profile'])) $data['site_profile'] = array();
		if (!is_array($data['scan_log'])) $data['scan_log'] = array();
		if (!is_array($data['brand_analysis'])) $data['brand_analysis'] = array();
		$data['languages'] = $this->model_localisation_language->getLanguages();
		$data['breadcrumbs'] = array(
			array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)),
			array('text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true)),
			array('text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/module/ai_seo', 'user_token=' . $this->session->data['user_token'], true))
		);
		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');
		$this->response->setOutput($this->load->view('extension/module/ai_seo', $data));
	}

	public function save() {
		$this->load->language('extension/module/ai_seo');
		$json = array();
		if (!$this->user->hasPermission('modify', 'extension/module/ai_seo')) { $json['error'] = $this->language->get('error_permission'); }
		elseif ($this->request->server['REQUEST_METHOD'] != 'POST') { $json['error'] = $this->language->get('error_request'); }
		else {
			$allowed = array('provider', 'openrouter_key', 'openrouter_model', 'openai_key', 'openai_model', 'gemini_key', 'gemini_model', 'language_id', 'brand_notes', 'competitor_urls', 'generation_tone', 'max_products', 'status');
			$this->load->model('setting/setting');
			$setting = $this->model_setting_setting->getSetting('module_ai_seo');
			foreach ($allowed as $key) { $setting['module_ai_seo_' . $key] = isset($this->request->post[$key]) ? trim($this->request->post[$key]) : ''; }
			$this->model_setting_setting->editSetting('module_ai_seo', $setting);
			$json['success'] = $this->language->get('text_success');
		}
		$this->response->addHeader('Content-Type: application/json'); $this->response->setOutput(json_encode($json));
	}

	public function scan() {
		$this->load->language('extension/module/ai_seo'); $json = array();
		if (!$this->user->hasPermission('modify', 'extension/module/ai_seo')) { $json['error'] = $this->language->get('error_permission'); }
		else {
			$this->load->model('setting/setting'); $settings = $this->model_setting_setting->getSetting('module_ai_seo');
			$url = defined('HTTP_CATALOG') ? HTTP_CATALOG : '';
			if (!$url || !function_exists('curl_init')) { $json['error'] = $this->language->get('error_scan'); }
			else {
				$scan = $this->scanStorePages($url);
				if (empty($scan['profile'])) { $json['error'] = $this->language->get('error_scan'); }
				else {
					$profile = $scan['profile'];
					$analysis = $this->analyzeBrand($scan['snapshot'], $settings, $profile);
					$profile['pages_scanned'] = $scan['pages_scanned'];
					$profile['scanned_at'] = date('c');
					$log = !empty($settings['module_ai_seo_scan_log']) ? json_decode($settings['module_ai_seo_scan_log'], true) : array(); if (!is_array($log)) $log = array();
					array_unshift($log, array('time' => date('d.m.Y H:i'), 'status' => !empty($analysis['ai_ready']) ? 'AI analizli' : 'Tarandı', 'title' => $profile['title'], 'pages' => $scan['pages_scanned'])); $log = array_slice($log, 0, 10);
					$settings['module_ai_seo_site_profile'] = json_encode($profile, JSON_UNESCAPED_UNICODE);
					$settings['module_ai_seo_brand_analysis'] = json_encode($analysis, JSON_UNESCAPED_UNICODE);
					$settings['module_ai_seo_scan_log'] = json_encode($log, JSON_UNESCAPED_UNICODE);
					$this->model_setting_setting->editSetting('module_ai_seo', $settings);
					$json['success'] = $this->language->get('text_scan_success'); $json['profile'] = $profile; $json['analysis'] = $analysis; $json['scan_log'] = $log;
				}
			}
		}
		$this->response->addHeader('Content-Type: application/json'); $this->response->setOutput(json_encode($json));
	}

	public function how() {
		$this->load->language('extension/module/ai_seo');
		$this->document->setTitle('AI SEO Asistanı - Nasıl Çalışır');
		$data = $this->languageData();
		$data['products_url'] = $this->url->link('catalog/product', 'user_token=' . $this->session->data['user_token'], true);
		$data['assistant_url'] = $this->url->link('extension/module/ai_seo', 'user_token=' . $this->session->data['user_token'], true);
		$data['breadcrumbs'] = array(array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)), array('text' => $this->language->get('heading_title'), 'href' => $data['assistant_url']), array('text' => 'Nasıl Çalışır', 'href' => $this->url->link('extension/module/ai_seo/how', 'user_token=' . $this->session->data['user_token'], true)));
		$data['header'] = $this->load->controller('common/header'); $data['column_left'] = $this->load->controller('common/column_left'); $data['footer'] = $this->load->controller('common/footer');
		$this->response->setOutput($this->load->view('extension/module/ai_seo_how', $data));
	}

	public function models() {
		$json = array('models' => array());
		if (!$this->user->hasPermission('modify', 'extension/module/ai_seo')) { $json['error'] = 'Permission denied.'; }
		else {
			$this->load->model('setting/setting'); $s = $this->model_setting_setting->getSetting('module_ai_seo');
			$provider = isset($this->request->get['provider']) && $this->request->get['provider'] === 'openai' ? 'openai' : 'openrouter';
			$key = isset($s['module_ai_seo_' . $provider . '_key']) ? $s['module_ai_seo_' . $provider . '_key'] : '';
			if (empty($key)) { $json['error'] = ($provider === 'openai' ? 'OpenAI' : 'OpenRouter') . ' API anahtarı girilip kaydedilmelidir.'; }
			else {
				$url = $provider === 'openai' ? 'https://api.openai.com/v1/models' : 'https://openrouter.ai/api/v1/models';
				$ch = curl_init($url); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . $key)));
				$res = json_decode(curl_exec($ch), true); curl_close($ch);
				if (!empty($res['data'])) foreach ($res['data'] as $model) { if (!empty($model['id'])) $json['models'][] = array('id' => $model['id'], 'name' => isset($model['name']) ? $model['name'] : $model['id']); }
				if (!$json['models']) $json['error'] = 'Model listesi alınamadı.';
			}
		}
		$this->response->addHeader('Content-Type: application/json'); $this->response->setOutput(json_encode($json));
	}

	public function generate() {
		$this->load->language('extension/module/ai_seo'); $json = array('results' => array());
		if (!$this->user->hasPermission('modify', 'extension/module/ai_seo')) { $json['error'] = $this->language->get('error_permission'); }
		elseif (empty($this->request->post['product_id']) || !is_array($this->request->post['product_id'])) { $json['error'] = $this->language->get('error_products'); }
		else {
			$this->load->model('setting/setting'); $s = $this->model_setting_setting->getSetting('module_ai_seo');
			if (!empty($this->request->post['provider']) && in_array($this->request->post['provider'], array('openrouter', 'openai', 'gemini'))) $s['module_ai_seo_provider'] = $this->request->post['provider'];
			$provider = isset($s['module_ai_seo_provider']) ? $s['module_ai_seo_provider'] : 'openrouter';
			if (empty($s['module_ai_seo_' . $provider . '_key'])) { $json['error'] = 'Seçilen sağlayıcı için kayıtlı API anahtarı bulunamadı.'; $this->response->addHeader('Content-Type: application/json'); $this->response->setOutput(json_encode($json)); return; }
			$limit = isset($s['module_ai_seo_max_products']) ? max(1, min(50, (int)$s['module_ai_seo_max_products'])) : 30;
			foreach (array_slice(array_unique(array_map('intval', $this->request->post['product_id'])), 0, $limit) as $product_id) {
				$result = $this->generateProduct($product_id, $s); $json['results'][] = $result;
			}
			$json['success'] = $this->language->get('text_generated');
		}
		$this->response->addHeader('Content-Type: application/json'); $this->response->setOutput(json_encode($json));
	}

	public function generateCategories() {
		$this->load->language('extension/module/ai_seo'); $json = array('results' => array());
		if (!$this->user->hasPermission('modify', 'extension/module/ai_seo')) { $json['error'] = $this->language->get('error_permission'); }
		elseif (empty($this->request->post['category_id']) || !is_array($this->request->post['category_id'])) { $json['error'] = 'En az bir kategori seçin.'; }
		else {
			$this->load->model('setting/setting'); $settings = $this->model_setting_setting->getSetting('module_ai_seo');
			if (!empty($this->request->post['provider']) && in_array($this->request->post['provider'], array('openrouter', 'openai', 'gemini'))) $settings['module_ai_seo_provider'] = $this->request->post['provider'];
			$provider = isset($settings['module_ai_seo_provider']) ? $settings['module_ai_seo_provider'] : 'openrouter';
			if (empty($settings['module_ai_seo_' . $provider . '_key'])) $json['error'] = 'Seçilen sağlayıcı için kayıtlı API anahtarı bulunamadı.';
			else {
				$limit = isset($settings['module_ai_seo_max_products']) ? max(1, min(50, (int)$settings['module_ai_seo_max_products'])) : 30;
				foreach (array_slice(array_unique(array_map('intval', $this->request->post['category_id'])), 0, $limit) as $category_id) $json['results'][] = $this->generateCategory($category_id, $settings);
				$json['success'] = 'Kategori SEO içeriği üretildi.';
			}
		}
		$this->response->addHeader('Content-Type: application/json'); $this->response->setOutput(json_encode($json));
	}

	public function categoryAudit() {
		$json = array();
		if (!$this->user->hasPermission('modify', 'extension/module/ai_seo')) { $json['error'] = 'Yetkiniz yok.'; }
		else {
			$this->load->model('setting/setting'); $settings = $this->model_setting_setting->getSetting('module_ai_seo');
			$language_id = !empty($settings['module_ai_seo_language_id']) ? (int)$settings['module_ai_seo_language_id'] : (int)$this->config->get('config_language_id');
			$rows = $this->db->query("SELECT c.category_id, cd.name, cd.description, cd.meta_title, cd.meta_description, cd.meta_keyword, su.keyword FROM `" . DB_PREFIX . "category` c JOIN `" . DB_PREFIX . "category_description` cd ON (c.category_id=cd.category_id AND cd.language_id='" . $language_id . "') LEFT JOIN `" . DB_PREFIX . "seo_url` su ON (su.query=CONCAT('category_id=', c.category_id) AND su.language_id='" . $language_id . "' AND su.store_id='0') WHERE c.status='1' ORDER BY cd.name ASC LIMIT 300")->rows;
			$issues = array(); $total = 0; $sum = 0;
			foreach ($rows as $row) { $total++; $score = $this->seoScore(array('meta_title' => $row['meta_title'], 'meta_description' => $row['meta_description'], 'meta_keyword' => $row['meta_keyword'], 'seo_keyword' => $row['keyword'])); $sum += $score['score']; if ($score['score'] < 100 || utf8_strlen(trim(strip_tags($row['description']))) < 80) { if (utf8_strlen(trim(strip_tags($row['description']))) < 80) $score['notes'][] = 'Kategori açıklaması en az 80 karakter olmalı.'; $issues[] = array('category_id' => (int)$row['category_id'], 'name' => $row['name'], 'score' => $score['score'], 'notes' => $score['notes']); } }
			usort($issues, function($a, $b) { return $a['score'] - $b['score']; });
			$json = array('total' => $total, 'average_score' => $total ? (int)round($sum / $total) : 0, 'issues' => array_slice($issues, 0, 30));
		}
		$this->response->addHeader('Content-Type: application/json'); $this->response->setOutput(json_encode($json));
	}

	public function history() {
		$this->load->language('extension/module/ai_seo'); $this->document->setTitle('AI SEO Asistanı - İşlem Geçmişi'); $this->ensureAiSeoTables();
		$data = $this->languageData(); $data['assistant_url'] = $this->url->link('extension/module/ai_seo', 'user_token=' . $this->session->data['user_token'], true); $data['products_url'] = $this->url->link('catalog/product', 'user_token=' . $this->session->data['user_token'], true); $data['categories_url'] = $this->url->link('catalog/category', 'user_token=' . $this->session->data['user_token'], true);
		$data['summary'] = $this->historySummary();
		$data['product_history'] = $this->db->query("SELECT * FROM `" . DB_PREFIX . "ai_seo_history` ORDER BY history_id DESC LIMIT 100")->rows;
		$data['category_history'] = $this->db->query("SELECT * FROM `" . DB_PREFIX . "ai_seo_category_history` ORDER BY history_id DESC LIMIT 100")->rows;
		$data['breadcrumbs'] = array(array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)), array('text' => $this->language->get('heading_title'), 'href' => $data['assistant_url']), array('text' => 'İşlem geçmişi', 'href' => $this->url->link('extension/module/ai_seo/history', 'user_token=' . $this->session->data['user_token'], true)));
		$data['header'] = $this->load->controller('common/header'); $data['column_left'] = $this->load->controller('common/column_left'); $data['footer'] = $this->load->controller('common/footer');
		$this->response->setOutput($this->load->view('extension/module/ai_seo_history', $data));
	}

	public function tasks() {
		$this->load->language('extension/module/ai_seo'); $this->document->setTitle('AI SEO Asistanı - SEO Görev Merkezi');
		$data = $this->languageData(); $data['assistant_url'] = $this->url->link('extension/module/ai_seo', 'user_token=' . $this->session->data['user_token'], true); $data['products_url'] = $this->url->link('catalog/product', 'user_token=' . $this->session->data['user_token'], true); $data['categories_url'] = $this->url->link('catalog/category', 'user_token=' . $this->session->data['user_token'], true); $data['task_data'] = $this->taskData();
		$data['breadcrumbs'] = array(array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)), array('text' => $this->language->get('heading_title'), 'href' => $data['assistant_url']), array('text' => 'SEO görev merkezi', 'href' => $this->url->link('extension/module/ai_seo/tasks', 'user_token=' . $this->session->data['user_token'], true)));
		$data['header'] = $this->load->controller('common/header'); $data['column_left'] = $this->load->controller('common/column_left'); $data['footer'] = $this->load->controller('common/footer'); $this->response->setOutput($this->load->view('extension/module/ai_seo_tasks', $data));
	}

	public function undo() {
		$json = array(); $this->ensureAiSeoTables();
		if (!$this->user->hasPermission('modify', 'extension/module/ai_seo')) $json['error'] = 'Yetkiniz yok.';
		elseif (empty($this->request->post['history_id'])) $json['error'] = 'Geçmiş kaydı seçilmedi.';
		else { $row = $this->db->query("SELECT * FROM `" . DB_PREFIX . "ai_seo_history` WHERE history_id='" . (int)$this->request->post['history_id'] . "'"); $before = $row->num_rows ? json_decode($row->row['before_data'], true) : array(); if (!$before || empty($before['product_id'])) $json['error'] = 'Bu kayıt geri alınabilir eski değer içermiyor.'; else { $this->restoreProductSnapshot($before); $json['success'] = 'Ürün SEO alanları önceki hâline geri alındı.'; } }
		$this->response->addHeader('Content-Type: application/json'); $this->response->setOutput(json_encode($json));
	}

	public function providers() {
		$json = array('providers' => array());
		if (!$this->user->hasPermission('modify', 'extension/module/ai_seo')) { $json['error'] = $this->language->get('error_permission'); }
		else {
			$this->load->model('setting/setting'); $settings = $this->model_setting_setting->getSetting('module_ai_seo');
			$labels = array('openrouter' => 'OpenRouter', 'openai' => 'OpenAI', 'gemini' => 'Google Gemini');
			foreach ($labels as $id => $label) if (!empty($settings['module_ai_seo_' . $id . '_key'])) $json['providers'][] = array('id' => $id, 'label' => $label, 'model' => !empty($settings['module_ai_seo_' . $id . '_model']) ? $settings['module_ai_seo_' . $id . '_model'] : 'Varsayılan model', 'selected' => (!empty($settings['module_ai_seo_provider']) ? $settings['module_ai_seo_provider'] : 'openrouter') === $id);
		}
		$this->response->addHeader('Content-Type: application/json'); $this->response->setOutput(json_encode($json));
	}

	public function updateStatus() {
		$json = array('current_version' => $this->extensionVersion(), 'update_available' => false);
		if (!$this->user->hasPermission('access', 'extension/module/ai_seo')) $json['error'] = 'Yetkiniz yok.';
		else {
			$release = $this->latestRelease();
			if (!empty($release['error'])) $json['error'] = $release['error'];
			else {
				$json['latest_version'] = $release['version'];
				$json['release_url'] = $release['release_url'];
				$json['notes'] = $release['notes'];
				$json['update_available'] = version_compare($release['version'], $this->extensionVersion(), '>');
			}
		}
		$this->response->addHeader('Content-Type: application/json'); $this->response->setOutput(json_encode($json));
	}

	public function update() {
		$json = array();
		if (!$this->user->hasPermission('modify', 'extension/module/ai_seo')) $json['error'] = 'Güncelleme için değiştirme yetkiniz yok.';
		elseif ($this->request->server['REQUEST_METHOD'] !== 'POST') $json['error'] = 'Geçersiz güncelleme isteği.';
		else {
			$release = $this->latestRelease();
			if (!empty($release['error'])) $json['error'] = $release['error'];
			elseif (!version_compare($release['version'], $this->extensionVersion(), '>')) $json['error'] = 'Eklenti zaten güncel.';
			else {
				try {
					$this->installReleasePackage($release);
					$json['success'] = 'AI SEO Asistanı v' . $release['version'] . ' yüklendi. Değişiklikler yenilendi; sayfa şimdi yenilenecek.';
					$json['version'] = $release['version'];
				} catch (Exception $e) { $json['error'] = 'Güncelleme tamamlanamadı: ' . $e->getMessage(); }
			}
		}
		$this->response->addHeader('Content-Type: application/json'); $this->response->setOutput(json_encode($json));
	}

	private function extensionVersion() { return '1.9.5'; }

	private function latestRelease() {
		if (!function_exists('curl_init')) return array('error' => 'Sunucuda cURL etkin olmadığı için güncelleme denetlenemedi.');
		$url = 'https://api.github.com/repos/efeytrl/opencart-ai-seo-assistant/releases/latest';
		$ch = curl_init($url);
		curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => array('Accept: application/vnd.github+json', 'User-Agent: OpenCart-AI-SEO-Assistant/' . $this->extensionVersion()), CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2));
		$raw = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
		$release = json_decode($raw, true);
		if ($http !== 200 || !is_array($release)) return array('error' => 'GitHub sürüm bilgisi şu anda alınamadı.');
		$version = preg_replace('/^v/i', '', isset($release['tag_name']) ? $release['tag_name'] : '');
		if (!preg_match('/^\d+(?:\.\d+){1,3}$/', $version)) return array('error' => 'GitHub yayın etiketi geçerli bir sürüm değil.');
		$asset = array();
		foreach (!empty($release['assets']) && is_array($release['assets']) ? $release['assets'] : array() as $item) {
			if (!empty($item['name']) && $item['name'] === 'ai-seo-assistant-v' . $version . '.ocmod.zip' && !empty($item['browser_download_url'])) { $asset = $item; break; }
		}
		if (!$asset) return array('error' => 'Bu yayın için doğrulanmış OCMOD paketi bulunamadı.');
		$asset_url = $asset['browser_download_url'];
		if (parse_url($asset_url, PHP_URL_SCHEME) !== 'https' || parse_url($asset_url, PHP_URL_HOST) !== 'github.com') return array('error' => 'Güncelleme paketi güvenilir GitHub bağlantısından gelmiyor.');
		return array('version' => $version, 'asset_url' => $asset_url, 'release_url' => !empty($release['html_url']) ? $release['html_url'] : '', 'notes' => utf8_substr(trim(strip_tags(isset($release['body']) ? $release['body'] : '')), 0, 4000));
	}

	private function installReleasePackage($release) {
		if (!class_exists('ZipArchive')) throw new Exception('Sunucuda ZIP desteği etkin değil.');
		$upload_dir = defined('DIR_UPLOAD') ? DIR_UPLOAD : DIR_STORAGE . 'upload/';
		if (!is_dir($upload_dir) && !@mkdir($upload_dir, 0755, true)) throw new Exception('Geçici yükleme klasörü oluşturulamadı.');
		$temp_file = tempnam($upload_dir, 'ai-seo-update-');
		if (!$temp_file) throw new Exception('Geçici güncelleme dosyası oluşturulamadı.');
		try {
			$target = fopen($temp_file, 'wb');
			if (!$target) throw new Exception('Güncelleme paketi için yazma izni yok.');
			$ch = curl_init($release['asset_url']);
			$options = array(CURLOPT_FILE => $target, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 90, CURLOPT_HTTPHEADER => array('User-Agent: OpenCart-AI-SEO-Assistant/' . $this->extensionVersion()), CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2);
			if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
			curl_setopt_array($ch, $options); $ok = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); fclose($target);
			if (!$ok || $http !== 200 || !is_file($temp_file) || filesize($temp_file) < 100 || filesize($temp_file) > 15728640) throw new Exception('Güncelleme paketi indirilemedi veya dosya boyutu geçersiz.');
			$zip = new ZipArchive();
			if ($zip->open($temp_file) !== true) throw new Exception('İndirilen dosya geçerli bir ZIP arşivi değil.');
			$xml = $zip->getFromName('install.xml');
			if (!$xml) { $zip->close(); throw new Exception('OCMOD install.xml dosyası pakette bulunamadı.'); }
			$dom = new DOMDocument('1.0', 'UTF-8');
			if (!@$dom->loadXML($xml) || !$dom->getElementsByTagName('code')->length || !$dom->getElementsByTagName('version')->length) { $zip->close(); throw new Exception('OCMOD manifesti geçerli değil.'); }
			$code = trim($dom->getElementsByTagName('code')->item(0)->nodeValue);
			$package_version = trim($dom->getElementsByTagName('version')->item(0)->nodeValue);
			if ($code !== 'ai_seo_assistant' || $package_version !== $release['version']) { $zip->close(); throw new Exception('Paket kimliği veya sürümü yayınla eşleşmiyor.'); }
			$root = realpath(DIR_APPLICATION . '../');
			if (!$root) { $zip->close(); throw new Exception('OpenCart kök klasörü bulunamadı.'); }
			$files = array();
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$name = str_replace('\\', '/', $zip->getNameIndex($i));
				if ($name === 'install.xml' || substr($name, -1) === '/') continue;
				if (strpos($name, 'upload/admin/') !== 0 || strpos($name, '../') !== false || strpos($name, "\0") !== false) { $zip->close(); throw new Exception('Paket güvenli olmayan bir dosya yolu içeriyor.'); }
				$relative = substr($name, 7);
				$files[] = array('archive' => $name, 'relative' => $relative, 'target' => $root . '/' . $relative);
			}
			if (!$files) { $zip->close(); throw new Exception('Paket kurulacak dosya içermiyor.'); }
			$backup_root = rtrim(DIR_STORAGE, '/\\') . '/backup/ai-seo-' . date('Ymd-His');
			$written = array();
			try {
				foreach ($files as $file) {
					$directory = dirname($file['target']);
					if (!is_dir($directory) && !@mkdir($directory, 0755, true)) throw new Exception('Hedef klasör oluşturulamadı.');
					$existed = is_file($file['target']); $backup = $backup_root . '/' . $file['relative'];
					if ($existed) { if (!is_dir(dirname($backup)) && !@mkdir(dirname($backup), 0755, true)) throw new Exception('Yedek klasörü oluşturulamadı.'); if (!@copy($file['target'], $backup)) throw new Exception('Mevcut dosya yedeklenemedi.'); }
					$source = $zip->getStream($file['archive']); $destination = @fopen($file['target'], 'wb');
					if (!$source || !$destination) { if ($source) fclose($source); if ($destination) fclose($destination); throw new Exception('Güncelleme dosyası yazılamadı.'); }
					stream_copy_to_stream($source, $destination); fclose($source); fclose($destination); $written[] = array('target' => $file['target'], 'backup' => $backup, 'existed' => $existed);
				}
			} catch (Exception $e) {
				foreach (array_reverse($written) as $written_file) { if ($written_file['existed'] && is_file($written_file['backup'])) @copy($written_file['backup'], $written_file['target']); elseif (is_file($written_file['target'])) @unlink($written_file['target']); }
				$zip->close(); throw $e;
			}
			$zip->close();
			$this->load->model('setting/modification');
			$existing = $this->db->query("SELECT modification_id FROM `" . DB_PREFIX . "modification` WHERE code='ai_seo_assistant'")->rows;
			foreach ($existing as $modification) $this->model_setting_modification->deleteModification((int)$modification['modification_id']);
			$this->model_setting_modification->addModification(array('name' => 'AI SEO Assistant', 'code' => 'ai_seo_assistant', 'author' => 'efeytrl', 'version' => $package_version, 'link' => 'https://github.com/efeytrl/opencart-ai-seo-assistant', 'xml' => $xml, 'status' => 1));
			if (method_exists($this->model_setting_modification, 'refresh')) $this->model_setting_modification->refresh();
		} catch (Exception $e) { if (is_file($temp_file)) @unlink($temp_file); throw $e; }
		if (is_file($temp_file)) @unlink($temp_file);
	}

	private function generateProduct($product_id, $s) {
		$language_id = !empty($s['module_ai_seo_language_id']) ? (int)$s['module_ai_seo_language_id'] : (int)$this->config->get('config_language_id');
		$q = $this->db->query("SELECT p.product_id, p.model, pd.name, pd.description, pd.meta_title, pd.meta_description, pd.meta_keyword, pd.tag FROM `" . DB_PREFIX . "product` p JOIN `" . DB_PREFIX . "product_description` pd ON (p.product_id=pd.product_id) WHERE p.product_id='" . (int)$product_id . "' AND pd.language_id='" . $language_id . "'");
		if (!$q->num_rows) return array('product_id' => $product_id, 'error' => 'Ürün bulunamadı.');
		$p = $q->row; $profile = !empty($s['module_ai_seo_site_profile']) ? json_decode($s['module_ai_seo_site_profile'], true) : array();
		$tone = !empty($s['module_ai_seo_generation_tone']) ? $s['module_ai_seo_generation_tone'] : 'professional';
		$notes = !empty($s['module_ai_seo_brand_notes']) ? $s['module_ai_seo_brand_notes'] : '';
		$categories = $this->getCatalogCategories($language_id);
		$prompt = 'You are a senior e-commerce SEO editor. Create accurate, conversion-focused and unique SEO content in the storefront language. Use ONLY facts supported by the product name, model and description. Never invent material, certification, health, price, availability, discount, delivery or performance claims. Return ONLY valid JSON with exactly these keys: meta_title, meta_description, meta_keyword, seo_keyword, tags, category_ids, ai_search_summary, faq. meta_title must be natural, unique and 45-60 characters; meta_description must be compelling, specific and 130-160 characters; meta_keyword must contain 4-8 distinct search-intent phrases separated by commas; seo_keyword must be a concise lowercase URL slug based on the strongest real product terms; tags must be a maximum of 10 useful distinct terms; category_ids must contain at most 3 IDs selected ONLY from the supplied existing categories and must fit the description. ai_search_summary must be a factual 1-2 sentence direct answer for AI search systems, no more than 280 characters. faq must be an array of at most 2 objects with question and answer, using only supplied facts. Avoid keyword stuffing, generic superlatives, emojis, quotation marks and unsupported promises. Writing tone: ' . $tone . '. Brand/editorial rules: ' . $notes . '. Store profile: ' . json_encode($profile, JSON_UNESCAPED_UNICODE) . '. Existing categories: ' . json_encode($categories, JSON_UNESCAPED_UNICODE) . '. Product name: ' . html_entity_decode($p['name'], ENT_QUOTES, 'UTF-8') . '. Model: ' . $p['model'] . '. Product description: ' . trim(strip_tags(html_entity_decode($p['description'], ENT_QUOTES, 'UTF-8')));
		$answer = $this->askAi($prompt, $s);
		if (isset($answer['error'])) return array('product_id' => $product_id, 'name' => $p['name'], 'error' => $answer['error']);
		$seo = $this->decodeSeo($answer['text']); if (!$seo) return array('product_id' => $product_id, 'name' => $p['name'], 'error' => 'Yapay zekâ geçerli SEO verisi döndürmedi.');
		$slug = $this->slug($seo['seo_keyword']); if (!$slug) $slug = $this->slug($p['name']);
		$slug = $this->uniqueKeyword($slug, $product_id, $language_id);
		$tags = $this->mergeTags(isset($p['tag']) ? $p['tag'] : '', isset($seo['tags']) ? $seo['tags'] : array());
		$before = $this->productSnapshot($product_id, $language_id);
		$description = $this->mergeAiSearchSection($p['description'], isset($seo['ai_search_summary']) ? $seo['ai_search_summary'] : '', isset($seo['faq']) ? $seo['faq'] : array());
		$this->db->query("UPDATE `" . DB_PREFIX . "product_description` SET description='" . $this->db->escape($description) . "', meta_title='" . $this->db->escape(utf8_substr($seo['meta_title'], 0, 255)) . "', meta_description='" . $this->db->escape(utf8_substr($seo['meta_description'], 0, 255)) . "', meta_keyword='" . $this->db->escape(utf8_substr($seo['meta_keyword'], 0, 255)) . "', tag='" . $this->db->escape(utf8_substr($tags, 0, 255)) . "' WHERE product_id='" . (int)$product_id . "' AND language_id='" . $language_id . "'");
		$this->db->query("DELETE FROM `" . DB_PREFIX . "seo_url` WHERE query='product_id=" . (int)$product_id . "' AND language_id='" . $language_id . "' AND store_id='0'");
		$this->db->query("INSERT INTO `" . DB_PREFIX . "seo_url` SET store_id='0', language_id='" . $language_id . "', query='product_id=" . (int)$product_id . "', keyword='" . $this->db->escape($slug) . "'");
		$category_count = $this->assignCategories($product_id, $language_id, isset($seo['category_ids']) ? $seo['category_ids'] : array());
		$related_count = $this->assignRelatedProducts($product_id, $language_id, $p['name'] . ' ' . $tags);
		$score = $this->seoScore(array('meta_title' => $seo['meta_title'], 'meta_description' => $seo['meta_description'], 'meta_keyword' => $seo['meta_keyword'], 'seo_keyword' => $slug));
		$this->recordHistory($product_id, $p['name'], 'completed', $s, $answer, $score['score'], '', $before, $this->productSnapshot($product_id, $language_id));
		return array('product_id' => $product_id, 'name' => $p['name'], 'keyword' => $slug, 'tags' => $tags, 'categories_added' => $category_count, 'related_added' => $related_count, 'seo_score' => $score['score'], 'seo_notes' => $score['notes'], 'usage' => isset($answer['usage']) ? $answer['usage'] : array());
	}

	private function generateCategory($category_id, $settings) {
		$language_id = !empty($settings['module_ai_seo_language_id']) ? (int)$settings['module_ai_seo_language_id'] : (int)$this->config->get('config_language_id');
		$query = $this->db->query("SELECT c.category_id, c.parent_id, cd.name, cd.description, cd.meta_title, cd.meta_description, cd.meta_keyword FROM `" . DB_PREFIX . "category` c JOIN `" . DB_PREFIX . "category_description` cd ON (c.category_id=cd.category_id) WHERE c.category_id='" . (int)$category_id . "' AND cd.language_id='" . $language_id . "'");
		if (!$query->num_rows) return array('category_id' => $category_id, 'error' => 'Kategori bulunamadı.');
		$category = $query->row; $profile = !empty($settings['module_ai_seo_site_profile']) ? json_decode($settings['module_ai_seo_site_profile'], true) : array(); $notes = !empty($settings['module_ai_seo_brand_notes']) ? $settings['module_ai_seo_brand_notes'] : '';
		$parent = ''; if ($category['parent_id']) { $parentQuery = $this->db->query("SELECT name FROM `" . DB_PREFIX . "category_description` WHERE category_id='" . (int)$category['parent_id'] . "' AND language_id='" . $language_id . "'"); if ($parentQuery->num_rows) $parent = $parentQuery->row['name']; }
		$prompt = 'You are a senior e-commerce category SEO editor. Return ONLY valid JSON with exactly these keys: description, meta_title, meta_description, meta_keyword, seo_keyword, ai_search_summary. Write in the storefront language. Use only facts supplied below. Do not mention price, stock, shipping, certification, medical claims or claims you cannot verify. description must be clear, useful HTML with 2 short paragraphs and optionally one ul list, 180-450 words; meta_title 45-60 characters; meta_description 130-160 characters; meta_keyword 4-8 distinct search-intent phrases; seo_keyword a concise lowercase URL slug; ai_search_summary a factual direct answer no more than 280 characters. Avoid keyword stuffing and generic superlatives. Brand/editorial rules: ' . $notes . '. Store profile: ' . json_encode($profile, JSON_UNESCAPED_UNICODE) . '. Category name: ' . $category['name'] . '. Parent category: ' . $parent . '. Existing category description: ' . trim(strip_tags(html_entity_decode($category['description'], ENT_QUOTES, 'UTF-8')));
		$answer = $this->askAi($prompt, $settings); if (isset($answer['error'])) return array('category_id' => $category_id, 'name' => $category['name'], 'error' => $answer['error']);
		$seo = $this->decodeCategorySeo($answer['text']); if (!$seo) return array('category_id' => $category_id, 'name' => $category['name'], 'error' => 'Yapay zekâ geçerli kategori SEO verisi döndürmedi.');
		$slug = $this->uniqueCategoryKeyword($this->slug($seo['seo_keyword']) ?: $this->slug($category['name']), $category_id, $language_id);
		$this->db->query("UPDATE `" . DB_PREFIX . "category_description` SET description='" . $this->db->escape($this->safeCategoryHtml($seo['description'])) . "', meta_title='" . $this->db->escape(utf8_substr($seo['meta_title'], 0, 255)) . "', meta_description='" . $this->db->escape(utf8_substr($seo['meta_description'], 0, 255)) . "', meta_keyword='" . $this->db->escape(utf8_substr($seo['meta_keyword'], 0, 255)) . "' WHERE category_id='" . (int)$category_id . "' AND language_id='" . $language_id . "'");
		$this->db->query("DELETE FROM `" . DB_PREFIX . "seo_url` WHERE query='category_id=" . (int)$category_id . "' AND language_id='" . $language_id . "' AND store_id='0'");
		$this->db->query("INSERT INTO `" . DB_PREFIX . "seo_url` SET store_id='0', language_id='" . $language_id . "', query='category_id=" . (int)$category_id . "', keyword='" . $this->db->escape($slug) . "'");
		$score = $this->seoScore(array('meta_title' => $seo['meta_title'], 'meta_description' => $seo['meta_description'], 'meta_keyword' => $seo['meta_keyword'], 'seo_keyword' => $slug)); $this->recordCategoryHistory($category_id, $category['name'], 'completed', $settings, $answer, $score['score'], '');
		return array('category_id' => $category_id, 'name' => $category['name'], 'keyword' => $slug, 'seo_score' => $score['score'], 'seo_notes' => $score['notes'], 'usage' => isset($answer['usage']) ? $answer['usage'] : array());
	}

	private function askAi($prompt, $s) {
		$provider = isset($s['module_ai_seo_provider']) ? $s['module_ai_seo_provider'] : 'openrouter';
		if ($provider === 'openai') { $key = $s['module_ai_seo_openai_key']; $url = 'https://api.openai.com/v1/chat/completions'; $body = array('model' => $s['module_ai_seo_openai_model'], 'messages' => array(array('role' => 'system', 'content' => 'You produce precise, policy-safe ecommerce SEO JSON. Follow the user constraints exactly and never add text outside JSON.'), array('role' => 'user', 'content' => $prompt)), 'temperature' => 0.35, 'response_format' => array('type' => 'json_object')); }
		elseif ($provider === 'gemini') { $key = $s['module_ai_seo_gemini_key']; $model = $s['module_ai_seo_gemini_model']; $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key); $body = array('contents' => array(array('parts' => array(array('text' => $prompt)))), 'generationConfig' => array('temperature' => 0.35, 'responseMimeType' => 'application/json')); }
		else { $key = $s['module_ai_seo_openrouter_key']; $url = 'https://openrouter.ai/api/v1/chat/completions'; $body = array('model' => $s['module_ai_seo_openrouter_model'], 'messages' => array(array('role' => 'system', 'content' => 'You produce precise, policy-safe ecommerce SEO JSON. Follow the user constraints exactly and never add text outside JSON.'), array('role' => 'user', 'content' => $prompt)), 'temperature' => 0.35, 'response_format' => array('type' => 'json_object')); }
		if (empty($key) || empty($body['model']) && $provider !== 'gemini') return array('error' => 'Seçili sağlayıcı için API anahtarı ve model girilmelidir.');
		$headers = array('Content-Type: application/json'); if ($provider !== 'gemini') $headers[] = 'Authorization: Bearer ' . $key;
		$ch = curl_init($url); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60)); $res = json_decode(curl_exec($ch), true); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
		if ($http >= 400 || empty($res)) return array('error' => isset($res['error']['message']) ? $res['error']['message'] : 'API isteği başarısız oldu.');
		$text = $provider === 'gemini' ? (isset($res['candidates'][0]['content']['parts'][0]['text']) ? $res['candidates'][0]['content']['parts'][0]['text'] : '') : (isset($res['choices'][0]['message']['content']) ? $res['choices'][0]['message']['content'] : '');
		$usage = $provider === 'gemini' ? array('input_tokens' => isset($res['usageMetadata']['promptTokenCount']) ? (int)$res['usageMetadata']['promptTokenCount'] : 0, 'output_tokens' => isset($res['usageMetadata']['candidatesTokenCount']) ? (int)$res['usageMetadata']['candidatesTokenCount'] : 0, 'estimated_cost' => 0) : array('input_tokens' => isset($res['usage']['prompt_tokens']) ? (int)$res['usage']['prompt_tokens'] : 0, 'output_tokens' => isset($res['usage']['completion_tokens']) ? (int)$res['usage']['completion_tokens'] : 0, 'estimated_cost' => isset($res['usage']['cost']) ? (float)$res['usage']['cost'] : 0);
		return $text ? array('text' => $text, 'usage' => $usage) : array('error' => 'API yanıtında içerik bulunamadı.');
	}

	private function decodeSeo($text) { $text = preg_replace('/^```(?:json)?|```$/m', '', trim($text)); $v = json_decode($text, true); return is_array($v) && isset($v['meta_title'], $v['meta_description'], $v['meta_keyword'], $v['seo_keyword']) ? $v : false; }
	private function decodeCategorySeo($text) { $text = preg_replace('/^```(?:json)?|```$/m', '', trim($text)); $v = json_decode($text, true); return is_array($v) && isset($v['description'], $v['meta_title'], $v['meta_description'], $v['meta_keyword'], $v['seo_keyword']) ? $v : false; }
	private function safeCategoryHtml($html) { $html = strip_tags((string)$html, '<p><br><strong><b><em><i><ul><ol><li><h2><h3>'); $html = preg_replace('/\s(on\w+|style)\s*=\s*(["\']).*?\2/is', '', $html); return utf8_substr(trim($html), 0, 12000); }
	private function mergeAiSearchSection($description, $summary, $faq) { $description = preg_replace('/<!-- ai-seo-search:start -->.*?<!-- ai-seo-search:end -->/is', '', (string)$description); $summary = trim(strip_tags((string)$summary)); if (!$summary) return trim($description); $html = '<!-- ai-seo-search:start --><section class="ai-seo-search-answer"><h2>Ürün hakkında kısa bilgi</h2><p>' . htmlspecialchars(utf8_substr($summary, 0, 280), ENT_QUOTES, 'UTF-8') . '</p>'; if (is_array($faq) && $faq) { $html .= '<h3>Sık sorulan sorular</h3><dl>'; foreach (array_slice($faq, 0, 2) as $item) { if (!is_array($item) || empty($item['question']) || empty($item['answer'])) continue; $html .= '<dt>' . htmlspecialchars(utf8_substr(trim(strip_tags($item['question'])), 0, 140), ENT_QUOTES, 'UTF-8') . '</dt><dd>' . htmlspecialchars(utf8_substr(trim(strip_tags($item['answer'])), 0, 280), ENT_QUOTES, 'UTF-8') . '</dd>'; } $html .= '</dl>'; } return trim($description) . "\n" . $html . '</section><!-- ai-seo-search:end -->'; }
	private function slug($s) { $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8'); $s = strtr($s, array('Ç'=>'c','ç'=>'c','Ğ'=>'g','ğ'=>'g','İ'=>'i','I'=>'i','ı'=>'i','Ö'=>'o','ö'=>'o','Ş'=>'s','ş'=>'s','Ü'=>'u','ü'=>'u')); $s = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) : $s; $s = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $s))); return trim($s, '-'); }
	private function getCatalogCategories($language_id) { $rows = $this->db->query("SELECT c.category_id, cd.name FROM `" . DB_PREFIX . "category` c JOIN `" . DB_PREFIX . "category_description` cd ON (c.category_id=cd.category_id) WHERE c.status='1' AND cd.language_id='" . (int)$language_id . "' ORDER BY cd.name ASC LIMIT 200")->rows; $items = array(); foreach ($rows as $row) $items[] = array('id' => (int)$row['category_id'], 'name' => $row['name']); return $items; }
	private function mergeTags($existing, $generated) { if (!is_array($generated)) $generated = preg_split('/[,;|]/u', (string)$generated); $tags = preg_split('/[,;|]/u', (string)$existing); foreach ($generated as $tag) $tags[] = $tag; $clean = array(); $seen = array(); foreach ($tags as $tag) { $tag = trim(preg_replace('/\s+/u', ' ', strip_tags($tag))); $key = $this->slug($tag); if ($tag && $key && utf8_strlen($tag) <= 80 && empty($seen[$key])) { $clean[] = $tag; $seen[$key] = true; } if (count($clean) >= 12) break; } return implode(', ', $clean); }
	private function assignCategories($product_id, $language_id, $category_ids) { if (!is_array($category_ids)) $category_ids = array(); $all = $this->getCatalogCategories($language_id); $available = array(); foreach ($all as $category) $available[(int)$category['id']] = $category['name']; $wanted = array(); foreach ($category_ids as $id) if (isset($available[(int)$id])) $wanted[(int)$id] = (int)$id; foreach ($available as $id => $name) if ($this->slug($name) === 'urunler') $wanted[$id] = $id; foreach ($this->db->query("SELECT category_id FROM `" . DB_PREFIX . "product_to_category` WHERE product_id='" . (int)$product_id . "'")->rows as $row) $wanted[(int)$row['category_id']] = (int)$row['category_id']; $expanded = array(); foreach ($wanted as $id) { $current = (int)$id; $guard = 0; while ($current && $guard++ < 20) { $expanded[$current] = $current; $parent = $this->db->query("SELECT parent_id FROM `" . DB_PREFIX . "category` WHERE category_id='" . $current . "'"); $current = $parent->num_rows ? (int)$parent->row['parent_id'] : 0; } } $added = 0; foreach ($expanded as $id) { $exists = $this->db->query("SELECT product_id FROM `" . DB_PREFIX . "product_to_category` WHERE product_id='" . (int)$product_id . "' AND category_id='" . (int)$id . "'"); if (!$exists->num_rows) { $this->db->query("INSERT INTO `" . DB_PREFIX . "product_to_category` SET product_id='" . (int)$product_id . "', category_id='" . (int)$id . "'"); $added++; } } return $added; }
	private function assignRelatedProducts($product_id, $language_id, $source) { $tokens = $this->seoTokens($source); if (!$tokens) return 0; $source_categories = array(); foreach ($this->db->query("SELECT category_id FROM `" . DB_PREFIX . "product_to_category` WHERE product_id='" . (int)$product_id . "'")->rows as $row) $source_categories[(int)$row['category_id']] = true; $rows = $this->db->query("SELECT p.product_id, pd.name, pd.tag, GROUP_CONCAT(ptc.category_id) AS categories FROM `" . DB_PREFIX . "product` p JOIN `" . DB_PREFIX . "product_description` pd ON (p.product_id=pd.product_id AND pd.language_id='" . (int)$language_id . "') LEFT JOIN `" . DB_PREFIX . "product_to_category` ptc ON (p.product_id=ptc.product_id) WHERE p.status='1' AND p.product_id != '" . (int)$product_id . "' GROUP BY p.product_id LIMIT 1000")->rows; $matches = array(); foreach ($rows as $row) { $score = 0; $haystack = $this->seoTokens($row['name'] . ' ' . $row['tag']); foreach ($tokens as $token) if (in_array($token, $haystack)) $score += 3; foreach (explode(',', (string)$row['categories']) as $category_id) if (isset($source_categories[(int)$category_id])) $score += 2; if ($score >= 3) $matches[(int)$row['product_id']] = $score; } arsort($matches); $added = 0; foreach (array_slice(array_keys($matches), 0, 5) as $related_id) { $exists = $this->db->query("SELECT product_id FROM `" . DB_PREFIX . "product_related` WHERE product_id='" . (int)$product_id . "' AND related_id='" . (int)$related_id . "'"); if (!$exists->num_rows) { $this->db->query("INSERT INTO `" . DB_PREFIX . "product_related` SET product_id='" . (int)$product_id . "', related_id='" . (int)$related_id . "'"); $this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "product_related` SET product_id='" . (int)$related_id . "', related_id='" . (int)$product_id . "'"); $added++; } } return $added; }
	private function seoTokens($text) { $text = str_replace('-', ' ', $this->slug($text)); $stop = array('ve','ile','icin','bir','bu','the','for','plus','new','set','paketi','urun'); $tokens = array(); foreach (explode(' ', $text) as $token) if (strlen($token) >= 3 && !in_array($token, $stop) && !in_array($token, $tokens)) $tokens[] = $token; return $tokens; }
	private function uniqueKeyword($slug, $product_id, $language_id) { $base = $slug ?: 'product-' . $product_id; $slug = $base; $i = 2; while ($this->db->query("SELECT seo_url_id FROM `" . DB_PREFIX . "seo_url` WHERE keyword='" . $this->db->escape($slug) . "' AND language_id='" . (int)$language_id . "' AND query != 'product_id=" . (int)$product_id . "'")->num_rows) $slug = $base . '-' . $i++; return $slug; }
	private function uniqueCategoryKeyword($slug, $category_id, $language_id) { $base = $slug ?: 'category-' . $category_id; $slug = $base; $i = 2; while ($this->db->query("SELECT seo_url_id FROM `" . DB_PREFIX . "seo_url` WHERE keyword='" . $this->db->escape($slug) . "' AND language_id='" . (int)$language_id . "' AND query != 'category_id=" . (int)$category_id . "'")->num_rows) $slug = $base . '-' . $i++; return $slug; }
	private function productSnapshot($product_id, $language_id) { $row = $this->db->query("SELECT pd.product_id, pd.language_id, pd.description, pd.meta_title, pd.meta_description, pd.meta_keyword, pd.tag, su.keyword FROM `" . DB_PREFIX . "product_description` pd LEFT JOIN `" . DB_PREFIX . "seo_url` su ON (su.query=CONCAT('product_id=',pd.product_id) AND su.language_id=pd.language_id AND su.store_id='0') WHERE pd.product_id='" . (int)$product_id . "' AND pd.language_id='" . (int)$language_id . "'"); if (!$row->num_rows) return array(); $snapshot = $row->row; $snapshot['categories'] = array(); foreach ($this->db->query("SELECT category_id FROM `" . DB_PREFIX . "product_to_category` WHERE product_id='" . (int)$product_id . "'")->rows as $category) $snapshot['categories'][] = (int)$category['category_id']; return $snapshot; }
	private function restoreProductSnapshot($snapshot) { $id = (int)$snapshot['product_id']; $language_id = (int)$snapshot['language_id']; $this->db->query("UPDATE `" . DB_PREFIX . "product_description` SET description='" . $this->db->escape($snapshot['description']) . "', meta_title='" . $this->db->escape($snapshot['meta_title']) . "', meta_description='" . $this->db->escape($snapshot['meta_description']) . "', meta_keyword='" . $this->db->escape($snapshot['meta_keyword']) . "', tag='" . $this->db->escape($snapshot['tag']) . "' WHERE product_id='" . $id . "' AND language_id='" . $language_id . "'"); $this->db->query("DELETE FROM `" . DB_PREFIX . "seo_url` WHERE query='product_id=" . $id . "' AND language_id='" . $language_id . "' AND store_id='0'"); if (!empty($snapshot['keyword'])) $this->db->query("INSERT INTO `" . DB_PREFIX . "seo_url` SET store_id='0', language_id='" . $language_id . "', query='product_id=" . $id . "', keyword='" . $this->db->escape($snapshot['keyword']) . "'"); $this->db->query("DELETE FROM `" . DB_PREFIX . "product_to_category` WHERE product_id='" . $id . "'"); foreach (!empty($snapshot['categories']) ? $snapshot['categories'] : array() as $category_id) $this->db->query("INSERT INTO `" . DB_PREFIX . "product_to_category` SET product_id='" . $id . "', category_id='" . (int)$category_id . "'"); }
	private function historyColumn($name) { $columns = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "ai_seo_history` LIKE '" . $this->db->escape($name) . "'"); if (!$columns->num_rows) $this->db->query("ALTER TABLE `" . DB_PREFIX . "ai_seo_history` ADD `" . $this->db->escape($name) . "` MEDIUMTEXT NOT NULL AFTER message"); }
	private function ensureAiSeoTables() { $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "ai_seo_history` (history_id INT(11) NOT NULL AUTO_INCREMENT, product_id INT(11) NOT NULL, product_name VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL, provider VARCHAR(30) NOT NULL, model VARCHAR(128) NOT NULL, seo_score TINYINT(3) NOT NULL DEFAULT 0, input_tokens INT(11) NOT NULL DEFAULT 0, output_tokens INT(11) NOT NULL DEFAULT 0, estimated_cost DECIMAL(12,6) NOT NULL DEFAULT 0, message TEXT NOT NULL, before_data MEDIUMTEXT NOT NULL, after_data MEDIUMTEXT NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (history_id), KEY product_id (product_id), KEY created_at (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8"); $this->historyColumn('before_data'); $this->historyColumn('after_data'); $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "ai_seo_category_history` (history_id INT(11) NOT NULL AUTO_INCREMENT, category_id INT(11) NOT NULL, category_name VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL, provider VARCHAR(30) NOT NULL, model VARCHAR(128) NOT NULL, seo_score TINYINT(3) NOT NULL DEFAULT 0, input_tokens INT(11) NOT NULL DEFAULT 0, output_tokens INT(11) NOT NULL DEFAULT 0, estimated_cost DECIMAL(12,6) NOT NULL DEFAULT 0, message TEXT NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (history_id), KEY category_id (category_id), KEY created_at (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8"); }
	private function recordHistory($product_id, $name, $status, $settings, $answer, $score, $message, $before = array(), $after = array()) { $this->ensureAiSeoTables(); $provider = isset($settings['module_ai_seo_provider']) ? $settings['module_ai_seo_provider'] : 'openrouter'; $model = isset($settings['module_ai_seo_' . $provider . '_model']) ? $settings['module_ai_seo_' . $provider . '_model'] : ''; $usage = isset($answer['usage']) && is_array($answer['usage']) ? $answer['usage'] : array(); $this->db->query("INSERT INTO `" . DB_PREFIX . "ai_seo_history` SET product_id='" . (int)$product_id . "', product_name='" . $this->db->escape(utf8_substr($name, 0, 255)) . "', status='" . $this->db->escape($status) . "', provider='" . $this->db->escape($provider) . "', model='" . $this->db->escape(utf8_substr($model, 0, 128)) . "', seo_score='" . (int)$score . "', input_tokens='" . (int)(isset($usage['input_tokens']) ? $usage['input_tokens'] : 0) . "', output_tokens='" . (int)(isset($usage['output_tokens']) ? $usage['output_tokens'] : 0) . "', estimated_cost='" . (float)(isset($usage['estimated_cost']) ? $usage['estimated_cost'] : 0) . "', message='" . $this->db->escape($message) . "', before_data='" . $this->db->escape(json_encode($before, JSON_UNESCAPED_UNICODE)) . "', after_data='" . $this->db->escape(json_encode($after, JSON_UNESCAPED_UNICODE)) . "', created_at=NOW()"); }
	private function recordCategoryHistory($category_id, $name, $status, $settings, $answer, $score, $message) { $this->ensureAiSeoTables(); $provider = isset($settings['module_ai_seo_provider']) ? $settings['module_ai_seo_provider'] : 'openrouter'; $model = isset($settings['module_ai_seo_' . $provider . '_model']) ? $settings['module_ai_seo_' . $provider . '_model'] : ''; $usage = isset($answer['usage']) && is_array($answer['usage']) ? $answer['usage'] : array(); $this->db->query("INSERT INTO `" . DB_PREFIX . "ai_seo_category_history` SET category_id='" . (int)$category_id . "', category_name='" . $this->db->escape(utf8_substr($name, 0, 255)) . "', status='" . $this->db->escape($status) . "', provider='" . $this->db->escape($provider) . "', model='" . $this->db->escape(utf8_substr($model, 0, 128)) . "', seo_score='" . (int)$score . "', input_tokens='" . (int)(isset($usage['input_tokens']) ? $usage['input_tokens'] : 0) . "', output_tokens='" . (int)(isset($usage['output_tokens']) ? $usage['output_tokens'] : 0) . "', estimated_cost='" . (float)(isset($usage['estimated_cost']) ? $usage['estimated_cost'] : 0) . "', message='" . $this->db->escape($message) . "', created_at=NOW()"); }
	private function historySummary() { $this->ensureAiSeoTables(); $row = $this->db->query("SELECT COUNT(*) AS total, COALESCE(SUM(input_tokens + output_tokens),0) AS tokens, COALESCE(SUM(estimated_cost),0) AS cost, COALESCE(ROUND(AVG(seo_score)),0) AS average_score FROM `" . DB_PREFIX . "ai_seo_history`")->row; return array('total' => (int)$row['total'], 'tokens' => (int)$row['tokens'], 'cost' => (float)$row['cost'], 'average_score' => (int)$row['average_score']); }
	private function taskData() { $language_id = (int)$this->config->get('config_language_id'); $rows = $this->db->query("SELECT p.product_id, p.image, p.model, p.manufacturer_id, pd.name, pd.description, pd.meta_title, pd.meta_description, pd.meta_keyword, su.keyword, COUNT(pa.product_attribute_id) AS attributes FROM `" . DB_PREFIX . "product` p JOIN `" . DB_PREFIX . "product_description` pd ON (p.product_id=pd.product_id AND pd.language_id='" . $language_id . "') LEFT JOIN `" . DB_PREFIX . "seo_url` su ON (su.query=CONCAT('product_id=',p.product_id) AND su.language_id='" . $language_id . "' AND su.store_id='0') LEFT JOIN `" . DB_PREFIX . "product_attribute` pa ON (pa.product_id=p.product_id) WHERE p.status='1' GROUP BY p.product_id LIMIT 1000")->rows; $tasks = array(); $titles = array(); $descriptions = array(); $urls = array(); $clusters = array('Bilgi' => 0, 'Karşılaştırma' => 0, 'Satın alma' => 0); foreach ($rows as $row) { $score = $this->seoScore(array('meta_title' => $row['meta_title'], 'meta_description' => $row['meta_description'], 'meta_keyword' => $row['meta_keyword'], 'seo_keyword' => $row['keyword'])); $missing = array(); if (utf8_strlen(trim(strip_tags($row['description']))) < 80) $missing[] = 'açıklama'; if (!$row['image']) $missing[] = 'görsel'; if (!$row['model']) $missing[] = 'model'; if (!(int)$row['manufacturer_id']) $missing[] = 'marka'; if (!(int)$row['attributes']) $missing[] = 'özellik'; $key = $this->slug($row['meta_title']); if ($key) $titles[$key][] = $row['name']; $dkey = $this->slug(utf8_substr(strip_tags($row['meta_description']), 0, 130)); if ($dkey) $descriptions[$dkey][] = $row['name']; if ($row['keyword']) $urls[$row['keyword']][] = $row['name']; $intent = preg_match('/(karsilast|vs|fark|alternatif)/u', $row['name'] . ' ' . $row['meta_keyword']) ? 'Karşılaştırma' : (preg_match('/(fiyat|satın|satinal|indirim|kampanya)/u', $row['name'] . ' ' . $row['meta_keyword']) ? 'Satın alma' : 'Bilgi'); $clusters[$intent]++; if ($score['score'] < 100 || $missing) $tasks[] = array('product_id' => (int)$row['product_id'], 'name' => $row['name'], 'score' => $score['score'], 'missing' => implode(', ', $missing), 'intent' => $intent); }
		$duplicates = array(); foreach (array('Meta başlığı' => $titles, 'Meta açıklaması' => $descriptions, 'SEO URL' => $urls) as $type => $groups) foreach ($groups as $value => $names) if (count($names) > 1) $duplicates[] = array('type' => $type, 'items' => array_slice($names, 0, 5)); $schema = $this->schemaStatus(); return array('tasks' => array_slice($tasks, 0, 80), 'duplicates' => array_slice($duplicates, 0, 40), 'clusters' => $clusters, 'schema' => $schema);
	}
	private function schemaStatus() { $files = glob(DIR_CATALOG . 'view/theme/*/template/product/product.twig'); $contents = ''; foreach ($files as $file) $contents .= @file_get_contents($file); return array('product' => stripos($contents, '"@type":"Product"') !== false || stripos($contents, '"@type": "Product"') !== false, 'faq' => stripos($contents, 'FAQPage') !== false, 'breadcrumb' => stripos($contents, 'BreadcrumbList') !== false); }
	private function seoScore($seo) { $score = 0; $notes = array(); $titleLength = utf8_strlen($seo['meta_title']); if ($titleLength >= 20 && $titleLength <= 60) $score += 30; else $notes[] = 'Meta başlığı 20–60 karakter aralığında olmalı.'; $descriptionLength = utf8_strlen($seo['meta_description']); if ($descriptionLength >= 70 && $descriptionLength <= 160) $score += 30; else $notes[] = 'Meta açıklaması 70–160 karakter aralığında olmalı.'; if (utf8_strlen(trim($seo['meta_keyword'])) >= 6) $score += 15; else $notes[] = 'Meta anahtar kelimeleri güçlendirilebilir.'; if (!empty($seo['seo_keyword'])) $score += 25; else $notes[] = 'SEO URL anahtar kelimesi eksik.'; return array('score' => $score, 'notes' => $notes); }
	private function scanStorePages($url) {
		$home = $this->fetchPage($url);
		if (empty($home['html'])) return array();
		$host = parse_url($url, PHP_URL_HOST); $pages = array(array('url' => $url, 'html' => $home['html']));
		foreach ($this->importantLinks($home['html'], $url, $host) as $link) {
			if (count($pages) >= 4) break;
			$response = $this->fetchPage($link);
			if (!empty($response['html'])) $pages[] = array('url' => $link, 'html' => $response['html']);
		}
		$profile = $this->pageProfile($pages[0]['html'], $url); $snapshot = array();
		foreach ($pages as $page) { $snapshot[] = $this->pageSnapshot($page['html'], $page['url']); }
		return array('profile' => $profile, 'snapshot' => implode("\n\n--- SAYFA SONU ---\n\n", $snapshot), 'pages_scanned' => count($pages));
	}

	private function fetchPage($url) {
		$ch = curl_init($url); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12, CURLOPT_USERAGENT => 'OpenCart AI SEO Scanner/1.5'));
		$html = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
		return ($html && $code > 0 && $code < 400) ? array('html' => $html) : array();
	}

	private function competitorSnapshots($settings) {
		$raw = !empty($settings['module_ai_seo_competitor_urls']) ? $settings['module_ai_seo_competitor_urls'] : ''; $urls = preg_split('/[\r\n,]+/', $raw); $snapshots = array();
		foreach ($urls as $url) { if (count($snapshots) >= 3) break; $url = trim($url); if (!$this->isSafePublicUrl($url)) continue; $page = $this->fetchPage($url); if (!empty($page['html'])) $snapshots[] = $this->pageSnapshot($page['html'], $url); }
		return $snapshots;
	}

	private function isSafePublicUrl($url) {
		$parts = parse_url($url); if (!$parts || empty($parts['host']) || empty($parts['scheme']) || !in_array(strtolower($parts['scheme']), array('http', 'https'))) return false;
		$ip = gethostbyname($parts['host']); if (!$ip || $ip === $parts['host']) return false;
		return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
	}

	private function importantLinks($html, $base, $host) {
		$links = array(); preg_match_all('/<a[^>]+href=["\']([^"\']+)["\']/i', $html, $matches);
		foreach ($matches[1] as $href) {
			$href = html_entity_decode(trim($href), ENT_QUOTES, 'UTF-8'); if (!$href || strpos($href, '#') === 0 || preg_match('/^(mailto:|tel:|javascript:)/i', $href)) continue;
			$url = $this->absoluteUrl($href, $base); if (!$url || parse_url($url, PHP_URL_HOST) !== $host) continue;
			if (!preg_match('/(hakkimizda|about|iletisim|contact|teslimat|kargo|iade|gizlilik|privacy|sart|kosul|faq|blog)/iu', $url)) continue;
			$url = preg_replace('/[#?].*$/', '', $url); if ($url && !in_array($url, $links)) $links[] = $url;
		}
		return $links;
	}

	private function absoluteUrl($href, $base) {
		$baseParts = parse_url($base); if (!$baseParts || empty($baseParts['scheme']) || empty($baseParts['host'])) return '';
		if (preg_match('#^https?://#i', $href)) return $href;
		if (strpos($href, '//') === 0) return $baseParts['scheme'] . ':' . $href;
		$origin = $baseParts['scheme'] . '://' . $baseParts['host'] . (!empty($baseParts['port']) ? ':' . $baseParts['port'] : '');
		if (strpos($href, '/') === 0) return $origin . $href;
		$path = isset($baseParts['path']) ? $baseParts['path'] : '/'; return $origin . rtrim(str_replace('\\', '/', dirname($path)), '/') . '/' . $href;
	}

	private function pageProfile($html, $url) {
		return array('site_url' => $url, 'title' => $this->match($html, '/<title[^>]*>(.*?)<\\/title>/is'), 'description' => $this->match($html, '/<meta[^>]+name=["\\\']description["\\\'][^>]+content=["\\\'](.*?)["\\\']/is'), 'language' => $this->match($html, '/<html[^>]+lang=["\\\'](.*?)["\\\']/is'), 'logo' => $this->match($html, '/<meta[^>]+property=["\\\']og:image["\\\'][^>]+content=["\\\'](.*?)["\\\']/is'));
	}

	private function pageSnapshot($html, $url) {
		$title = $this->match($html, '/<title[^>]*>(.*?)<\\/title>/is'); $description = $this->match($html, '/<meta[^>]+name=["\\\']description["\\\'][^>]+content=["\\\'](.*?)["\\\']/is');
		preg_match_all('/<h[1-3][^>]*>(.*?)<\\/h[1-3]>/is', $html, $headingMatches); $headings = array(); foreach (isset($headingMatches[1]) ? $headingMatches[1] : array() as $heading) { $heading = trim(html_entity_decode(strip_tags($heading), ENT_QUOTES, 'UTF-8')); if ($heading && !in_array($heading, $headings)) $headings[] = $heading; if (count($headings) >= 12) break; }
		$text = preg_replace('/<script\b[^>]*>.*?<\\/script>|<style\b[^>]*>.*?<\\/style>|<noscript\b[^>]*>.*?<\\/noscript>/is', ' ', $html); $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8')));
		return "URL: " . $url . "\nBaşlık: " . $title . "\nAçıklama: " . $description . "\nBaşlıklar: " . implode(' | ', $headings) . "\nGörünür metin: " . utf8_substr($text, 0, 5000);
	}

	private function analyzeBrand($snapshot, $settings, $profile) {
		$provider = isset($settings['module_ai_seo_provider']) ? $settings['module_ai_seo_provider'] : 'openrouter';
		$keyName = 'module_ai_seo_' . $provider . '_key';
		if (empty($settings[$keyName])) return array('ai_ready' => false, 'ai_response' => 'Detaylı sayfa sinyalleri toplandı. Yapay zekâ yorumunu oluşturmak için seçili sağlayıcıya ait API anahtarını kaydedin.', 'pages_scanned' => 0);
		$competitors = $this->competitorSnapshots($settings);
		$prompt = 'You are a senior e-commerce brand and SEO strategist. Analyze the provided website scan and, when supplied, competitor page snapshots. Do not claim to have searched Google or to know rankings. Infer likely search intent only from the page signals. Do not invent facts. Reply ONLY valid JSON with: ai_response (Turkish, clear 2-4 paragraph analysis), brand_identity (short), target_audience (short), tone (short), value_propositions (array max 5), seo_opportunities (array max 5), do_not_use (array max 5), search_intent (array max 5), competitor_gaps (array max 5), suggested_brand_notes (Turkish short instruction). Website profile: ' . json_encode($profile, JSON_UNESCAPED_UNICODE) . '. Store scan data: ' . utf8_substr($snapshot, 0, 18000) . '. Competitor page snapshots: ' . utf8_substr(implode("\n\n--- RAKİP SAYFA ---\n", $competitors), 0, 12000);
		$answer = $this->askAi($prompt, $settings); if (isset($answer['error'])) return array('ai_ready' => false, 'ai_response' => 'Sayfalar tarandı; ancak AI yorumu oluşturulamadı: ' . $answer['error']);
		$analysis = json_decode(preg_replace('/^```(?:json)?|```$/m', '', trim($answer['text'])), true);
		if (!is_array($analysis) || empty($analysis['ai_response'])) return array('ai_ready' => false, 'ai_response' => 'Sayfalar tarandı; ancak AI yanıtı okunabilir bir analiz formatında gelmedi.');
		$clean = array('ai_ready' => true, 'competitors_scanned' => count($competitors)); foreach (array('ai_response','brand_identity','target_audience','tone','suggested_brand_notes') as $key) $clean[$key] = isset($analysis[$key]) ? trim(strip_tags((string)$analysis[$key])) : ''; foreach (array('value_propositions','seo_opportunities','do_not_use','search_intent','competitor_gaps') as $key) { $clean[$key] = array(); if (!empty($analysis[$key]) && is_array($analysis[$key])) foreach (array_slice($analysis[$key], 0, 5) as $item) { $item = trim(strip_tags((string)$item)); if ($item) $clean[$key][] = $item; } }
		return $clean;
	}

	private function match($html, $pattern) { return preg_match($pattern, $html, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')) : ''; }
	private function languageData() { $data = array(); foreach (array('heading_title','text_home','text_extension','text_success','text_scan_success','error_permission','error_request','error_scan','error_products') as $key) $data[$key] = $this->language->get($key); return $data; }
	public function install() { $this->load->model('setting/setting'); $this->model_setting_setting->editSetting('module_ai_seo', array('module_ai_seo_status' => '1', 'module_ai_seo_provider' => 'openrouter', 'module_ai_seo_openrouter_model' => 'openai/gpt-4o-mini', 'module_ai_seo_openai_model' => 'gpt-4o-mini', 'module_ai_seo_gemini_model' => 'gemini-1.5-flash', 'module_ai_seo_generation_tone' => 'professional', 'module_ai_seo_max_products' => '30')); }
	public function uninstall() { $this->load->model('setting/setting'); $this->model_setting_setting->deleteSetting('module_ai_seo'); }
}
