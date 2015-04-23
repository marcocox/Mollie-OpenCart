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
 * @property Config             $config
 * @property Currency           $currency
 * @property array              $data
 * @property Document           $document
 * @property Language           $language
 * @property Loader             $load
 * @property Log                $log
 * @property ModelCheckoutOrder $model_checkout_order
 * @property Request            $request
 * @property Response           $response
 * @property Session            $session
 * @property URL                $url
 *
 * @method render
 */
require_once(dirname(DIR_SYSTEM) . "/catalog/controller/payment/mollie/helper.php");

class ControllerPaymentMollieBase extends Controller
{
	// Current module name - should be overwritten by subclass using one of the values below.
	const MODULE_NAME = NULL;

	/**
	 * @return Mollie_API_Client
	 */
	protected function getAPIClient ()
	{
		return MollieHelper::getAPIClient($this->config, $this->customer->getCustomerGroupId());
	}

	/**
	 * @return ModelPaymentMollieBase
	 */
	protected function getModuleModel ()
	{
		$model_name = "model_payment_mollie_" . static::MODULE_NAME;

		if (!isset($this->$model_name))
		{
			$this->load->model("payment/mollie_" . static::MODULE_NAME);
		}

		return $this->$model_name;
	}

	/**
	 * Get the order we are processing from OpenCart.
	 *
	 * @return array
	 */
	protected function getOpenCartOrder ()
	{
		$this->load->model("checkout/order");

		if (empty($this->session->data['order_id']))
		{
			return array();
		}

		// Load last order from session
		return $this->model_checkout_order->getOrder($this->session->data['order_id']);
	}

	/**
	 * This gets called by OpenCart at the final checkout step and should generate a confirmation button.
	 */
	public function index ()
	{
		$this->load->language("payment/mollie");

		// Set template data.
		$data['action']         = $this->url->link("payment/mollie_" . static::MODULE_NAME . "/payment", "", "SSL");
		$data['message']        = $this->language;
		$data['issuers']        = $this->getIssuers();
		$data['text_issuer']    = $this->language->get("text_issuer");
		$data['set_issuer_url'] = $this->url->link("payment/mollie_" . static::MODULE_NAME . "/set_issuer", "", "SSL");

		// Return HTML output - it will get appended to confirm.tpl.
		return $this->renderTemplate("mollie_checkout_form", $data, array(), FALSE);
	}

	/**
	 * Get all issuers for the current payment method.
	 *
	 * @return array
	 */
	public function getIssuers ()
	{
		$issuers            = $this->getAPIClient()->issuers->all();
		$issuers_for_method = array();

		foreach ($issuers as $issuer)
		{
			if ($issuer->method == static::MODULE_NAME)
			{
				$issuers_for_method[] = $issuer;
			}
		}

		return $issuers_for_method;
	}

	/**
	 * The payment action creates the payment and redirects the customer to the selected bank.
	 *
	 * It is called when the customer submits the button generated in the mollie_checkout_form template.
	 */
	public function payment ()
	{
		if ($this->request->server['REQUEST_METHOD'] == "POST")
		{
			try
			{
				$api = $this->getAPIClient();
			}
			catch (Mollie_API_Exception $e)
			{
				$this->showErrorPage($e->getMessage());
				return;
			}

			// Load essentials
			$this->load->language("payment/mollie");

			$model = $this->getModuleModel();
			$order = $this->getOpenCartOrder();

			// Check if a payment already exists for this order and redirect to that paymentUrl if it is still open.
			$payment_id = $model->getTransactionIDByOrderID($order['order_id']);

			if ($payment_id)
			{
				try
				{
					$payment = $api->payments->get($payment_id);
				}
				catch (Mollie_API_Exception $e)
				{
					$this->showErrorPage($e->getMessage());
					return;
				}

				if (!empty($payment))
				{
					// A payment for this order has already been created. If the status is still open, we can redirect to
					// this paymentUrl. Otherwise an error must be thrown.
					if ($payment->isOpen() && $order['order_status_id'] === $this->config->get("mollie_ideal_pending_status_id"))
					{
						$this->redirect($payment->getPaymentUrl());
					}
					else
					{
						$this->showErrorPage("A payment already exists for order ID {$order['order_id']}.");
						return;
					}
				}
			}

			$amount = $this->currency->convert($order['total'], $this->config->get("config_currency"), "EUR");

			$amount      = round($amount, 2);
			$description = str_replace("%", $order['order_id'], html_entity_decode($this->config->get("mollie_ideal_description"), ENT_QUOTES, "UTF-8"));
			$return_url  = $this->url->link("payment/mollie_" . static::MODULE_NAME . "/callback", "", "SSL");
			$issuer      = $this->getIssuer();

			try
			{
				$data = array(
					"amount"            => $amount,
					"description"       => $description,
					"redirectUrl"       => $return_url,
					"webhookUrl"        => $this->getWebhookUrl(),
					"metadata"          => array("order_id" => $order['order_id']),
					"method"            => static::MODULE_NAME,
					"issuer"            => $issuer,

					/*
					 * This data is sent along for credit card payments / fraud checks. You can remove this but you will
					 * have a higher conversion if you leave it here.
					 */

					"billingCity"       => $order['payment_city'],
					"billingRegion"     => $order['payment_zone'],
					"billingPostal"     => $order['payment_postcode'],
					"billingCountry"    => $order['payment_iso_code_2'],

					"shippingAddress"   => $order['shipping_address_1']  ? $order['shipping_address_1']  : NULL,
					"shippingCity"      => $order['shipping_city']       ? $order['shipping_city']       : $order['payment_city'],
					"shippingRegion"    => $order['shipping_zone']       ? $order['shipping_zone']       : $order['payment_zone'],
					"shippingPostal"    => $order['shipping_postcode']   ? $order['shipping_postcode']   : $order['payment_postcode'],
					"shippingCountry"   => $order['shipping_iso_code_2'] ? $order['shipping_iso_code_2'] : $order['payment_iso_code_2'],
				);

				// Create the payment, if succeeded confirm the order and redirect the customer to the bank
				try
				{
					$payment = $api->payments->create($data);
				}
				catch (Mollie_API_Exception $e)
				{
					// If it fails because of the webhookUrl then clear it and retry.
					if ($e->getField() == "webhookUrl")
					{
						unset($data['webhookUrl']);
						$payment = $api->payments->create($data);
					}
					else
					{
						throw $e;
					}
				}
			}
			catch (Mollie_Api_Exception $e)
			{
				$this->showErrorPage($e->getMessage());
				return;
			}

			// Add pending status for starters.
			$this->addOrderHistory($order['order_id'], $this->config->get("mollie_ideal_pending_status_id"), $this->language->get("text_redirected"), FALSE);

			$model->setPayment($order['order_id'], $payment->id);

			// Redirect to payment gateway.
			$this->redirect($payment->links->paymentUrl);
		}
	}

	/**
	 * This action is getting called by Mollie to report the payment status
	 */
	public function webhook ()
	{
		// Mollie called this webhook to verify if it was reachable
		if (!empty($this->request->get['testByMollie']))
		{
			// returns status 200
			return;
		}

		$payment_id = isset($this->request->post['id']) ? $this->request->post['id'] : 0;

		if (empty($payment_id))
		{
			header("HTTP/1.0 400 Bad Request");
			echo "No ID received.";
			return;
		}

		$payment = null;
		try {
			// First try with default API key
			$payment = $this->getAPIClient()->payments->get($payment_id);
		} catch (Exception $e) {
			// Try override API keys
			$api_key_overrides = $this->config->get("mollie_api_key_overrides");
			if ($api_key_overrides != '') {
				$api_keys = explode(',', $api_key_overrides);
				foreach ($api_keys as $api_key) {
					try {
						$api_client = MollieHelper::getAPIClientFixedKey($this->config, $api_key);
						$payment = $api_client->payments->get($payment_id);
						break;
					} catch (Exception $e) {
						continue;
					}
				}
			}
		}
		
		if ($payment == null) {
			$this->log->wirte("Mollie webhook call failed because payment status cannot be retrieved. Please check your API keys.");
			return;
		}

		// Load essentials
		$this->load->model("checkout/order");
		$this->getModuleModel();
		$this->load->language("payment/mollie");

		//Get order_id of this transaction from db
		$order    = $this->model_checkout_order->getOrder($payment->metadata->order_id);

		if (!empty($order))
		{
			// Only if the transaction is in 'pending' status
			if ($order['order_status_id'] === $this->config->get("mollie_ideal_pending_status_id"))
			{
				if ($payment->isPaid())
				{
					echo "The payment was received and the order was moved to the processing status.";
					$this->addOrderHistory($order['order_id'], $this->config->get("mollie_ideal_processing_status_id"), $this->language->get("response_success"), TRUE); // Processed
					return;
				}

				if ($payment->status == Mollie_API_Object_Payment::STATUS_CANCELLED)
				{
					echo "The payment was cancelled and the order was moved to the canceled status.";
					$this->addOrderHistory($order['order_id'], $this->config->get("mollie_ideal_canceled_status_id"), $this->language->get("response_cancelled"), FALSE); // Canceled
					return;
				}

				if ($payment->status == Mollie_API_Object_Payment::STATUS_EXPIRED)
				{
					echo "The payment was expired and the order was moved to the expired status.";
					$this->addOrderHistory($order['order_id'], $this->config->get("mollie_ideal_expired_status_id"), $this->language->get("response_expired"), FALSE); // Expired
					return;
				}

				echo "The payment failed for an unknown reason, order was updated.";
				$this->addOrderHistory($order['order_id'], $this->config->get("mollie_ideal_failed_status_id"), $this->language->get("response_unknown"), FALSE); // Fail
			}
			else
			{
				echo "The order was already processed before.";
			}
		}

		echo " Done.";
	}

	/**
	 * Gets called via AJAX from the checkout form to store the selected issuer.
	 */
	public function set_issuer ()
	{
		if (!empty($this->request->post['mollie_issuer_id']))
		{
			$this->session->data['mollie_issuer'] = $this->request->post['mollie_issuer_id'];
		}
		else
		{
			$this->session->data['mollie_issuer'] = NULL;
		}

		echo $this->session->data['mollie_issuer'];
	}

	/**
	 * Retrieve the issuer if one was selected. Return NULL otherwise.
	 *
	 * @return string|NULL
	 */
	protected function getIssuer ()
	{
		if (!empty($this->request->post['mollie_issuer']))
		{
			return $this->request->post['mollie_issuer'];
		}

		if (!empty($this->session->data['mollie_issuer']))
		{
			return $this->session->data['mollie_issuer'];
		}

		return NULL;
	}

	/**
	 * Customer returning from the bank with an transaction_id
	 * Depending on what the state of the payment is they get redirected to the corresponding page
	 */
	public function callback ()
	{
		$order = $this->getOpenCartOrder();

		// Load required translations.
		$this->load->language("payment/mollie");

		// Now that the customer has returned to our web site, check if we already know if the payment has succeeded. If the payment is all good, we need to clear the cart.
		if ($order && $order["order_status_id"] == $this->config->get("mollie_ideal_processing_status_id"))
		{
			// Redirect to 'success' page.
			$this->redirect($this->url->link("checkout/success", "", "SSL"));
			return;
		}

		// When an order could be found, check if Mollie has reported the new status. When the order status is still pending, the report is not delivered yet.
		elseif ($order && $order['order_status_id'] == $this->config->get("mollie_ideal_pending_status_id"))
		{
			if ($this->cart)
			{
				$this->cart->clear();
			}

			// Show a 'transaction pending' page.
			return $this->showReturnPage(
				$this->language->get("heading_unknown"),
				$this->language->get("msg_unknown"),
				NULL,
				FALSE
			);
		}

		// Show a 'transaction failed' page.
		return $this->showReturnPage(
			$this->language->get("heading_failed"),
			$this->language->get("msg_failed")
		);
	}

	/**
	 * @param &$data
	 */
	protected function setBreadcrumbs (&$data)
	{
		$data['breadcrumbs'] = array();

		$data['breadcrumbs'][] = array(
			"href"      => $this->url->link("common/home", (isset($this->session->data['token'])) ? "token=" . $this->session->data['token'] : "", "SSL"),
			"text"      => $this->language->get("text_home"),
			"separator" => FALSE,
		);
	}

	/**
	 * @param $message
	 *
	 * @return string
	 */
	protected function showErrorPage ($message)
	{
		$this->load->language("payment/mollie");

		$this->log->write("Error setting up transaction with Mollie: {$message}.");

		return $this->showReturnPage(
			$this->language->get("heading_error"),
			$this->language->get("text_error"),
			$message
		);
	}

	/**
	 * Render a return page.
	 *
	 * @param string      $title      The title of the status page.
	 * @param string      $body       The status message.
	 * @param string|NULL $api_error  Show an API error, if applicable.
	 * @param bool $show_retry_button Show a retry button that redirects the customer back to the checkout page.
	 *
	 * @return string
	 */
	protected function showReturnPage ($title, $body, $api_error = NULL, $show_retry_button = TRUE)
	{
		$this->load->language("payment/mollie");

		$data['message_title'] = $title;
		$data['message_text']  = $body;

		if ($api_error)
		{
			$data['mollie_error'] = $api_error;
		}

		if ($show_retry_button)
		{
			$data['checkout_url']  = $this->url->link("checkout/checkout", "", "SSL");
			$data['button_retry']  = $this->language->get("button_retry");
		}

		$this->document->setTitle($this->language->get("ideal_title"));

		$this->setBreadcrumbs($data);

		return $this->renderTemplate("mollie_return", $data, array(
			"column_left",
			"column_right",
			"content_top",
			"content_bottom",
			"footer",
			"header",
		));
	}

	/**
	 * We check for and remove the admin url in the webhook link.
	 *
	 * @return string|null
	 */
	public function getWebhookUrl ()
	{
		$system_webhook_url = $this->url->link("payment/mollie_" . static::MODULE_NAME . "/webhook", "", "SSL");

		if (strpos($system_webhook_url, $this->getAdminDirectory()) !== FALSE)
		{
			return str_replace($this->getAdminDirectory(), "", $system_webhook_url);
		}

		return $system_webhook_url ? $system_webhook_url : NULL;
	}

	/**
	 * Retrieves the admin directoryname from the catalog and admin urls.
	 *
	 * @return string
	 */
	protected function getAdminDirectory ()
	{
		// if no default admin URL defined in the config, use the default admin directory.
		if (!defined('HTTP_ADMIN'))
		{
			return "admin/";
		}

		return str_replace(HTTP_SERVER, "", HTTP_ADMIN);
	}

	/**
	 * Map payment status history handling for different Opencart versions.
	 *
	 * @param int|string $order_id
	 * @param int|string $order_status_id
	 * @param string     $comment
	 * @param bool       $notify
	 */
	protected function addOrderHistory ($order_id, $order_status_id, $comment = "", $notify = FALSE)
	{
		if ($this->isOpencart2())
		{
			$this->model_checkout_order->addOrderHistory($order_id, $order_status_id, $comment, $notify);
		}
		else
		{
			if ($order_status_id === $this->config->get("mollie_ideal_pending_status_id"))
			{
				$this->model_checkout_order->confirm($order_id, $order_status_id, $comment, $notify);
			}
			else
			{
				$this->model_checkout_order->update($order_id, $order_status_id, $comment, $notify);
			}
		}
	}

	/**
	 * Map template handling for different Opencart versions.
	 *
	 * @param string $template
	 * @param array  $data
	 * @param array  $common_children
	 * @param bool   $echo
	 */
	protected function renderTemplate ($template, $data, $common_children = array(), $echo = TRUE)
	{
		$template = $this->getTemplatePath($template);

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

			$this->children = array(
				// Leave this line empty so vQmod's search & replace doesn't break our code (see vqmod/xml/vqmod_custom_positions.xml).
			);

			foreach ($common_children as $child)
			{
				$this->children[] = "common/".$child;
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
	 * Fetch path to a template file. Allows themes to overwrite the template. Prefers *_2.tpl for Opencart 2 specific layouts.
	 *
	 * @param  string $template
	 *
	 * @return string
	 */
	protected function getTemplatePath ($template)
	{
		$theme_path     = $this->config->get("config_template") . "/template/payment/";
		$default_path   = "default/template/payment/";
		$possible_paths = array();

		if ($this->isOpencart2())
		{
			$possible_paths[] = $theme_path . $template . "_2.tpl";
			$possible_paths[] = $default_path . $template . "_2.tpl";
		}

		$possible_paths[] = $theme_path . $template . ".tpl";

		foreach ($possible_paths as $path)
		{
			if (file_exists(DIR_TEMPLATE . $path))
			{
				return $path;
			}
		}

		return $default_path . $template . ".tpl";
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
