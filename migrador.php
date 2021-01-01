<?php

	if ( !function_exists('posix_geteuid') ) exit("Certifique-se de compilar o PHP com suporte às funções POSIX!\n");
	else if ( !function_exists('curl_init') ) exit("É preciso que você compile o PHP com a extensão cURL!\n");
	
	if ( !ini_get('allow_url_fopen') ) {
		$args = implode(' ', $argv);
		exit("Rode o comando php com o parâmetro allow_url_fopen ativado: /usr/local/bin/php -d allow_url_fopen=1 $args\n");
	}
	elseif ( stripos(ini_get('disable_functions'), 'shell_exec') !== false ) {
		$args = implode(' ', $argv);
		exit("Rode o comando php com o parâmetro disable_functions em branco: /usr/local/bin/php -d disable_functions= $args\n");
	}
	
	$uid = posix_geteuid();
	$running_user_info = posix_getpwuid($uid);
	define("ISCRONJOB", !isset($_SERVER['TERM']));
	define("STORAGE", ($uid === 0 ? '/home' : $running_user_info['dir']));

	if ( ISCRONJOB ) {
		$session_save_path = '/root/migrador';
		$search = glob($session_save_path . '/*');
		if ( $search ) {
			foreach ( $search as $found ) {
				if ( file_exists($found . '/data') ) {
					$data = file_get_contents($found . '/data');
					$status = file_get_contents($found . '/status');
					if ( $status == 'pending' ) {
						$info = json_decode($data);
						if ( !empty($info['ip']) ) $argv[1] = $info['ip'];
						if ( !empty($info['user']) ) $argv[2] = $info['user'];
						if ( !empty($info['pass']) ) $argv[3] = $info['pass'];
						if ( !empty($info['accts']) ) {
							$argv[4] = null;
							if ( !empty($info['exclude']) ) $argv[4] = '-e';
							elseif ( !empty($info['include']) ) $argv[4] = '-i';
							$argv[5] = $info['accts'];
						}
						if ( isset($info['force']) ) {
							$index = count($argv);
							$argv[$index] = $info['force'];
						}
						if ( isset($info['exist']) ) {
							$index = count($argv);
							$argv[$index] = $info['exist'];
						}
					}
				}
			}
		}
	}
	
	if ( count($argv) < 3 ) {
		//var_dump($argv, ini_get('register_argc_argv'), phpversion(), php_sapi_name(), $_GET);
		print "Faltando algum parâmetro.\n";
		print "Uso: php migrador.php <IP> <REVENDEDOR> <SENHA>\n";
		exit;
	}
	
	if ( !function_exists('cli_set_process_title') ) {
		exit("Certifique-se de que o cPanel usa internamente o PHP na versão 5.6 ou superior.\n");
	}
	
	if ( filter_var($argv[1], FILTER_VALIDATE_IP) ) $host = 'https://' . $argv[1] . ':2087';
	else {
		$secure = ( stripos($argv[1], 'https') !== false || stripos($argv[1], ':2087') !== false );
		$domain = preg_replace('#(\:208(?:6|7)|https?\://)#i', null, $argv[1]);
		if ( stripos($argv[1], 'whm.') !== false ) $host = 'http' . ($secure ? 's' : null) . '://' . $domain;
		else $host = 'http' . ($secure ? 's' : null) . '://' . $domain . ':208' . ($secure ? '7' : '6');
	}

	$user = $argv[2];
	$pass = $argv[3];
	
	$title = 'cpmove - transferindo a revenda de ' . $user;
	cli_set_process_title($title) or exit("Falha grave! Não pudemos mudar o título do processo.\n");

	$move = new Backup_Cpanel_Reseller($user, $pass, $host);

	if ( in_array('-f', $argv) ) $move->force   = true;
	if ( in_array('-n', $argv) ) $move->exist   = false;
	if ( in_array('-d', $argv) ) $move->debug   = true;
	if ( in_array('-b', $argv) ) $move->restore = false;
	if ( in_array('-l', $argv) ) $move->dryrun  = true;
	
	foreach ( $argv as $key => $param ) {
		if ( $param == '-x' ) $move->proxy = $argv[$key+1];
		elseif ( $param == '-i' ) $move->include = preg_split('#,\s*#', $argv[$key+1]);
		elseif ( $param == '-e' ) $move->exclude = preg_split('#,\s*#', $argv[$key+1]);
	}

    try {
        $move->list_user_accounts();
        $move->backup_user_accounts();
    }
    catch (Exception $exception) {
        echo 'Ocorreu um erro: ',  $exception->getMessage(), "\n";
    }

class Backup_Cpanel_Reseller {        

    public $user = null;
    public $pass = null;
    public $host = null;
	
	public $proxy = null;
	public $debug = false;
	
	public $exist     = true;
	public $force     = false;
	public $dryrun    = false;
	public $restore   = true;
	
	public $include   = array();
	public $exclude   = array();
	
    private $auth = '';
    private $info = array();
    private $data = array();

    public function __construct ($user, $pass, $host) {
        $this->user = $user;
        $this->pass = $pass;
        $this->host = $host;
        $this->auth = base64_encode($user . ':' . $pass);
    }
    
    public function list_user_accounts () {

        $context = http_request_option('GET', 30, $this->auth, $this->proxy);
        $return = file_get_contents($this->host . '/json-api/listaccts?api.version=1', false, $context);

        if ( $this->debug ) var_dump($return, $http_response_header);
        if ( strpos($http_response_header[0], '200 OK') === false ) throw new Exception("Falha ao obter a lista de contas!\n");
        
        $response = json_decode($return, true);//var_dump($response);
        if ( !isset($response['data']['acct']) ) throw new Exception("Não foi possível obter a lista de contas!\n");
        $this->info = $response['data']['acct'];//var_dump($info);
		
        echo "\n";
        foreach ( $this->info as $index => $account ) {
            $used_disk_space = str_replace('none', '0M', $account["diskused"]);
            $reserved_disk_space = str_replace('unlimited', 'ilimitado', $account["disklimit"]);
            echo "Usuário: \033[44m\0", str_pad($account["user"] . "\033[0m", 32, " "), " Espaço usado: \033[44m\0", $used_disk_space, "\033[0m\n";
			if ( $this->exist == false && file_exists('/var/cpanel/users/' . $account["user"]) ) {
				//echo "Pulando a conta ", $account["user"], " porque ela já existe neste servidor.\n";
				continue;
			}
			if ( !empty($this->include) && !in_array($account["user"], $this->include) ) {
				//echo "Pulando a conta ", $account["user"], " porque não é uma das que você quer.\n";
				continue;
			}
			elseif ( in_array($account["user"], $this->exclude) ) {
				//echo "Pulando a conta ", $account["user"], " porque você decidiu não copiá-la.\n";
				continue;
			}
			array_push($this->data, $account["user"]);
        }
		
		# Incluimos o proprietário da revenda, mesmo que ele não esteja na lista de contas
        if ( !in_array($this->user, $this->data) ) {
			
			$add_this_user = true;
			if ( $this->exist == false && file_exists('/var/cpanel/users/' . $this->user) ) $add_this_user = false;
			if ( !empty($this->include) && !in_array($this->user, $this->include) ) $add_this_user = false;
			elseif ( in_array($this->user, $this->exclude) ) $add_this_user = false;
			if ( $add_this_user ) $this->data[] = $this->user;
		}
		
        return $this->info;
    }
    
    public function list_user_backups ($user) {
        
        $context = http_request_option('GET', 30, $this->auth, $this->proxy);        
        $url = $this->host . '/json-api/cpanel?cpanel_jsonapi_user=' . $user . 
        '&cpanel_jsonapi_apiversion=2&cpanel_jsonapi_module=Backups&cpanel_jsonapi_func=listfullbackups';
        $return = file_get_contents($url, false, $context);
        if ( $this->debug ) var_dump($return, $http_response_header);
		
        if ( strpos($http_response_header[0], '200 OK') === false ) throw new Exception("Falha ao obter a lista de backups!\n");
        $response = json_decode($return, true);
        if ( !isset($response['cpanelresult']['data']) ) throw new Exception("Não foi possível obter a lista de backups!\n");
        $info = $response['cpanelresult']['data'];
        if ( $this->debug ) var_dump($info);
		
        $data = array();
        foreach ($info as $backup) {
            $data[$backup["file"]] = array($backup["time"], $backup["status"]);
        }
        return $data;
    }
    
    public function backup_user_accounts () {

	    echo "\n";
		if ( $this->dryrun ) return;
		
        $host = str_replace([':2087', ':2086', 'whm.'], [':2083', '2082', 'cpanel.'], $this->host);
        $context = http_request_option('GET', 30, $this->auth, $this->proxy);
	    $proc = array();

		if ( ISCRONJOB ) file_put_contents($session_save_path . '/' . $this->user . '/status', 'running');
		
        foreach ( $this->data as $user ) {

			echo "\n";
            $old_backup_files = array_keys( $this->list_user_backups ($user) );
            if ( $this->debug ) var_dump($old_backup_files);
            sort($old_backup_files, SORT_NATURAL | SORT_FLAG_CASE);
            
            $url = $this->host . '/json-api/cpanel?cpanel_jsonapi_user=' . $user . 
            '&cpanel_jsonapi_apiversion=1&cpanel_jsonapi_module=Fileman&cpanel_jsonapi_func=fullbackup';
            $return = file_get_contents($url, false, $context);
            if ( $this->debug ) var_dump($return, $http_response_header);
			
            if ( strpos($http_response_header[0], '200 OK') === false ) {
                print "Falha ao requisitar um novo backup!\n"; continue;
            }
            
            $wait = 3600;
            print str_pad("\033[44m\0Vamos aguardar o backup da conta $user ser concluído.\033[0m", 107) . "\n";
            $fail = true;

            while ( $wait > 0 ) {
                $backup_file_list = $this->list_user_backups($user);
                $new_backup_files = array_keys($backup_file_list);
                sort($new_backup_files, SORT_NATURAL | SORT_FLAG_CASE);
                $last_backup_file = array_pop($new_backup_files);

                if ( !in_array($last_backup_file, $old_backup_files) ) {
                    if ( $backup_file_list[$last_backup_file][1] == 'complete' ) {
                        print str_pad("\033[32m\0O arquivo $last_backup_file foi criado.\033[0m", 107) . "\n";
                        $fail = false;
                        break;
                    }
                }
                else {
                    if ( $wait < 3420 ) {
                        print str_pad("\033[31m\0O backup está demorando demais para começar! Saindo ...\033[0m", 107) . "\n";
                        break;
                    }
                    //var_dump($backup_file_list);
                    print str_pad("\033[33m\0O processo de backup ainda não começou no servidor remoto!\033[0m", 107) . "\r";
                    sleep(3);
                }
                
                $stop = mt_rand(7, 17);
                print str_pad("Faremos uma nova verificação em cerca de $stop segundos ...", 107) . "\r";
				sleep($stop);
                $wait -= $stop;
            }
            
            if ( $fail ) {
				print str_pad("\033[31m\0******* O backup falhou! *******\033[0m", 107) . "\n";
				continue;
			}
          
            $auth = base64_encode($user . ':' . $this->pass);
			$setup = http_request_option('HEAD', 30, $auth, $this->proxy);
            $url = $host . '/download?file=' . $last_backup_file;
			$return = file_get_contents($url, false, $setup, NULL, NULL);
			$size = 0;
			foreach ( $http_response_header as $header ) {
				if ( preg_match('#Content-Length:\s+(\d+)#i', $header, $match) ) {
					$size = (int) $match[1];
					break;
				}				
			}
			//var_dump($http_response_header, $size); exit;

			if ( ! $this->restore ) $path = STORAGE . '/' . $last_backup_file;
			else $path = STORAGE . '/cpmove-' . $user . '.tar.gz';
			
			$url = $host . '/download?file=' . $last_backup_file;
			$exit = curl_download_file ($url, $auth, $path, $this->proxy);
			$lenght = file_exists($path) ? filesize($path) : 0;
			
			if ( $size != 0 ) {
				if ( $lenght === $size ) print str_pad("\033[44m\0O download da conta $user foi concluído com sucesso.\033[0m", 107) . "\n";
				else {
					print str_pad("\033[31m\0Erro ao baixar o arquivo $last_backup_file ($path)\033[0m", 107) . "\n";
					continue;
				}
			}
			else print str_pad("\033[33m\0Não foi possível saber se o download foi bem sucedido!\033[0m", 107) . "\n";

			if ( ! $this->restore ) continue;

			$config = '/var/cpanel/users/' . $user;
			$restore = '/usr/local/cpanel/bin/restorepkg --allow_reseller';
			if ( $this->force ) $restore .= ' --force';
			elseif ( file_exists($config) ) {
				print str_pad("\033[32m\0A restauração da conta $user foi ignorada.\033[0m", 107) . "\n";
				continue;
			}
			
			$cmd = $restore . ' ' . $path; 
			$tmp = tempnam("/tmp", "cpanel_");
			$pid = trim(shell_exec("$cmd > $tmp 2>&1 & echo $!"));
			
			if ( empty($pid) ) {
				print str_pad("\033[31m\0A restauração da conta $user não pôde ser iniciada!\033[0m", 107) . "\n";
				continue;
			}
			
			$loop = 0;
			$wait = 7200;
			$append = '';
			$timeout = false;
			while ( file_exists('/proc/' . $pid) ) {
				
				if ( $loop > $wait ) {
					print str_pad("\033[31m\0A restauração da conta $user extrapolou o limite de tempo!\033[0m", 107) . "\n";
					$timeout = true;
					break;
				}
								
				//$last = (int) substr( (string) $loop, -1 );
				//$append = str_repeat('.', $last);
				
				print str_pad("\033[33m\0Aguarde enquanto fazemos a restauração da conta $user $append\033[0m", 107) . "\r";
				
				clearstatcache();				
				sleep(1);
				
				$loop += 1;
				$append = ( $loop % 2 == 0 ) ? '..' : '....';
			}
			
			if ( $timeout ) continue;
			
			$fail = true;
			if ( file_exists($config) ) {
				$data = file_get_contents($config);
				if ( preg_match('#MTIME=(\d+)#i', $data, $match) ) {
					$past = time() - intval($match[1]);
					# Quanto tempo o cPanel demora para (sobr)escrever o arquivo de usuário após a extração do arquivo de backup?
					if ( $past < 3600 ) $fail = false;
				}
			}
			
			if ( $fail ) print str_pad("\033[31m\0Parece ter havido erro na restauração da conta $user ...\033[0m", 107) . "\n";
			else {
				print str_pad("\033[32m\0A restauração da conta $user foi finalizada com sucesso.\033[0m", 107) . "\n";
				unlink($path);
			}
        }
        
		if ( ISCRONJOB ) file_put_contents($session_save_path . '/' . $this->user . '/status', 'finished');
    }
    
}    

	function curl_download_file ($url, $auth, $path, $proxy=null) {
		$fp = fopen ($path, 'w+');
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		$header[] = 'Authorization: Basic ' . $auth . "\n\r";
		$header[] = 'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:67.0) Gecko/20100101 Firefox/67.0' . "\n\r";
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
		curl_setopt($ch, CURLOPT_FILE, $fp);
		if ($proxy) curl_setopt($ch, CURLOPT_PROXY, $proxy);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, 'curl_download_progress');
		curl_setopt($ch, CURLOPT_NOPROGRESS, false);
		curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		fclose($fp);
		return $code;
	}
	
	function curl_download_progress($resource, $download_size, $downloaded, $upload_size, $uploaded) {
		if ( $download_size > 0 ) {
			$percent = $downloaded / $download_size * 100;
			$percent = round($percent, 2) . '%';
			$got = intval($downloaded / 1048576);
			$total = intval($download_size / 1048576);
			print str_pad("Baixando agora $got de um total de $total MB ($percent)...", 107) . "\r";
		}
	}
	
    function http_request_option($method, $timeout, $auth, $proxy = null, $post = null) {
        $opts = array(
            'http' => array(
                'method'  => $method,
                'timeout' => $timeout,
                'header'  => 'Authorization: Basic ' . $auth . "\r\n" .
				'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:67.0) Gecko/20100101 Firefox/67.0'
            ),
            "ssl"=>array(
                "verify_peer" => false,
                "verify_peer_name" => false,
            )
        );
        if ( $post ) $opts['http']['content'] = $post;
		if ( $proxy ) {
			$opts['http']['proxy'] = 'tcp://' . $proxy;
			//$opts['http']['request_fulluri'] = true;
		}
        $context = stream_context_create($opts);
        return $context;
    }

	
?>
