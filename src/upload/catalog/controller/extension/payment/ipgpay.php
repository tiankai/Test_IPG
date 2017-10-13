<?php
class ControllerExtensionPaymentIpgpay extends Controller {

	public function index() {
		$data['button_confirm'] = $this->language->get('button_confirm');

		$this->load->model('checkout/order');

		$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

		$config = $this->getConfig();
		$out_trade_no = trim($order_info['order_id']);
		$subject = trim($this->config->get('config_name'));
		$total_amount = trim($this->currency->format($order_info['total'], 'USD', '', false));
		$body = '';//trim($_POST['WIDbody']);

		$payRequestBuilder = array(
			'body'         => $body,
			'subject'      => $subject,
			'total_amount' => $total_amount,
			'out_trade_no' => $out_trade_no,
			'product_code' => 'FAST_INSTANT_TRADE_PAY'
		);

		$this->load->model('extension/payment/ipgpay');

		$response = $this->model_extension_payment_ipgpay->pagePay($payRequestBuilder, $config);
		$data['action'] = $config['gateway_url'] . "?charset=" . $this->model_extension_payment_ipgpay->getPostCharset();
		$data['form_params'] = $response;

		return $this->load->view('extension/payment/ipgpay', $data);
	}

	public function callback() {
		$this->log->write('ipgpay pay notify:');
		$arr = $_POST;
		$config = $this->getConfig();
		$this->load->model('extension/payment/ipgpay');
		$this->log->write('POST' . var_export($_POST,true));
		$result = $this->model_extension_payment_ipgpay->check($arr, $config);

		if($result) {//check successed
			$this->log->write('Ipgpay check successed');
			$order_id = $_POST['out_trade_no'];
			if($_POST['trade_status'] == 'TRADE_FINISHED') {
			}
			else if ($_POST['trade_status'] == 'TRADE_SUCCESS') {
				$this->load->model('checkout/order');
				$this->model_checkout_order->addOrderHistory($order_id, $this->config->get('payment_ipgpay_order_status_id'));
			}
			echo "success";	//Do not modified or deleted
		}else {
			$this->log->write('Ipgpay check failed');
			//chedk failed
			echo "fail";

		}
	}

	function getConfig() {

		$config = array (
			'app_id'               => $this->config->get('payment_ipgpay_app_id'),
			'merchant_private_key' => $this->config->get('payment_ipgpay_merchant_private_key'),
			'notify_url'           => HTTPS_SERVER . "payment_callback/ipgpay",
			'return_url'           => $this->url->link('checkout/success'),
			'charset'              => "UTF-8",
			'sign_type'            => "RSA2",
			'gateway_url'          => $this->config->get('payment_ipgpay_test') == "sandbox" ? "https://openapi.ipgpaydev.com/gateway.do" : "https://openapi.ipgpay.com/gateway.do",
			'ipgpay_public_key'    => $this->config->get('payment_ipgpay_ipgpay_public_key'),
		);

		return $config;
	}
}