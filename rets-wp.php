<?php
/*
Plugin Name: RETS WP
Description: RETS protocol integration for WordPress
Author: nerdspice
Version: 0.1
*/

if (!defined('ABSPATH')) die('-1');

class RetsWP {
  private static $_instance;
  
  private $config;
  private $cache;
  
  private $rets_config;
  private $rets;
  private $connected = false;
  private $lastResultCount;
  
	private function __clone() { }
	private function __wakeup() { }
  
  public function __construct() {
    $this_dir = dirname(__FILE__);
    date_default_timezone_set('America/New_York');
    require_once($this_dir.'/phrets/vendor/autoload.php');
    
    $this->config = (object)$this->getOption('rets-config');
    $this->cache  = (array)($this->getOption('rets-cache', false) ?: array());
  }
  
	public static function getInstance() {
		if (!(static::$_instance instanceof static)) {
			static::$_instance = new static();
		}

		return static::$_instance;
	}
  
  public function stashInCache($name, $data) {
    if(empty($this->cache)) $this->cache = array();
    $timeout = @$this->config->cache_timeout ?: 3600;
    $this->cache[$name] = array(time()+$timeout, $data);
  }
  
  public function getCache($name) {
    $expires = @$this->cache[$name][0];
    if($expires && $expires < time()) {
      unset($this->cache[$name]);
      $this->saveOption('rets-cache', 'cache', false);
    }
    
    return @$this->cache[$name][1];
  }
  
  public function saveCache($name, $data) {
    $this->stashInCache($name, $data);
    $this->saveOption('rets-cache', 'cache', false);
  }
  
  public function getOption($name, $decode_json = true) {
    $default = $decode_json ? '{}' : null;
    $data = get_option($name, $default);
    
    if($decode_json) return @json_decode($data);
    return $data;
  }
  
  public function saveOption($name, $var, $encode_json = true) {
    $data = $this->{$var};
    if($encode_json) json_encode($data, JSON_UNESCAPED_SLASHES);
    update_option($name, $data, false);
  }
  
  public function init() {
    add_shortcode('rets', array($this, 'shortcodeRets'));
    add_filter('rets-featured', array($this, 'filterRetsFeatured'));
    add_filter('rets-mls', array($this, 'filterRetsMls'), 10, 2);
    add_filter('rets-search', array($this, 'filterRetsSearch'), 10, 2);
  }
  
  public function shortcodeRets($atts, $content) {
    $search = trim(@$atts['search']);
    $res = $this->doSearch($search);
    return json_encode($res);
  }
  
  public function filterRetsSearch($default, $search) {
    try {
      if(!is_string($search)) $search = '';
      if(!$search) return $default;
      return $this->doSearch($search);
    } catch(Exception $e) {
      error_log($e->getMessage());
      return $default;
    }
  }
  
  public function filterRetsFeatured($props) {
    try {
      return $this->getFeaturedProperties();
    } catch(Exception $e) {
      error_log($e->getMessage());
      return array();
    }
  }
  
  public function filterRetsMls($default, $mlsid) {
    try {
      return is_array($mlsid) ? $this->getProperties($mlsid) : $this->getProperty($mlsid);
    } catch(Exception $e) {
      error_log($e->getMessage());
      return $default;
    }
  }
  
  public function connect() {
    try {
      $this->setupConfig();
      $this->loginToServer();
    } catch(Exception $e) {
      error_log($e->getMessage());
      $this->connected = false;
    }
    
    $this->connected = true;
  }
  
  
  public function setupConfig() {
    $config = $this->config;
    $this->rets_config = new \PHRETS\Configuration;
    $this->rets_config
         ->setLoginUrl($config->login_url)
         ->setUsername($config->username)
         ->setPassword($config->password)
         ->setRetsVersion($config->rets_version)
         ;
    return $this->rets_config;
  }
  
  public function loginToServer() {
    $this->rets = new \PHRETS\Session($this->rets_config);
    return $this->rets->Login();
  }
  
  public function doConnect() {
    if(!$this->connected) $this->connect();
    return $this->connected && $this->rets;
  }
  
  public function getAndSavePhotos(&$results) {
    if(!is_array($results)) return;
    
    $media_types = array('Photo', 'LargePhoto', 'HighRes', 'Supplement');
    $photo_mids = array();
    $props_mid_map = array();
    
    foreach($results as &$r) {
      $r = (object)$r;
      $mid = $r->Matrix_Unique_ID;
      $photo_mids[] = $mid;
      $props_mid_map[$mid] = $r;
      $r->_photos = array();
    }
    
    foreach($media_types as $mtype) {
      $photos = $this->getPhotos($photo_mids, $mtype);
      
      foreach($photos as $pmid=>$links) {
        if(!isset($props_mid_map[$pmid])) continue;
        
        $prop = $props_mid_map[$pmid];
        $prop->_photos[$mtype] = $links;
      }
    }
  }
  
  public function getPhotos($mids, $size = 'Photo') {
    if(!$this->doConnect()) return array();
    
    $config = $this->config;
    $ret = array();
    
    $results = $this->rets->GetObject('Property', $size, $mids, '*', 1)->toArray();
    
    foreach($results as $photo) {
      if($photo->isError()) continue;
      $cid = $photo->getContentId();
      if(!isset($ret[$cid])) $ret[$cid] = array();
      $ret[$cid][] = $photo->getLocation();
    }
    
    return $ret;
  }
  
  public function retsFetch($resource, $class, $query, $limit='NONE', $offset=1, $count=0, $select='', $format='COMPACT-DECODED') {
    $results = $this->rets->Search($resource, $class, $query, array(
      'Count'=>$count,
      'Offset'=>$offset,
      'Limit'=>$limit,
      'Format'=>$format,
      'Select'=>$select
    ));
    
    $this->lastResultCount = $results->getTotalResultsCount();
    $results = $results->toArray();
    $this->getAndSavePhotos($results);
    
    return $results;
  }
  
  public function doSearch($search) {
    $search = is_string($search) ? trim($search) : '';
    $search = preg_replace('#\*#', '', $search);
    
    if(!$search || strlen($search)<2 || !$this->doConnect()) return array();
    
    $config = $this->config;
    $query_prefix = trim(@$config->search_query_prefix);
    if($query_prefix) $query_prefix .= ',';
    
    $query = "{$query_prefix}(
(MLSNumber={$search})|
(Remarks=*{$search}*)|
(InternetRemarks=*{$search}*)|
(LegalDescription=*{$search}*)
)";
    
    $query = preg_replace('#\r\n#', '', $query);
    $fields = trim(@$config->search_query_fields);
    
    $results =  $this->retsFetch(
      'Property',
      'Listing',
      $query,
      20,
      1, 0,
      $fields
    );
    
    //var_dump($this->lastResultCount);exit;
    
    return $results;
  }
  
  public function getPropertiesFromMLS($mlsids, $clean = true) {
    if($clean) $mlsids = $this->cleanMLSIDs($mlsids);
    if(!$mlsids || !$this->doConnect()) return array();
    
    $cnt = count($mlsids);
    $mlsids = implode(',', $mlsids);
    if(!$mlsids) return array();
    
    return $this->retsFetch(
      'Property', 
      'Listing', 
      '(MLSNumber='.$mlsids.')',
      $cnt+20
    );
  }
  
  public function cleanMLSIDs($mlsids) {
    if(!is_array($mlsids)) $mlsids = array($mlsids);
    
    foreach($mlsids as $idx=>$id) {
      $id = is_string($id) ? trim($id) : '';
      $mlsids[$idx] = preg_replace('#[^a-zA-Z0-9_-]#', '', $id);
    }
    
    $mlsids = array_values(array_unique(array_filter($mlsids)));
    return $mlsids;
  }
  
  public function getProperty($mlsid) {
    $ret = $this->getProperties($mlsid) ?: array();
    return @$ret[0] ?: new stdClass;
  }
  
  public function getProperties($mlsids) {
    $mlsids = $this->cleanMLSIDs($mlsids);
    if(!$mlsids) return array();
    
    $results = array();
    $fetch_mlsids = array();
    $props = array();
    
    foreach($mlsids as $mlsid) {
      $cache = $this->getCache('mls-'.$mlsid);
      if(!$cache) {
        $fetch_mlsids[] = $mlsid;
        continue;
      }
      
      $props[] = $cache;
    }
    
    if($fetch_mlsids) {
      $results = $this->getPropertiesFromMLS($fetch_mlsids, false) ?: array();
      
      foreach($results as $result) {
        $mlsid = trim(@$result->MLSNumber);
        if(!$mlsid) continue;
        $this->stashInCache('mls-'.$mlsid, $result);
      }
      
      $this->saveOption('rets-cache', 'cache', false);
    }
    
    $results = array_merge($props, $results);
    return $results;
  }
  
  public function getFeaturedProperties() {
    $cache = $this->getCache('featured_listings');
    if(is_array($cache)) return $cache;
    
    if(!$this->doConnect()) return array();
    
    $config = $this->config;
    
    /*
    $system = $rets->GetSystemMetadata();
    var_dump($system);

    $resources = $system->getResources();
    $classes = $resources->first()->getClasses();
    var_dump($classes);

    $classes = $rets->GetClassesMetadata('Property');
    var_dump($classes->first());

    $objects = $rets->GetObject('Property', 'Photo', '00-1669', '*', 1);
    var_dump($objects);

    $fields = $rets->GetTableMetadata('Property', 'A');
    var_dump($fields[0]);
    */
    
    //$results = $rets->Search('Media', 'Media', 'matrix_unique_id=26931966', ['Count'=>0, 'Limit'=>3, 'Select'=>'matrix_unique_id,MediaPath']);
    //$results = $rets->Search('PropertySubTable', 'Room', '*', ['Count'=>0, 'Limit'=>3, 'Select'=>'RoomDimension,RoomType']);
    //$results = $this->rets->GetObject('Property', 'Photo', '26931807', '*', 1);
    //var_dump($results);
    //var_dump($this->rets->getLastRequestURL());
    //var_dump($this->rets->getClient());
    
    /*foreach($results as $r) {
      $r['_photo'] = $this->rets->GetObject('Property', 'HighRes', $r['Matrix_Unique_ID'], '*', 1)->toArray();
    }*/
    
    $fields = trim(@$config->featured_query_fields);
    
    $results = $this->retsFetch(
      'Property',
      'Listing',
      $config->featured_query,
      $config->featured_count,
      1, 0,
      $fields
    );

    $this->saveCache('featured_listings', $results);
    
    return $results;
  }
}

RetsWP::getInstance()->init();
