<?
	namespace Peczuh\Tharstern;
	
	use ThriveData\ThrivePHP\ContextException;
	use ThriveData\ThrivePHP\CURL;

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
			printf("Tharstern::__construct()\n");
			
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
			printf("Tharstern::authenticate()\n");
			
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
			printf("Tharstern::authenticate() | AUTHKEY=%s\n", $this->auth);
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
		
		public function estrequest($json)
		{
			printf("Tharstern::estrequest()\n");
			
			$url = sprintf('%s/estrequest', $this->url);
			
			try {
				$c = new CURL($url, method: CURL::POST, headers: $this->headers, data: $json);
			} catch (\ThriveData\ThrivePHP\BadRequest $e) {
				$msg = $e->getContext()['json']?->Details?->Result?->Problems[0] ?? $e->getMessage();
				throw new InvalidRequest($msg, previous: $e, context: $e->getContext());
			}
			
			if ($c->json->Status->Success != true):
				throw new EstimateFailed($c->json->Details->Result->Problems[0]);
			endif;
			
			return $c->json->Details;
		}
		
		public function salesorder($json)
		{
			printf("Tharstern::salesorder()\n");
			
			$url = sprintf('%s/orders/submit', $this->url);
			
			try {
				$c = new CURL($url, method: CURL::POST, headers: $this->headers, data: $json);
			} catch (\Thrive\CURL\BadRequest $e) {
				throw new InvalidRequest($e->getMessage(), previous: $e, context: $e->getContext());
			}
				
			if ($c->json->Status->Success != true):
				throw new Exception('api error: '.print_r($c->result, 1));
			endif;
			
			if ($c->json->Details->Orders[0]->ID == '0'):
				throw new SalesOrderFailed($c->json->Details->Orders[0]['StatusDetails'], context: $c);
			endif;
				
			return $c->json->Details->Orders;
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