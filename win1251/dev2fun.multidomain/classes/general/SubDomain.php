<?
/**
 * @package subdomain
 * @author darkfriend
 * @version 0.1.23
 */
namespace Dev2fun\MultiDomain;
if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();

use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Localization\Loc;
use Dev2fun\MultiDomain\HLHelpers;

class SubDomain{
    /**
     * value subdomain
     * @var string
     */
    private $subdomain;
    /**
     * @var object
     */
    private $csite;
    /**
     * Key for $GLOBALS
     * @var string
     */
    private $globalKey='subdomain'; //SUBDOMAIN
    private $globalLangKey='lang'; //SUBDOMAIN

    private $cookieKey='subdomain';
    private $mainHost;
    /**
     * Default value for default site
     * @var string
     */
    private $defaultVal = 'ru';
    /**
     * Lang list, exclude current lang
     * @var array
     */
    private $otherLangs;

    private $cacheEnable = true;

	private $domains = [];
	private $domainToLang = [];
	private $currentDomain = [];

	private static $instance;

	/**
	 * Singleton instance.
	 * @return self
	 */
	public static function getInstance() {
		if (!self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

    /**
     * check subdomain
     * @param bool $enable
     * @param array $params cacheTime|cacheID|cacheInit (CPHPCache::InitCache)
     * @return string|false subdomain
     */
    public function check($enable=true, $params=['cacheTime'=>3600,'cacheID'=>null,'cacheInit'=>null])
	{
        global $APPLICATION;
    	if(!$enable) return;
		$config = Config::getInstance();
		$moduleId = Base::$module_id;
		$subHost = '';

		$arNames = explode('.',$_SERVER['HTTP_HOST']);
		$cntNames = count($arNames);
		switch ($cntNames) {
			case 2 :
				$host = $_SERVER['HTTP_HOST'];
				break;
			default :
				$arNames = array_reverse($arNames);
				$host = $arNames[1].'.'.$arNames[0];
				$subHost = [];
				for ($i=2;$i<$cntNames;$i++) {
					if($arNames[$i]=='www') continue;
					$subHost[] = $arNames[$i];
				}
				$subHost = array_reverse($subHost);
				$subHost = implode('.',$subHost);
				break;
		}

        $hl = HLHelpers::getInstance();
        $hlDomain = $config->get('highload_domains');
		$this->domains = $hl->getElementList($hlDomain,[
			'UF_DOMAIN' => $host,
			'UF_SUBDOMAIN' => $subHost,
//			'UF_ACTIVE' => 'Y',
		]);
		if(!$this->domains) return;

		$this->mainHost = $host;
//		$arDomainToLang = [];
		foreach ($this->domains as $key=>$domain) {
			$subDomain = '';
			if($domain['UF_SUBDOMAIN']) {
				$subDomain = $domain['UF_SUBDOMAIN'].'.';
			}
			$this->domainToLang[$subDomain.$domain['UF_DOMAIN']] = $domain['UF_LANG'];
			$this->domains[$subDomain.$domain['UF_DOMAIN']] = $domain;
			unset($this->domains[$key]);
		}
		if(!$this->isSupportHost($_SERVER['HTTP_HOST'])){
			\CHTTP::SetStatus('404 Not Found');
		}

		if($config->get('logic_subdomain')!='virtual') {
			if($this->domainToLang[$_SERVER['HTTP_HOST']]=='redirect') {
				$this->redirectDomainProcess();
			}
		} else {
			$this->subdomain = $this->getSubDomain();
		}

		if(isset($this->domains[$_SERVER['HTTP_HOST']])) {
			$this->currentDomain = $this->domains[$_SERVER['HTTP_HOST']];
			$this->subdomain = $this->domainToLang[$_SERVER['HTTP_HOST']];
		}

		$GLOBALS[$this->getGlobalKey()] = $this->subdomain;

		$this->setCookie($this->subdomain);

		if($config->get('enable_multilang')=='Y') {
			if($this->currentDomain['UF_LANG']) {
				$lang = $this->currentDomain['UF_LANG'];
			} else {
				$lang = $config->get('lang_default');
			}
			$this->setLanguage($lang);
			$GLOBALS[$this->globalLangKey] = $lang;
			$this->setCookie($lang,$this->globalLangKey);
		}

//		$cookie = $APPLICATION->get_cookie($this->cookieKey);
//		if($cookie) {
//			$cookie = mb_strtolower(htmlspecialcharsbx($cookie));
//			if($cookie==$this->domainToLang[$_SERVER['HTTP_HOST']]) {
//				$this->subdomain = $cookie;
//				return $this->subdomain;
//			}
//		}

//        if($enable && $this->subdomain = $this->getCache($params)){
//            $GLOBALS[$this->getGlobalKey()] = $this->subdomain; //SUBDOMAIN
//            return $this->subdomain;
//        }
//        $this->subdomain = $this->match();
//        if($this->subdomain) {
//            $this->setCache($this->subdomain, $params);
//        }
//        $GLOBALS[$this->getGlobalKey()] = $this->subdomain;
//        $this->setLanguage($this->subdomain);
        return $this->currentDomain;
    }

	/**
	 * Записывает куку
	 * @param string $cookieKey
	 * @return void
	 */
    public function setCookie($subdomain,$cookieKey=null) {
		global $APPLICATION;
		if(!$cookieKey) $cookieKey = $this->cookieKey;
		$APPLICATION->set_cookie($cookieKey,$subdomain,time()+3600*30*12,'/','*.'.$this->mainHost);
	}

	/**
	 * Возвращает куку
	 * @param string $cookieKey
	 * @return string
	 */
	public function getCookie($cookieKey=null) {
		global $APPLICATION;
		if(!$cookieKey) $cookieKey = $this->cookieKey;
		return $APPLICATION->get_cookie($cookieKey);
	}

	/**
	 * Возвращает домен первого уровня из ссылки
	 * @param string $url
	 * @return string
	 */
	public function getParentHost($url) {
    	$host = '';
		if(preg_match('#(\w+\.\w+)$#',$url,$match)) {
			$host = $match[1];
		}
		return $host;
	}

	public function getProtocol() {
		if(\CMain::IsHTTPS()) {
			return 'https';
		}
		return 'http';
	}

	public function setGlobal($key,$subdomain) {
		$GLOBALS[$key] = $subdomain;
	}

	/**
	 * Процесс редиректа
	 * @param bool $redirect
	 * @return string
	 */
    public function redirectDomainProcess($redirect=true) {
		global $APPLICATION;
//		$config = Config::getInstance();
		$currentPage = $APPLICATION->GetCurUri();
		$subdomain = $this->getSubDomain();
		$this->setCookie($subdomain);
//		$APPLICATION->set_cookie($this->cookieKey,$subdomain,time()+3600*30*12,'/','*.'.$this->mainHost);
		$url = $this->getProtocol().'://'.$subdomain.'.'.$this->mainHost.$currentPage;
		if($redirect) {
			LocalRedirect($url);
		}
		return $url;
	}

	/**
	 * Возвращает имя поддомена
	 * @return string
	 */
	public function getSubDomain() {
		$subdomain = $this->getCookie();
		if(!$subdomain) {
			$subdomain = $this->searchSubdomain();
		}
		$config = Config::getInstance();
		if(!in_array($subdomain,$this->domainToLang)) {
			$subdomain = $config->get('domain_default');
		}
		return $subdomain;
	}

	/**
	 * Ищет и возвращает поддомен.<br>
	 * Если режим городом, то возвращает код города.<br>
	 * Если режим стран, то возвращает код страны.
	 * @return string
	 */
    public function searchSubdomain() {
		global $APPLICATION;
		$cookie = $APPLICATION->get_cookie($this->cookieKey);
		$cookie = mb_strtolower(htmlspecialcharsbx($cookie));
		if($cookie) {
			return $cookie;
		}
		$config = Config::getInstance();
		$keyIp = $config->get('key_ip');
		if(!$keyIp) $keyIp = 'HTTP_X_REAL_IP';
		$record = (new Geo())->setIp($_SERVER[$keyIp]);
		if($config->get('type_subdomain')=='city') {
			return $record->getCityCode();
		}
		return $record->getCountryCode();
	}

	public function getCurrent() {
    	return $this->currentDomain;
	}

    /**
     * Check cache for host
     * @param array $params cacheTime|cacheID|cacheInit (CPHPCache::InitCache)
     * @return boolean
     */
    private function getCache($params){
        if(!$params['cacheID']) $params['cacheID'] = md5($_SERVER['HTTP_HOST']);
        if(!$params['cacheInit']) $params['cacheInit'] = '/';
        $oCache = new \CPHPCache();
        if($oCache->initCache($params['cacheTime'],$params['cacheID'],$params['cacheInit'])){
            return $oCache->getVars()[0];
        }
        return false;
    }
    /**
     * Save data in cache
     * @param mixed $data save data
     * @param array $params cacheTime|cacheID|cacheInit (CPHPCache::InitCache)
     */
    private function setCache($data,$params){
        $oCache = new \CPHPCache();
        if(!$params['cacheID']) $params['cacheID'] = md5($_SERVER['HTTP_HOST']);
        if(!$params['cacheInit']) $params['cacheInit'] = '/';
        $oCache->StartDataCache($params['cacheTime'],$params['cacheID'],$params['cacheInit']);
        $oCache->EndDataCache((array)$data);
    }
    /**
     * regexp subdomain
     * @return string|false
     */
    public function match(){
        $host = $_SERVER['HTTP_HOST'];
        $mainHost = $this->getMainHost();
        $host = str_replace($mainHost, '', $host);
        if(!$host) return $this->defaultVal;
        preg_match('#^(.*?)\.#', $host, $matches);
        if($matches) return $matches[1];
        return false;
    }
    /**
     * Get main server host from bitrix setting
     * @return string
     */
    public function getMainHost(){
        if($this->csite){
            $host = $this->csite['SERVER_NAME'];
        } else {
            $rsSites = \CSite::GetList($by="sort", $order="desc",['ACTIVE'=>'Y','DEF'=>'Y']);
            $host = '';
            if($arSite = $rsSites->Fetch()){
                $host = $arSite['SERVER_NAME'];
                $this->csite = $arSite;
            }
        }
        if(!$host){
            $paramUrl = '';
            if($this->csite) $paramUrl = '?LID='.$this->csite['LID'].'&tabControl_active_tab=edit1';
            \CAdminNotify::Add([
                'MESSAGE' => 'Необходимо указать "URL сервера" на странице <a href="/bitrix/admin/site_edit.php'.$paramUrl.'">настроек</a>',
            ]);
        }
        return $host;
    }
    /**
     * is exist current server host support
     * @param string $host server host
     * @param boolean $checkFile check support array from file
     * @return boolean
     */
    public function isSupportHost($host, $checkFile=false){
        if(!$host) return false;
        $cacheParams = [
            'cacheID' => md5($host.'_check'),
            'cacheTime' => (3600*24*30)
        ];
        if($this->cacheEnable && $checkStatus = $this->getCache($cacheParams)){
            return $checkStatus;
        }
        $arSupportHost = [];
		if(!$this->csite){
			$rsSites = \CSite::GetList($by="sort", $order="asc",['ACTIVE'=>'Y','DEFAULT'=>'Y']);
			if(!$this->csite = $rsSites->Fetch()){
				return false;
			}
		}
		$domains = $this->csite['DOMAINS'];
		if($domains) {
			$arSupportHost = explode(PHP_EOL, $domains);
			foreach ($arSupportHost as &$sDomain) {
				$sDomain = trim($sDomain);
			}
		}

        $checkStatus = in_array($host, $arSupportHost);
        if($this->cacheEnable && $checkStatus) {
            $this->setCache($checkStatus, $cacheParams);
        }
        return $checkStatus;
    }
    /**
     * get name subdomain
     * @return string|false
     */
    public function get(){
        return $this->subdomain;
    }
    /**
     * get name subdomain
     * @return string|false
     */
    public static function GetDomain(){
        return $GLOBALS[(new SubDomain())->getGlobalKey()];
    }
    /**
     * set name subdomain
     * @param string $subdomain
     */
    public function set($subdomain){
        $GLOBALS[$this->getGlobalKey()] = $this->subdomain = $subdomain;
    }

    /**
     * Get key for $GLOBALS
     * @return string
     */
    public function getGlobalKey(){
        return $this->globalKey;
    }

    /**
     * Set language
     * @param string $lang
     */
    public function setLanguage($lang){
        if($lang) {
        	Loc::setCurrentLang($lang);
			$application = \Bitrix\Main\Application::getInstance();
			$context = $application->getContext();
			$context->setLanguage($lang);
		}
    }

    /**
     * Get default lang
     * @return string
     */
    public function getDefaultLang(){
        return $this->defaultVal;
    }

    /**
     * Get default lang
     * @return string
     */
    public static function DefaultLang(){
        return (new SubDomain())->defaultVal;
    }

    /**
     * Get active lang list
     * @return array
     */
    public function getLangList(){
        return [
            'en',
            'ru',
        ];
    }

    /**
     * Get list other languages and exclude current lang
     * @return array
     */
//    public static function GetOtherLang(){
//        $oLang = new CSubdomain();
//        if (!$oLang->otherLangs) {
//            $listLang = $oLang->getLangList();
//            array_splice($listLang, array_search($GLOBALS['lang'], $listLang), 1);
//            $oLang->otherLangs = $listLang;
//        }
//        return $oLang->otherLangs;
//    }

    public function getProperties($hlId) {
    	$host = $_SERVER['HTTP_HOST'];
    	$arHost = explode('.',$host);
    	if(count($arHost)>2) {
			$subHost = $arHost[0];
			$host = str_replace($subHost.'.','',$host);
		} else {
			$subHost = '';
		}

		$domain = HLHelpers::getInstance()->getElementList($hlId,[
			'UF_SUBDOMAIN' => $subHost,
			'UF_DOMAIN' => $host,
		]);
    	if(empty($domain[0])) return false;
		return $domain[0];
	}
}