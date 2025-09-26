<?
	namespace Peczuh\Tharstern;
	
	use ThriveData\ThrivePHP\ContextException;
	use ThriveData\ThrivePHP\CURL;
	use ThriveData\ThrivePHP\Log;

	class API
	{
		private $url;
		private $user;
		private $pwd;
		private $appid;
		private $auth;
		private $headers;
		
		public function __construct($host, $user, $pwd, $appid, ?int $port=80)
		{
			Log::debug("Tharstern::__construct()");
			
			$this->url = sprintf('http://%s:%s/TharsternAPI/api', $host, $port);
			$this->user = $user;
			$this->pwd = $pwd;
			$this->appid = $appid;
			
			$this->authenticate();
			
			$this->headers[] = 'Authorization: Basic '.$this->auth;
			$this->headers[] = 'Content-Type: application/json';
			$this->headers[] = 'Accept: application/json';
		}
		
		public function authenticate()
		{
			Log::debug("Tharstern::authenticate()");
			
			$url = sprintf('%s/authentication/GenerateAPIToken?%s',
				$this->url,
				http_build_query([
					'email' => $this->user,
					'password' => $this->pwd,
					'applicationId' => $this->appid
				])
			);
			
			$c = new CURL($url);
			
			if ($c->json->Status->Success != true):
				throw new Exception('api error: '.$c->result);
			endif;
			
			if ($c->json->Details->Token == '00000000-0000-0000-0000-000000000000'):
				throw new AuthenticationFailed('got invalid token (check user, password, and application ID)',
					context: ['request' => $c->result, 'info' => $c->info]);
			endif;
			
			$token = $c->json->Details->Token;
			$this->auth = base64_encode($token);
			Log::debug(sprintf("Tharstern::authenticate() | AUTHKEY=%s", $this->auth));
		}
		
		public function product($code)
		{
			$url = sprintf('%s/products?productCode=%s', $this->url, $code);
			
			$c = new CURL($url, headers: $this->headers);
			
			if ($c->json->Status->Success != true):
				throw new Exception('api error: '.print_r($c->result, 1));
			endif;
			
			if ($c->json->Details->TotalItemCount < 1):
				throw new ProductNotFound('product not found');
			endif;
			
			return $c->json->Details->Items[0];
		}
		
		public function producttype($id)
		{
			$url = sprintf('%s/producttypes?id=%s', $this->url, $id);
			
			$c = new CURL($url, headers: $this->headers);
			
			if ($c->json->Status->Success != true):
				throw new Exception('api error: '.print_r($c->result, 1));
			endif;
			
			if ($c->json->Details->TotalItemCount < 1):
				throw new ProductTypeNotFound('product type not found', context: ['result' => $c->result]);
			endif;
			
			return $c->json->Details->Items[0];
		}
		
		public function estimate($id=[], $num=[])
		{
			Log::debug('Tharstern::estimate()');
			
			$url = sprintf("%s/estimates", $this->url);
			
			$c = new CURL($url, method: CURL::GET, headers: $this->headers, query: ['id' => $id, 'estimateRef' => $num]);
			
			if ($c->json->Status->Success != true):
				throw new Exception('api error: '.print_f($c->result, 1));
			endif;
			
			return $c->json->Details->Items;
		}

		/**
		 * Create a new estimate from an existing one, changing the quantity
		 *
		 * @param int $id The ID of the existing estimate
		 * @param int $quantity The new quantity for the new estimate
		 * @return void
		 * @throws InvalidRequest
		 */
		public function newEstimateFromExisting($id, $quantity)
		{
			Log::debug('Tharstern::newEstimateFromExisting()');

			$url = sprintf("%s/estimates/newestimatefromexisting?id=%s&quantity=%s", $this->url, $id, $quantity);

			try {
				$c = new CURL($url, method: CURL::GET, headers: $this->headers);
			} catch (\ThriveData\ThrivePHP\BadRequest $e) {
				throw new InvalidRequest($e->getMessage(), previous: $e, context: $e->getContext());
			}
		}
		
		public function estrequest($json)
		{
			Log::debug("Tharstern::estrequest()");
			
			$url = sprintf('%s/estrequest', $this->url);
			
			try {
				$c = new CURL($url, method: CURL::POST, headers: $this->headers, data: $json);
			} catch (\ThriveData\ThrivePHP\BadRequest $e) {
				$cxt = $e->getContext()['json'];
				$msg = '';
				
				if (isset($cxt->Details->Result->Problems)):
					$msg .= join("\n", $cxt->Details->Result->Problems)."\n";
				endif;
				
				if (isset($cxt->ModelState->{'estRequestProduct.Quantity'})):
					$msg .= $cxt->ModelState->{'estRequestProduct.Quantity'};
				endif;
				
				throw new InvalidRequest($msg, previous: $e, context: $e->getContext());
			}
			
			if ($c->json?->Status?->Success != true):
				$msg = $c->json->Details?->Result?->Problems[0] ?? $c->result;
				throw new EstimateFailed($msg, context: ['request' => $json, 'response' => $c]);
			endif;
			
			return $c->json->Details;
		}
		
		public function salesorder($id=[], $num=[])
		{
			Log::debug("Tharstern::salesorderGet()");
			
			$url = sprintf("%s/orders", $this->url);
			
			$c = new CURL($url, method: CURL::GET, headers: $this->headers, query: ['orderIDs' => $id, 'orderNOs' => $num]);
			
			if ($c->json->Status->Success != true):
				throw new Exception('api error: '.print_r($c->result, 1));
			endif;
			
			return $c->json->Details->Items;
		}
		
		public function salesorder_post($json)
		{
			Log::debug("Tharstern::salesorderSubmit()");
			
			$url = sprintf('%s/orders/submit', $this->url);
			
			try {
				$c = new CURL($url, method: CURL::POST, headers: $this->headers, data: $json);
			} catch (\ThriveData\ThrivePHP\BadRequest $e) {
				throw new InvalidRequest($e->getMessage(), previous: $e, context: $e->getContext());
			}
				
			if ($c->json->Status->Success != true):
				throw new Exception('api error: '.print_r($c->result, 1));
			endif;
			
			if ($c->json->Details->Orders[0]->ID == '0'):
				throw new SalesOrderFailed($c->json->Details->Orders[0]->StatusDetails, context: [$c]);
			endif;
				
			return $c->json->Details->Orders;
		}
		
		public function job($id=[], $num=[])
		{
			Log::debug("Tharstern::job()");
			
			$url = sprintf('%s/jobs', $this->url);
			
			try {
				$c = new CURL($url, method: CURL::GET, headers: $this->headers, query: ['id' => $id, 'jobNo' => $num]);
			} catch (\ThriveData\ThrivePHP\BadRequest $e) {
				throw new InvalidRequest($e->getMessage(), previous: $e, context: $e->getContext());
			}
			
			if ($c->json->Status->Success != true):
				throw new Exception('api error: '.print_r($c->result, 1));
			endif;
			
			return $c->json->Details->Items;
		}

		public function updateJob($json)
		{
			Log::debug("Tharstern::updateJob()");

			$url = sprintf('%s/job/update', $this->url);

			try {
				$c = new CURL($url, method: CURL::POST, headers: $this->headers, data: $json);
			} catch (\ThriveData\ThrivePHP\BadRequest $e) {
				throw new InvalidRequest($e->getMessage(), previous: $e, context: $e->getContext());
			}

			if ($c->json->Status->Success != true):
				throw new Exception('api error: '.print_r($c->result, 1));
			endif;

			return $c->json->Details->UpdatedJob;
		}
		
		public function salesorderasset($json)
		{
			Log::debug('Tharstern::salesorderasset()');
			
			$url = sprintf('%s/orders/submitorderasset', $this->url);
			
			try {
				$c = new CURL($url, method: CURL::POST, headers: $this->headers, data: $json);
			} catch (\ThriveData\ThrivePHP\BadRequest $e) {
				throw new InvalidRequest($e->getMessage(), previous: $e, context: $e->getContext());
			}
			
			return $c;
		}
		
		public function salesorderattach($salesOrderId, $sourceFile, $targetName)
		{
			Log::debug('Tharstern::salesorderattach()');
			
			$encoded = base64_encode(file_get_contents($sourceFile));
			
			$request = [
				'OrderId' => $salesOrderId,
				'Filename' => $targetName,
				'Content_Base64' => $encoded,
			];
			
			$json = json_encode($request);
			
			return $response = $this->salesorderasset($json);
		}
		
		public function jdf($jobId)
		{
			Log::debug('Tharstern::jdf()');
			$url = sprintf('%s/jdf/submitjobs', $this->url);
			$request = [
				'Items' => [
					['JobId' => $jobId]
				]
			];
			
			$json = json_encode($request);
			$c = new CURL($url, method: CURL::POST, headers: $this->headers, data: $json);
			
			return $c->json;
		}
	}
	
	class Exception extends ContextException {};
	class InvalidRequest extends Exception {};
	class AuthenticationFailed extends Exception {};
	class ProductNotFound extends Exception {};
	class ProductTypeNotFound extends Exception {};
	class ProductTypePartNotFound extends Exception {};
	class EstimateFailed extends Exception {};
	class SalesOrderFailed extends Exception {};
	
?>