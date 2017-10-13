<?php
class ModelExtensionPaymentIpgpay extends Model {

	private $apiMethodName="ipgpay.trade.page.pay";
	private $postCharset = "UTF-8";
	private $ipgpaySdkVersion = "ipgpay-sdk-php-20170601";
	private $apiVersion="1.0";
	private $logFileName = "ipgpay.log";
	private $gateway_url = "https://openapi.ipgpay.com/gateway.do";
	private $ipgpay_public_key;
	private $private_key;
	private $appid;
	private $notifyUrl;
	private $returnUrl;
	private $format = "json";
	private $signtype = "RSA2";

	private $apiParas = array();

	public function getMethod($address, $total) {
		$this->load->language('extension/payment/ipgpay');

		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$this->config->get('payment_ipgpay_geo_zone_id') . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");

		if ($this->config->get('payment_ipgpay_total') > 0 && $this->config->get('payment_ipgpay_total') > $total) {
			$status = false;
		} elseif (!$this->config->get('payment_ipgpay_geo_zone_id')) {
			$status = true;
		} elseif ($query->num_rows) {
			$status = true;
		} else {
			$status = false;
		}

		$method_data = array();

		if ($status) {
			$method_data = array(
				'code'       => 'ipgpay',
				'title'      => $this->language->get('text_title'),
				'terms'      => ''
			);
		}

		return $method_data;
	}

	private function setParams($ipgpay_config){
		$this->gateway_url = $ipgpay_config['gateway_url'];
		$this->appid = $ipgpay_config['app_id'];
		$this->private_key = $ipgpay_config['merchant_private_key'];
		$this->ipgpay_public_key = $ipgpay_config['ipgpay_public_key'];
		$this->postCharset = $ipgpay_config['charset'];
		$this->signtype = $ipgpay_config['sign_type'];
		$this->notifyUrl = $ipgpay_config['notify_url'];
		$this->returnUrl = $ipgpay_config['return_url'];

		if (empty($this->appid)||trim($this->appid)=="") {
			throw new Exception("appid should not be NULL!");
		}
		if (empty($this->private_key)||trim($this->private_key)=="") {
			throw new Exception("private_key should not be NULL!");
		}
		if (empty($this->ipgpay_public_key)||trim($this->ipgpay_public_key)=="") {
			throw new Exception("ipgpay_public_key should not be NULL!");
		}
		if (empty($this->postCharset)||trim($this->postCharset)=="") {
			throw new Exception("charset should not be NULL!");
		}
		if (empty($this->gateway_url)||trim($this->gateway_url)=="") {
			throw new Exception("gateway_url should not be NULL!");
		}
	}

	function pagePay($builder, $config) {
		$this->setParams($config);
		$biz_content=null;
		if(!empty($builder)){
			$biz_content = json_encode($builder,JSON_UNESCAPED_UNICODE);
		}

		$log = new Log($this->logFileName);
		$log->write($biz_content);

		$this->apiParas["biz_content"] = $biz_content;

		$response = $this->pageExecute($this, "post");
		$log = new Log($this->logFileName);
		$log->write("response: ".var_export($response,true));

		return $response;
	}

	function check($arr, $config){
		$this->setParams($config);

		$result = $this->rsaCheckV1($arr, $this->signtype);

		return $result;
	}

	public function pageExecute($request, $httpmethod = "POST") {
		$iv=$this->apiVersion;

		$sysParams["app_id"] = $this->appid;
		$sysParams["version"] = $iv;
		$sysParams["format"] = $this->format;
		$sysParams["sign_type"] = $this->signtype;
		$sysParams["method"] = $this->apiMethodName;
		$sysParams["timestamp"] = date("Y-m-d H:i:s");
		$sysParams["alipay_sdk"] = $this->alipaySdkVersion;
		$sysParams["notify_url"] = $this->notifyUrl;
		$sysParams["return_url"] = $this->returnUrl;
		$sysParams["charset"] = $this->postCharset;
		$sysParams["gateway_url"] = $this->gateway_url;

		$apiParams = $this->apiParas;

		$totalParams = array_merge($apiParams, $sysParams);

		$totalParams["sign"] = $this->generateSign($totalParams, $this->signtype);

		if ("GET" == strtoupper($httpmethod)) {
			$preString=$this->getSignContentUrlencode($totalParams);
			$requestUrl = $this->gateway_url."?".$preString;

			return $requestUrl;
		} else {
			foreach ($totalParams as $key => $value) {
				if (false === $this->checkEmpty($value)) {
					$value = str_replace("\"", "&quot;", $value);
					$totalParams[$key] = $value;
				} else {
					unset($totalParams[$key]);
				}
			}
			return $totalParams;
		}
	}

	protected function checkEmpty($value) {
		if (!isset($value))
			return true;
		if ($value === null)
			return true;
		if (trim($value) === "")
			return true;

		return false;
	}

	public function rsaCheckV1($params, $signType='RSA') {
		$sign = $params['sign'];
		$params['sign_type'] = null;
		$params['sign'] = null;
		return $this->verify($this->getSignContent($params), $sign, $signType);
	}

	function verify($data, $sign, $signType = 'RSA') {
		$pubKey= $this->ipgpay_public_key;
		$res = "-----BEGIN PUBLIC KEY-----\n" .
			wordwrap($pubKey, 64, "\n", true) .
			"\n-----END PUBLIC KEY-----";

		(trim($pubKey)) or die('Ipgpay public key error!');

		if ("RSA2" == $signType) {
			$result = (bool)openssl_verify($data, base64_decode($sign), $res, OPENSSL_ALGO_SHA256);
		} else {
			$result = (bool)openssl_verify($data, base64_decode($sign), $res);
		}

		return $result;
	}

	public function getSignContent($params) {
		ksort($params);

		$stringToBeSigned = "";
		$i = 0;
		foreach ($params as $k => $v) {
			if (false === $this->checkEmpty($v) && "@" != substr($v, 0, 1)) {
				if ($i == 0) {
					$stringToBeSigned .= "$k" . "=" . "$v";
				} else {
					$stringToBeSigned .= "&" . "$k" . "=" . "$v";
				}
				$i++;
			}
		}

		unset ($k, $v);
		return $stringToBeSigned;
	}

	public function generateSign($params, $signType = "RSA") {
		return $this->sign($this->getSignContent($params), $signType);
	}

	protected function sign($data, $signType = "RSA") {
		$priKey=$this->private_key;
		$res = "-----BEGIN RSA PRIVATE KEY-----\n" .
			wordwrap($priKey, 64, "\n", true) .
			"\n-----END RSA PRIVATE KEY-----";

		if ("RSA2" == $signType) {
			openssl_sign($data, $sign, $res, OPENSSL_ALGO_SHA256);
		} else {
			openssl_sign($data, $sign, $res);
		}

		$sign = base64_encode($sign);
		return $sign;
	}

	function getPostCharset(){
		return trim($this->postCharset);
	}
}
