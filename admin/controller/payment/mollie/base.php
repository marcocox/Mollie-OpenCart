<?php
/**
 * Copyright (c) 2012-2015, Mollie B.V.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * - Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 * - Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE AUTHOR AND CONTRIBUTORS ``AS IS'' AND ANY
 * EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE AUTHOR OR CONTRIBUTORS BE LIABLE FOR ANY
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY
 * OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH
 * DAMAGE.
 *
 * @package     Mollie
 * @license     Berkeley Software Distribution License (BSD-License 2) http://www.opensource.org/licenses/bsd-license.php
 * @author      Mollie B.V. <info@mollie.com>
 * @copyright   Mollie B.V.
 * @link        https://www.mollie.com
 *
 * @property Config                       $config
 * @property DB                           $db
 * @property Language                     $language
 * @property Loader                       $load
 * @property ModelSettingSetting          $model_setting_setting
 * @property ModelSettingStore            $model_setting_store
 * @property ModelLocalisationOrderStatus $model_localisation_order_status
 * @property Request                      $request
 * @property Response                     $response
 * @property URL                          $url
 * @property User                         $user
 */
require_once(dirname(DIR_SYSTEM) . "/catalog/controller/payment/mollie/helper.php");

class ControllerPaymentMollieBase extends Controller
{
	// Current module name - should be overwritten by subclass using one of the MollieHelper::MODULE_NAME_* values.
	const MODULE_NAME = NULL;

	// Initialize var(s)
	protected $error = array();

	/**
	 * @return Mollie_API_Client
	 */
	protected function getAPIClient ()
	{
		return MollieHelper::getAPIClient($this->config);
	}

	/**
	 * This method is executed by OpenCart when the Payment module is installed from the admin. It will create the
	 * required tables.
	 *
	 * @return void
	 */
	public function install ()
	{
		$this->db->query(
			sprintf(
				"CREATE TABLE IF NOT EXISTS `%smollie_payments` (
					`order_id` int(11) unsigned NOT NULL,
					`method` enum('idl') NOT NULL DEFAULT 'idl',
					`transaction_id` varchar(32) NOT NULL,
					`bank_account` varchar(15) NOT NULL,
					`bank_status` varchar(20) NOT NULL,
					PRIMARY KEY (`order_id`),
					UNIQUE KEY `transaction_id` (`transaction_id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8",
				DB_PREFIX
			));

		// Just install all modules while we're at it.
		$this->installAllModules();
	}

	/**
	 * Trigger installation of all Mollie modules.
	 */
	protected function installAllModules ()
	{
		// Load models.
		$extensions = $this->getExtensionModel();
		$this->load->model("user/user_group");

		if ($this->isOpencart2())
		{
			$user_id = $this->user->getGroupId();
		}
		else
		{
			$user_id = $this->user->getId();
		}

		foreach (MollieHelper::$MODULE_NAMES as $module_name)
		{
			// Install extension.
			$extensions->install("payment", "mollie_" . $module_name);

			// Set permissions.
			$this->model_user_user_group->addPermission($user_id, "access", "payment/mollie_" . $module_name);
			$this->model_user_user_group->addPermission($user_id, "modify", "payment/mollie_" . $module_name);
		}
	}

	/**
	 * The method is executed by OpenCart when the Payment module is uninstalled from the admin. It will not drop the Mollie
	 * table at this point - we want to allow the user to toggle payment modules without losing their settings.
	 *
	 * @return void
	 */
	public function uninstall ()
	{
		$this->uninstallAllModules();
	}

	/**
	 * Trigger removal of all Mollie modules.
	 */
	protected function uninstallAllModules ()
	{
		$extensions = $this->getExtensionModel();

		foreach (MollieHelper::$MODULE_NAMES as $module_name)
		{
			$extensions->uninstall("payment", "mollie_" . $module_name);
		}
	}

	/**
	 * Get the extension installation handler.
	 *
	 * @return Model
	 */
	protected function getExtensionModel ()
	{
		if ($this->isOpencart2())
		{
			$this->load->model("extension/extension");

			return $this->model_extension_extension;
		}

		$this->load->model("setting/extension");

		return $this->model_setting_extension;
	}

	/**
	 * Render the payment method's settings page.
	 */
	public function index ()
	{
		// Load essential models
		$this->load->language("payment/mollie");
		$this->load->model("setting/setting");
		$this->load->model("setting/store");
		$this->load->model("localisation/order_status");

		$this->document->setTitle($this->language->get("heading_title"));

		// Call validate method on POST
		if (($this->request->server['REQUEST_METHOD'] == "POST") && ($this->validate()))
		{
			$post = $this->request->post;

			// Collect API key overrides in comma separated string
			$api_key_overrides = array();
			foreach($post as $k => $v) {
				if ((strpos($k, 'mollie_api_key_override_') !== false) && ($v != "")) {
					$api_key_overrides[] = $v;
				}
			}
			$post['mollie_api_key_overrides'] = implode(',', $api_key_overrides);

			foreach (MollieHelper::$MODULE_NAMES as $module_name)
			{
				$status = "mollie_" . $module_name . "_status";

				$post[$status] = (isset($post[$status]) && $post[$status] == "on") ? 1 : 0;
			}

			$this->model_setting_setting->editSetting("mollie", $post);

			// Migrate old settings if needed. We used to use "ideal" as setting group, but Opencart 2 requires us to use "mollie".
			$this->model_setting_setting->deleteSetting("ideal");

			$this->session->data['success'] = $this->language->get("text_success");

			$this->redirect($this->url->link("extension/payment", "token=" . $this->session->data['token'], "SSL"));
		}

		// Set data for template
		$data['heading_title']          = $this->language->get("heading_title");
		$data['title_global_options']   = $this->language->get("title_global_options");
		$data['title_payment_status']   = $this->language->get("title_payment_status");
		$data['title_mod_about']        = $this->language->get("title_mod_about");
		$data['footer_text']            = $this->language->get("footer_text");

		$data['text_enabled']                 = $this->language->get("text_enabled");
		$data['text_disabled']                = $this->language->get("text_disabled");
		$data['text_yes']                     = $this->language->get("text_yes");
		$data['text_no']                      = $this->language->get("text_no");
		$data['text_none']                    = $this->language->get("text_none");
		$data['text_edit']                    = $this->language->get("text_edit");
		$data['text_missing_api_key']         = $this->language->get("text_missing_api_key");
		$data['text_activate_payment_method'] = $this->language->get("text_activate_payment_method");

		$data['entry_api_key']          = $this->language->get("entry_api_key");
		$data['entry_description']      = $this->language->get("entry_description");
		$data['entry_show_icons']       = $this->language->get("entry_show_icons");
		$data['entry_status']           = $this->language->get("entry_status");
		$data['entry_mod_status']       = $this->language->get("entry_mod_status");
		$data['entry_comm_status']      = $this->language->get("entry_comm_status");

		$data['help_view_profile']     = $this->language->get("help_view_profile");
		$data['help_api_key']          = $this->language->get("help_api_key");
		$data['help_description']      = $this->language->get("help_description");
		$data['help_show_icons']       = $this->language->get("help_show_icons");
		$data['help_status']           = $this->language->get("help_status");

		$data['order_statuses']         = $this->model_localisation_order_status->getOrderStatuses();
		$data['entry_failed_status']    = $this->language->get("entry_failed_status");
		$data['entry_canceled_status']  = $this->language->get("entry_canceled_status");
		$data['entry_pending_status']   = $this->language->get("entry_pending_status");
		$data['entry_expired_status']   = $this->language->get("entry_expired_status");
		$data['entry_processing_status']= $this->language->get("entry_processing_status");
		$data['entry_processed_status'] = $this->language->get("entry_processed_status");

		$data['entry_payment_method']   = $this->language->get("entry_payment_method");
		$data['entry_activate']         = $this->language->get("entry_activate");
		$data['entry_sort_order']       = $this->language->get("entry_sort_order");
		$data['entry_support']          = $this->language->get("entry_support");
		$data['entry_mstatus']          = $this->checkModuleStatus();
		$data['entry_cstatus']          = $this->checkCommunicationStatus();
		$data['entry_module']           = $this->language->get("entry_module");
		$data['entry_version']          = $this->language->get("entry_version") . " " . MollieHelper::PLUGIN_VERSION;

		$data['button_save']            = $this->language->get("button_save");
		$data['button_cancel']          = $this->language->get("button_cancel");

		$data['tab_general']            = $this->language->get("tab_general");

		// If there are errors, show the error.
		if (isset($this->error['warning'])) {
			$data['error_warning'] = $this->error['warning'];
		} else {
			$data['error_warning'] = '';
		}

		if (isset($this->error['api_key'])) {
			$data['error_api_key'] = $this->error['api_key'];
		} else {
			$data['error_api_key'] = '';
		}

		if (isset($this->error['description'])) {
			$data['error_description'] = $this->error['description'];
		} else {
			$data['error_description'] = '';
		}

		if (isset($this->error['show_icons'])) {
			$data['error_show_icons'] = $this->error['show_icons'];
		} else {
			$data['error_show_icons'] = '';
		}

		if (isset($this->error['total'])) {
			$data['error_total'] = $this->error['total'];
		} else {
			$data['error_total'] = '';
		}

		$data['error_file_missing'] = $this->language->get("error_file_missing");

		// Breadcrumbs
		$data['breadcrumbs'] = array();

		$data['breadcrumbs'][] = array(
			"href"      => $this->url->link("common/home", "token=" . $this->session->data['token'], "SSL"),
			"text"      => $this->language->get("text_home"),
			"separator" => FALSE,
		);

		$data['breadcrumbs'][] = array(
			"href"      => $this->url->link("extension/payment", "token=" . $this->session->data['token'], "SSL"),
			"text"      => $this->language->get("text_payment"),
			"separator" => ' :: ',
		);

		$data['breadcrumbs'][] = array(
			"href"      => $this->url->link("payment/mollie_" . static::MODULE_NAME, "token=" . $this->session->data['token'], "SSL"),
			"text"      => $this->language->get("heading_title"),
			"separator" => " :: ",
		);

		// Form action url
		$data['action'] = $this->url->link("payment/mollie_" . static::MODULE_NAME, "token=" . $this->session->data['token'], "SSL");
		$data['cancel'] = $this->url->link("extension/payment", "token=" . $this->session->data['token'], "SSL");

		// Load global settings. Some are prefixed with mollie_ideal_ for legacy reasons.
		$settings = array(
			"mollie_api_key"                    => NULL,
			"mollie_ideal_description"          => "Order %",
			"mollie_show_icons"                 => FALSE,
			"mollie_ideal_pending_status_id"    => 1,
			"mollie_ideal_processing_status_id" => 2,
			"mollie_ideal_canceled_status_id"   => 7,
			"mollie_ideal_failed_status_id"     => 10,
			"mollie_ideal_expired_status_id"    => 14,
		);

		$this->load->model('sale/customer_group');
		if ($results = $this->model_sale_customer_group->getCustomerGroups()) {
			$data['customer_groups'] = array();
			$data['mollie_api_key_override'] = array();
			foreach($results as $result) {
				$data['customer_groups'][$result['customer_group_id']] = $result['name'];
				if (isset($this->request->post['mollie_api_key_override_'.$result['customer_group_id']]))
				{
					$data['mollie_api_key_override'][$result['customer_group_id']] = $this->request->post['mollie_api_key_override_'.$result['customer_group_id']];
				}
				elseif ($this->config->get('mollie_api_key_override_'.$result['customer_group_id']))
				{
					$data['mollie_api_key_override'][$result['customer_group_id']] = $this->config->get('mollie_api_key_override_'.$result['customer_group_id']);
				}
				else
				{
					$data['mollie_api_key_override'][$result['customer_group_id']] = '';
				}
				
			}
		}

		foreach ($settings as $setting_name => $default_value)
		{
			// Attempt to read from post
			if (isset($this->request->post[$setting_name]))
			{
				$data[$setting_name] = $this->request->post[$setting_name];
			}

			// Otherwise, attempt to get the setting from the database
			else
			{
				$stored_setting = $this->config->get($setting_name);

				if(!$stored_setting && $default_value !== NULL)
				{
					$data[$setting_name] = $default_value;
				}
				else
				{
					$data[$setting_name] = $stored_setting;
				}
			}
		}

		// Check which payment methods we can use with the current API key.
		$allowed_methods = array();

		try
		{
			$api_methods = $this->getAPIClient()->methods->all();

			foreach ($api_methods as $api_method)
			{
				$allowed_methods[] = $api_method->id;
			}
		}
		catch (Mollie_API_Exception $e)
		{
			// If we have an unauthorized request, our API key is likely invalid.
			if ($data['mollie_api_key'] !== NULL && strpos($e->getMessage(), "Unauthorized request") >= 0)
			{
				$data['error_api_key'] = $this->language->get("error_api_key_invalid");
			}
		}

		$data['payment_methods'] = array();

		foreach (MollieHelper::$MODULE_NAMES as $module_name)
		{
			$payment_method = array();

			$payment_method['name']    = $this->language->get("name_mollie_" . $module_name);
			$payment_method['icon']    = "https://www.mollie.com/images/payscreen/methods/" . $module_name . ".png";
			$payment_method['allowed'] = in_array($module_name, $allowed_methods);

			// Load module specific settings.
			if (isset($this->request->post['mollie_' . $module_name . '_status']))
			{
				$payment_method['status'] = ($this->request->post['mollie_' . $module_name . '_status'] == "on");
			}
			else
			{
				$payment_method['status'] = (bool) $this->config->get("mollie_" . $module_name . "_status");
			}

			if (isset($this->request->post['mollie_' . $module_name . '_sort_order']))
			{
				$payment_method['sort_order'] = $this->request->post['mollie_' . $module_name . '_sort_order'];
			}
			else
			{
				$payment_method['sort_order'] = $this->config->get("mollie_" . $module_name . "_sort_order");
			}

			$data['payment_methods'][$module_name] = $payment_method;
		}

		// Set different template for Opencart 2 as it uses Bootstrap and a left column
		if ($this->isOpencart2())
		{
			$this->renderTemplate("payment/mollie_2.tpl", $data, array(
				"header",
				"column_left",
				"footer",
			));
		}
		else
		{
			$this->renderTemplate("payment/mollie.tpl", $data, array(
				"header",
				"footer",
			));
		}
	}

	/**
	 * Check the post and check if the user has permission to edit the module settings
	 *
	 * @return bool
	 */
	private function validate ()
	{
		if (!$this->user->hasPermission("modify", "payment/mollie_" . static::MODULE_NAME))
		{
			$this->error['warning'] = $this->language->get("error_permission");
		}

		if (!$this->request->post['mollie_api_key'])
		{
			$this->error['api_key'] = $this->language->get("error_api_key");
		}

		if (!$this->request->post['mollie_ideal_description'])
		{
			$this->error['description'] = $this->language->get("error_description");
		}

		return (count($this->error) == 0);
	}

	protected function checkModuleStatus ()
	{
		$need_files = array();
		$mod_files  = array(
			DIR_APPLICATION . "controller/payment/mollie/base.php",
			DIR_APPLICATION . "language/english/payment/mollie.php",
			DIR_TEMPLATE . "payment/mollie.tpl",
			DIR_TEMPLATE . "payment/mollie_2.tpl",
			DIR_CATALOG . "controller/payment/mollie-api-client/",
			DIR_CATALOG . "controller/payment/mollie/base.php",
			DIR_CATALOG . "language/english/payment/mollie.php",
			DIR_CATALOG . "model/payment/mollie/base.php",
			DIR_CATALOG . "view/theme/default/template/payment/mollie_checkout_form.tpl",
			DIR_CATALOG . "view/theme/default/template/payment/mollie_return.tpl",
			DIR_CATALOG . "view/theme/default/template/payment/mollie_return_2.tpl",
		);

		foreach (MollieHelper::$MODULE_NAMES as $module_name)
		{
			$mod_files[] = DIR_APPLICATION . "controller/payment/mollie_" . $module_name . ".php";
			$mod_files[] = DIR_APPLICATION . "language/english/payment/mollie_" . $module_name . ".php";
			$mod_files[] = DIR_CATALOG . "controller/payment/mollie_" . $module_name . ".php";
			$mod_files[] = DIR_CATALOG . "model/payment/mollie_" . $module_name . ".php";
		}

		foreach ($mod_files as $file)
		{
			$file = realpath($file);

			if (!file_exists($file))
			{
				$need_files[] = '<span style="color:red">' . $file . '</span>';
			}
		}

		if (count($need_files) > 0)
		{
			return $need_files;
		}

		return '<span style="color:green">OK</span>';
	}

	/**
	 * @return string
	 */
	protected function checkCommunicationStatus ()
	{
		try
		{
			$this->getAPIClient()->methods->all();

			return '<span style="color: green">OK</span>';
		}
		catch (Mollie_API_Exception $e)
		{
			return '<span style="color:red">' . htmlspecialchars($e->getMessage()) . '</span>';
		}
	}

	/**
	 * Map template handling for different Opencart versions
	 *
	 * @param string $template
	 * @param array  $data
	 * @param array  $common_children
	 * @param bool   $echo
	 */
	protected function renderTemplate ($template, $data, $common_children = array(), $echo = TRUE)
	{
		if ($this->isOpencart2())
		{
			foreach ($common_children as $child)
			{
				$data[$child] = $this->load->controller("common/" . $child);
			}

			$html = $this->load->view($template, $data);
		}
		else
		{
			foreach ($data as $field => $value)
			{
				$this->data[$field] = $value;
			}

			$this->template = $template;

			$this->children = array();

			foreach ($common_children as $child)
			{
				$this->children[] = "common/" . $child;
			}

			$html = $this->render();
		}

		if ($echo)
		{
			return $this->response->setOutput($html);
		}

		return $html;
	}

	/**
	 * @param string $url
	 * @param int    $status
	 */
	protected function redirect ($url, $status = 302)
	{
		if ($this->isOpencart2())
		{
			$this->response->redirect($url, $status);
		}
		else
		{
			parent::redirect($url, $status);
		}
	}

	/**
	 * @return bool
	 */
	protected function isOpencart2 ()
	{
		return version_compare(VERSION, 2, ">=");
	}
}
